<?php

/** Loads a versioned stage policy and the calendar as an immutable SLA snapshot. */
function slaBuildPolicySnapshot(PDO $pdo, $flowId, $stageCode)
{
    $query = $pdo->prepare('SELECT p.duracao, p.unidade_tempo, p.regime_contagem, p.calendario_id, p.janela_inicio, p.janela_fim, c.fuso_horario, c.dias_semana_json, c.hora_inicio AS calendario_inicio, c.hora_fim AS calendario_fim FROM politicas_sla p LEFT JOIN calendarios c ON c.id = p.calendario_id WHERE p.fluxo_id = :flow_id AND p.codigo_etapa = :stage');
    $query->execute(array('flow_id' => $flowId, 'stage' => $stageCode));
    $policy = $query->fetch();
    if (!$policy) {
        return null;
    }

    $usesWorkCalendar = $policy['unidade_tempo'] === 'horas' || $policy['regime_contagem'] === 'uteis';
    $calendarId = $policy['calendario_id'] !== null ? (int) $policy['calendario_id'] : null;
    if ($usesWorkCalendar && (!$calendarId || !$policy['fuso_horario'])) {
        throw new RuntimeException('A política de SLA exige um calendário de trabalho válido.');
    }

    $weekdays = $policy['dias_semana_json'] ? json_decode($policy['dias_semana_json'], true) : array();
    if (!is_array($weekdays)) {
        $weekdays = array();
    }
    $weekdays = array_values(array_unique(array_map('intval', $weekdays)));
    $windowStart = $policy['janela_inicio'] ? substr($policy['janela_inicio'], 0, 8) : ($policy['calendario_inicio'] ? substr($policy['calendario_inicio'], 0, 8) : null);
    $windowEnd = $policy['janela_fim'] ? substr($policy['janela_fim'], 0, 8) : ($policy['calendario_fim'] ? substr($policy['calendario_fim'], 0, 8) : null);
    $holidays = array();

    if ($calendarId) {
        $holidayQuery = $pdo->prepare('SELECT data FROM feriados WHERE calendario_id = :calendar_id ORDER BY data');
        $holidayQuery->execute(array('calendar_id' => $calendarId));
        $holidays = array_map('strval', $holidayQuery->fetchAll(PDO::FETCH_COLUMN));
    }

    $duration = (float) $policy['duracao'];
    if ($policy['unidade_tempo'] === 'horas' || $policy['regime_contagem'] === 'uteis') {
        if (!$windowStart || !$windowEnd || !$weekdays) {
            throw new RuntimeException('O calendário do SLA precisa definir dias e janela de trabalho.');
        }
        $startSeconds = slaTimeOfDaySeconds($windowStart);
        $endSeconds = slaTimeOfDaySeconds($windowEnd);
        if ($endSeconds <= $startSeconds) {
            throw new RuntimeException('A janela de trabalho do calendário é inválida.');
        }
        $workdaySeconds = $endSeconds - $startSeconds;
    } else {
        $workdaySeconds = 0;
    }

    if ($policy['unidade_tempo'] === 'horas') {
        $targetSeconds = (int) round($duration * 3600);
    } elseif ($policy['regime_contagem'] === 'uteis') {
        $targetSeconds = (int) round($duration * $workdaySeconds);
    } else {
        $targetSeconds = (int) round($duration * 86400);
    }
    if ($targetSeconds < 1) {
        throw new RuntimeException('A duração da política de SLA é inválida.');
    }

    return array(
        'duracao' => (string) $policy['duracao'],
        'unidade_tempo' => $policy['unidade_tempo'],
        'regime_contagem' => $policy['regime_contagem'],
        'calendario_id' => $calendarId,
        'fuso_horario' => $policy['fuso_horario'] ? $policy['fuso_horario'] : 'UTC',
        'dias_semana' => $weekdays,
        'feriados' => $holidays,
        'janela_inicio' => $windowStart,
        'janela_fim' => $windowEnd,
        'segundos_limite' => $targetSeconds
    );
}

function slaStartForStage(PDO $pdo, $flowId, $stageId, $stageCode, $startedAt = null)
{
    $exists = $pdo->prepare('SELECT id FROM execucoes_sla WHERE etapa_solicitacao_id = :stage_id LIMIT 1');
    $exists->execute(array('stage_id' => $stageId));
    if ($exists->fetchColumn()) {
        return null;
    }

    $snapshot = slaBuildPolicySnapshot($pdo, $flowId, $stageCode);
    if (!$snapshot) {
        return null;
    }
    $startedAt = $startedAt ? (string) $startedAt : date('Y-m-d H:i:s');
    $deadline = slaCalculateDeadline($startedAt, $snapshot);
    $insert = $pdo->prepare('INSERT INTO execucoes_sla (etapa_solicitacao_id, politica_snapshot, iniciada_em, vence_em, segundos_consumidos, resultado) VALUES (:stage_id, :snapshot, :started_at, :deadline, 0, \'em_andamento\')');
    $insert->execute(array(
        'stage_id' => $stageId,
        'snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'started_at' => $startedAt,
        'deadline' => $deadline
    ));
    return (int) $pdo->lastInsertId();
}

function slaCloseForStage(PDO $pdo, $stageId)
{
    $query = $pdo->prepare('SELECT id, politica_snapshot, iniciada_em, segundos_consumidos, pausada_em, encerrada_em FROM execucoes_sla WHERE etapa_solicitacao_id = :stage_id LIMIT 1 FOR UPDATE');
    $query->execute(array('stage_id' => $stageId));
    $execution = $query->fetch();
    if (!$execution || $execution['encerrada_em'] !== null) {
        return;
    }
    $snapshot = json_decode($execution['politica_snapshot'], true);
    if (!is_array($snapshot)) {
        return;
    }
    $endAt = $execution['pausada_em'] ? $execution['pausada_em'] : date('Y-m-d H:i:s');
    $consumed = (int) $execution['segundos_consumidos'] + slaWorkingSecondsBetween($execution['iniciada_em'], $endAt, $snapshot);
    $result = $consumed >= (int) $snapshot['segundos_limite'] ? 'estourado' : 'cumprido';
    $update = $pdo->prepare('UPDATE execucoes_sla SET segundos_consumidos = :consumed, encerrada_em = :closed_at, resultado = :result WHERE id = :id');
    $update->execute(array('consumed' => min($consumed, 4294967295), 'closed_at' => date('Y-m-d H:i:s'), 'result' => $result, 'id' => $execution['id']));
}

function slaProgressForStage(PDO $pdo, $stageId, $now = null)
{
    $query = $pdo->prepare('SELECT id, politica_snapshot, iniciada_em, vence_em, segundos_consumidos, pausada_em, encerrada_em, resultado FROM execucoes_sla WHERE etapa_solicitacao_id = :stage_id LIMIT 1');
    $query->execute(array('stage_id' => $stageId));
    $execution = $query->fetch();
    if (!$execution) {
        return null;
    }
    $snapshot = json_decode($execution['politica_snapshot'], true);
    if (!is_array($snapshot) || empty($snapshot['segundos_limite'])) {
        return null;
    }

    $consumed = (int) $execution['segundos_consumidos'];
    if ($execution['encerrada_em'] === null) {
        $endAt = $execution['pausada_em'] ? $execution['pausada_em'] : ($now ? $now : date('Y-m-d H:i:s'));
        $consumed += slaWorkingSecondsBetween($execution['iniciada_em'], $endAt, $snapshot);
    }
    $target = (int) $snapshot['segundos_limite'];
    $percent = min(100, (int) floor(($consumed * 100) / $target));
    return array(
        'percentual' => $percent,
        'estourado' => $consumed >= $target,
        'consumidos_segundos' => $consumed,
        'limite_segundos' => $target,
        'vence_em' => $execution['vence_em'],
        'resultado' => $execution['resultado'],
        'snapshot' => $snapshot
    );
}

function slaCalculateDeadline($startedAt, array $snapshot)
{
    $startUtc = slaParseStoredDateTime($startedAt);
    $target = (int) $snapshot['segundos_limite'];
    if ($snapshot['unidade_tempo'] === 'dias' && $snapshot['regime_contagem'] === 'corridos') {
        return $startUtc->modify('+' . $target . ' seconds')->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
    }
    return slaAddWorkingSeconds($startUtc, $target, $snapshot)->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
}

function slaAddWorkingSeconds(DateTimeImmutable $startUtc, $seconds, array $snapshot)
{
    $timezone = new DateTimeZone($snapshot['fuso_horario']);
    $cursorLocal = $startUtc->setTimezone($timezone);
    $remaining = (int) $seconds;
    $weekdays = array_map('intval', $snapshot['dias_semana']);
    $holidays = array_flip($snapshot['feriados']);
    $windowStart = $snapshot['janela_inicio'];
    $windowEnd = $snapshot['janela_fim'];

    for ($dayOffset = 0; $dayOffset < 36525; $dayOffset++) {
        $date = $cursorLocal->format('Y-m-d');
        if (in_array((int) $cursorLocal->format('N'), $weekdays, true) && !isset($holidays[$date])) {
            $workStart = slaLocalDateTime($date, $windowStart, $timezone);
            $workEnd = slaLocalDateTime($date, $windowEnd, $timezone);
            $cursorTimestamp = $cursorLocal->getTimestamp();
            $segmentStart = max($cursorTimestamp, $workStart->getTimestamp());
            $segmentEnd = $workEnd->getTimestamp();
            if ($segmentStart < $segmentEnd) {
                $available = $segmentEnd - $segmentStart;
                if ($remaining <= $available) {
                    return (new DateTimeImmutable('@' . ($segmentStart + $remaining)))->setTimezone(new DateTimeZone('UTC'));
                }
                $remaining -= $available;
            }
        }
        $cursorLocal = $cursorLocal->setTime(12, 0, 0)->modify('+1 day')->setTime(0, 0, 0);
    }
    throw new RuntimeException('Não foi possível calcular o vencimento do SLA no calendário configurado.');
}

function slaWorkingSecondsBetween($startValue, $endValue, array $snapshot)
{
    $startUtc = slaParseStoredDateTime($startValue);
    $endUtc = slaParseStoredDateTime($endValue);
    if ($endUtc <= $startUtc) {
        return 0;
    }
    if ($snapshot['unidade_tempo'] === 'dias' && $snapshot['regime_contagem'] === 'corridos') {
        return $endUtc->getTimestamp() - $startUtc->getTimestamp();
    }

    $timezone = new DateTimeZone($snapshot['fuso_horario']);
    $startLocal = $startUtc->setTimezone($timezone);
    $endLocal = $endUtc->setTimezone($timezone);
    $weekdays = array_map('intval', $snapshot['dias_semana']);
    $holidays = array_flip($snapshot['feriados']);
    $total = 0;
    $cursor = $startLocal->setTime(12, 0, 0)->setTime(0, 0, 0);
    $lastDate = $endLocal->format('Y-m-d');

    for ($dayOffset = 0; $dayOffset < 36525 && $cursor->format('Y-m-d') <= $lastDate; $dayOffset++) {
        $date = $cursor->format('Y-m-d');
        if (in_array((int) $cursor->format('N'), $weekdays, true) && !isset($holidays[$date])) {
            $workStart = slaLocalDateTime($date, $snapshot['janela_inicio'], $timezone)->getTimestamp();
            $workEnd = slaLocalDateTime($date, $snapshot['janela_fim'], $timezone)->getTimestamp();
            $overlapStart = max($startUtc->getTimestamp(), $workStart);
            $overlapEnd = min($endUtc->getTimestamp(), $workEnd);
            if ($overlapEnd > $overlapStart) {
                $total += $overlapEnd - $overlapStart;
            }
        }
        $cursor = $cursor->setTime(12, 0, 0)->modify('+1 day')->setTime(0, 0, 0);
    }
    return $total;
}

function slaParseStoredDateTime($value)
{
    // DATETIME values are stored as Brasília wall time and have no timezone metadata.
    $appTimezone = new DateTimeZone(date_default_timezone_get());
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', (string) $value, $appTimezone);
    if ($parsed instanceof DateTimeImmutable) {
        return $parsed->setTimezone(new DateTimeZone('UTC'));
    }
    return (new DateTimeImmutable((string) $value, $appTimezone))->setTimezone(new DateTimeZone('UTC'));
}

function slaLocalDateTime($date, $time, DateTimeZone $timezone)
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $date . ' ' . substr($time, 0, 8), $timezone);
    if (!$parsed) {
        throw new RuntimeException('Data ou horário inválido no calendário do SLA.');
    }
    return $parsed;
}

function slaTimeOfDaySeconds($time)
{
    $parts = array_map('intval', explode(':', substr($time, 0, 8)));
    return isset($parts[2]) ? ($parts[0] * 3600) + ($parts[1] * 60) + $parts[2] : ($parts[0] * 3600) + ($parts[1] * 60);
}
