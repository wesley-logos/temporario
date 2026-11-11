<?php
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/database.php';
require_once __DIR__ . '/app/authorization.php';
require_once __DIR__ . '/app/system_log.php';
require_once __DIR__ . '/app/sla.php';
require_once __DIR__ . '/app/request_integrity.php';
startApplicationSession();
requireLocalUser();

function approvalValueWinningQuote(PDO $pdo, $requestId)
{
    $query = $pdo->prepare('SELECT c.id, c.mapa_id, c.valor_total, c.moeda FROM mapas_cotacao m INNER JOIN cotacoes c ON c.mapa_id = m.id WHERE m.solicitacao_id = :request_id AND m.versao = (SELECT MAX(m2.versao) FROM mapas_cotacao m2 WHERE m2.solicitacao_id = m.solicitacao_id) AND c.vencedora = 1 ORDER BY c.id DESC LIMIT 1 FOR UPDATE');
    $query->execute(array('request_id' => $requestId));
    return $query->fetch();
}

function approvalFailure($status, $message)
{
    systemLogEvent(
        'tentativa_decisao_aprovacao',
        'solicitacao',
        isset($GLOBALS['requestId']) ? (int) $GLOBALS['requestId'] : null,
        $status >= 500 ? 'falha' : 'negado',
        array('http_status' => (int) $status, 'decisao' => isset($GLOBALS['decision']) ? $GLOBALS['decision'] : null, 'etapa_id' => isset($GLOBALS['stageId']) ? (int) $GLOBALS['stageId'] : null, 'motivo' => $message)
    );
    http_response_code($status);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html lang="pt-BR"><meta charset="utf-8"><title>Aprovação</title><body style="font:15px system-ui;max-width:640px;margin:60px auto;padding:20px"><h1>Ação não concluída</h1><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p><a href="painel.php">Voltar ao painel</a></body></html>';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validApplicationCsrfToken(isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '')) {
    approvalFailure(400, 'A solicitação expirou ou é inválida. Atualize a página e tente novamente.');
}
$requestId = filter_var(isset($_POST['solicitacao_id']) ? $_POST['solicitacao_id'] : null, FILTER_VALIDATE_INT);
$stageId = filter_var(isset($_POST['etapa_id']) ? $_POST['etapa_id'] : null, FILTER_VALIDATE_INT);
$decision = isset($_POST['decisao']) ? $_POST['decisao'] : '';
if (!$requestId || !$stageId || !in_array($decision, array('aprovado', 'reprovado'), true)) {
    approvalFailure(400, 'Ação de aprovação inválida.');
}

$justification = trim(isset($_POST['justificativa']) ? $_POST['justificativa'] : '');
if ($decision === 'reprovado' && $justification === '') {
    approvalFailure(422, 'Justificativa obrigatoria: informe o motivo da reprovacao definitiva.');
}

$pdo = databaseConnection();
try {
    $pdo->beginTransaction();
    $requestQuery = $pdo->prepare('SELECT id, fluxo_id, etapa_atual, status FROM solicitacoes WHERE id = :id FOR UPDATE');
    $requestQuery->execute(array('id' => $requestId));
    $request = $requestQuery->fetch();
    if (!$request || $request['status'] !== 'em_aprovacao') {
        throw new RuntimeException('A solicitação não está aguardando aprovação.');
    }
    $stageQuery = $pdo->prepare('SELECT id, codigo_etapa, ordem_execucao, regra_snapshot, responsavel_tipo, responsavel_id FROM etapas_solicitacao WHERE id = :stage_id AND solicitacao_id = :request_id AND status = \'pendente\' FOR UPDATE');
    $stageQuery->execute(array('stage_id' => $stageId, 'request_id' => $requestId));
    $stage = $stageQuery->fetch();
    if (!$stage || $stage['codigo_etapa'] !== $request['etapa_atual']) {
        throw new RuntimeException('Não há etapa pendente válida para esta solicitação.');
    }

    $userId = (int) $_SESSION['user_id'];
    $activeUser = $pdo->prepare('SELECT 1 FROM usuarios WHERE id = :user_id AND ativo = 1');
    $activeUser->execute(array('user_id' => $userId));
    if (!$activeUser->fetchColumn()) {
        throw new RuntimeException('A conta do usuário não está ativa.');
    }
    if (!in_array($stage['responsavel_tipo'], array('grupo', 'usuario', 'grupo_usuario'), true)) {
        throw new RuntimeException('Tipo de aprovador inválido na etapa.');
    }
    $snapshot = json_decode($stage['regra_snapshot'], true);
    $approverIds = isset($snapshot['aprovadores_ids']) && is_array($snapshot['aprovadores_ids'])
        ? array_values(array_map('intval', $snapshot['aprovadores_ids']))
        : array((int) $stage['responsavel_id']);
    if (!in_array($userId, $approverIds, true)) {
        throw new RuntimeException('Usuário não designado para esta etapa de aprovação.');
    }
    $mode = isset($snapshot['modo']) && $snapshot['modo'] === 'OU' ? 'OU' : 'E';
    if ($mode === 'OU') {
        $requiredApprovals = 1;
    } else {
        $approvedCount = $pdo->prepare('SELECT COUNT(*) FROM decisoes_aprovacao WHERE etapa_solicitacao_id = :stage_id AND decisao = \'aprovado\'');
        $approvedCount->execute(array('stage_id' => $stage['id']));
        $approvalIndex = (int) $approvedCount->fetchColumn();
        $expectedApproverId = isset($approverIds[$approvalIndex]) ? $approverIds[$approvalIndex] : null;
        if ($expectedApproverId !== $userId) {
            throw new RuntimeException('Regra E exige aprovação hierárquica; aguarde o aprovador anterior.');
        }
        $requiredApprovals = count($approverIds);
    }

    $alreadyDecided = $pdo->prepare('SELECT 1 FROM decisoes_aprovacao WHERE etapa_solicitacao_id = :stage_id AND aprovador_id = :user_id');
    $alreadyDecided->execute(array('stage_id' => $stage['id'], 'user_id' => $userId));
    if ($alreadyDecided->fetchColumn()) {
        throw new RuntimeException('Você já registrou sua decisão nesta etapa.');
    }
    if ($decision === 'aprovado') {
        $integrityIssues = requestWorkflowIntegrityIssues($pdo, $requestId, $stage['codigo_etapa']);
        if ($integrityIssues) {
            throw new WorkflowIntegrityException(implode(' ', $integrityIssues));
        }
    }
    $valueApprovalQuote = null;
    if ($stage['codigo_etapa'] === 'aprovacao_valor') {
        $valueApprovalQuote = approvalValueWinningQuote($pdo, $requestId);
        if (!$valueApprovalQuote) {
            throw new RuntimeException('Não existe uma cotação vencedora no mapa mais recente. O comprador precisa finalizar o mapa antes da aprovação do valor.');
        }
    }
    $insertDecision = $pdo->prepare('INSERT INTO decisoes_aprovacao (etapa_solicitacao_id, aprovador_id, decisao, justificativa, ip_origem) VALUES (:stage_id, :user_id, :decision, :comment, :ip)');
    $insertDecision->execute(array(
        'stage_id' => $stage['id'],
        'user_id' => $userId,
        'decision' => $decision,
        'comment' => $justification !== '' ? $justification : null,
        'ip' => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null
    ));

    if ($decision === 'reprovado') {
        $pdo->prepare('UPDATE etapas_solicitacao SET status = \'reprovada\', concluida_em = NOW() WHERE id = :id')->execute(array('id' => $stage['id']));
        slaCloseForStage($pdo, $stage['id']);
        $pdo->prepare('UPDATE solicitacoes SET status = \'encerrado_reprovado\', etapa_atual = NULL, encerrada_em = NOW() WHERE id = :id')->execute(array('id' => $requestId));
    } else {
        $countApprovals = $pdo->prepare('SELECT COUNT(DISTINCT aprovador_id) FROM decisoes_aprovacao WHERE etapa_solicitacao_id = :stage_id AND decisao = \'aprovado\'');
        $countApprovals->execute(array('stage_id' => $stage['id']));
        $completed = (int) $countApprovals->fetchColumn() >= $requiredApprovals;
        if ($completed) {
            $pdo->prepare('UPDATE etapas_solicitacao SET status = \'aprovada\', concluida_em = NOW() WHERE id = :id')->execute(array('id' => $stage['id']));
            slaCloseForStage($pdo, $stage['id']);
            if ($stage['codigo_etapa'] === 'aprovacao_valor' && $valueApprovalQuote) {
                $pdo->prepare('UPDATE mapas_cotacao SET status = \'aprovado\', aprovado_em = COALESCE(aprovado_em, NOW()), congelado_em = COALESCE(congelado_em, NOW()) WHERE id = :map_id')->execute(array('map_id' => $valueApprovalQuote['mapa_id']));
            }
            $nextQuery = $pdo->prepare('SELECT id, codigo_etapa FROM etapas_solicitacao WHERE solicitacao_id = :request_id AND status = \'aguardando\' ORDER BY ordem_execucao LIMIT 1 FOR UPDATE');
            $nextQuery->execute(array('request_id' => $requestId));
            $next = $nextQuery->fetch();
            if ($next) {
                if ($next['codigo_etapa'] === 'aprovacao_valor' && !approvalValueWinningQuote($pdo, $requestId)) {
                    throw new RuntimeException('Não existe uma cotação vencedora no mapa mais recente. O comprador precisa finalizar o mapa antes da aprovação do valor.');
                }
                $nextStartedAt = date('Y-m-d H:i:s');
                $pdo->prepare('UPDATE etapas_solicitacao SET status = \'pendente\', iniciada_em = :started_at WHERE id = :id')->execute(array('started_at' => $nextStartedAt, 'id' => $next['id']));
                slaStartForStage($pdo, $request['fluxo_id'], $next['id'], $next['codigo_etapa'], $nextStartedAt);
                $pdo->prepare('UPDATE solicitacoes SET etapa_atual = :stage WHERE id = :id')->execute(array('stage' => $next['codigo_etapa'], 'id' => $requestId));
            } else {
                $directorLimitQuery = $pdo->prepare('SELECT limite_alcada_diretor FROM fluxos_aprovacao WHERE id = :flow_id');
                $directorLimitQuery->execute(array('flow_id' => $request['fluxo_id']));
                $directorLimit = $directorLimitQuery->fetchColumn();
                $directorStageCreated = false;

                if ($stage['codigo_etapa'] !== 'diretor_alcada' && $directorLimit !== false && $directorLimit !== null) {
                    $winningQuoteQuery = $pdo->prepare('SELECT c.id, c.valor_total, c.moeda, m.id AS mapa_id, (c.valor_total > CAST(:limit_value AS DECIMAL(15,2))) AS excede_limite FROM mapas_cotacao m INNER JOIN cotacoes c ON c.mapa_id = m.id WHERE m.solicitacao_id = :request_id AND c.vencedora = 1 AND (m.aprovado_em IS NOT NULL OR m.congelado_em IS NOT NULL) ORDER BY m.versao DESC, c.id DESC LIMIT 1 FOR UPDATE');
                    $winningQuoteQuery->execute(array('limit_value' => $directorLimit, 'request_id' => $requestId));
                    $winningQuote = $winningQuoteQuery->fetch();
                    if (!$winningQuote) {
                        throw new RuntimeException('A alçada do Diretor está configurada, mas ainda não existe cotação vencedora aprovada para comparar o valor.');
                    }
                    if (strtoupper($winningQuote['moeda']) !== 'BRL') {
                        throw new RuntimeException('A cotação vencedora não está em BRL e não pode ser comparada com a alçada configurada.');
                    }

                    if ((int) $winningQuote['excede_limite'] === 1) {
                        $directorsQuery = $pdo->prepare('SELECT DISTINCT u.id FROM usuarios u INNER JOIN usuario_perfis up ON up.usuario_id = u.id INNER JOIN perfis p ON p.id = up.perfil_id WHERE u.ativo = 1 AND p.codigo = \'diretor\' AND p.ativo = 1 AND (up.valido_ate IS NULL OR up.valido_ate >= CURDATE()) ORDER BY u.id');
                        $directorsQuery->execute();
                        $directorIds = array_map('intval', $directorsQuery->fetchAll(PDO::FETCH_COLUMN));
                        if (!$directorIds) {
                            throw new RuntimeException('A cotação ultrapassa a alçada, mas não há usuários ativos com perfil Diretor para aprovar.');
                        }

                        $nextOrderQuery = $pdo->prepare('SELECT COALESCE(MAX(ordem_execucao), 0) + 1 FROM etapas_solicitacao WHERE solicitacao_id = :request_id');
                        $nextOrderQuery->execute(array('request_id' => $requestId));
                        $nextOrder = (int) $nextOrderQuery->fetchColumn();
                        $directorSnapshot = json_encode(array(
                            'codigo_etapa' => 'diretor_alcada',
                            'ordem' => $nextOrder,
                            'modo' => 'OU',
                            'responsavel_tipo' => 'grupo',
                            'responsavel_id' => null,
                            'origem' => 'alcada_valor',
                            'limite_alcada_diretor' => $directorLimit,
                            'valor_cotacao' => $winningQuote['valor_total'],
                            'cotacao_id' => (int) $winningQuote['id'],
                            'aprovadores_ids' => $directorIds
                        ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        $insertDirectorStage = $pdo->prepare('INSERT INTO etapas_solicitacao (solicitacao_id, codigo_etapa, ordem_execucao, status, regra_snapshot, responsavel_tipo, responsavel_id, iniciada_em) VALUES (:request_id, \'diretor_alcada\', :stage_order, \'pendente\', :snapshot, \'grupo\', NULL, NOW())');
                        $insertDirectorStage->execute(array('request_id' => $requestId, 'stage_order' => $nextOrder, 'snapshot' => $directorSnapshot));
                        $pdo->prepare('UPDATE solicitacoes SET etapa_atual = \'diretor_alcada\' WHERE id = :id')->execute(array('id' => $requestId));
                        systemLogEvent('incluiu_diretor_por_alcada', 'solicitacao', $requestId, 'sucesso', array('etapa_id' => (int) $pdo->lastInsertId(), 'cotacao_id' => (int) $winningQuote['id'], 'valor_cotacao' => $winningQuote['valor_total'], 'limite_alcada' => $directorLimit, 'aprovadores_ids' => $directorIds), null, $userId, $pdo);
                        $directorStageCreated = true;
                    }
                }

                if (!$directorStageCreated) {
                    $pdo->prepare('UPDATE solicitacoes SET status = \'aprovada\', etapa_atual = NULL, encerrada_em = NOW() WHERE id = :id')->execute(array('id' => $requestId));
                }
            }
        }
    }
    $audit = $pdo->prepare('INSERT INTO auditoria_eventos (ator_id, solicitacao_id, acao, entidade, entidade_id, valor_anterior, valor_novo, ip_origem) VALUES (:user_id, :request_id, :action, \'aprovacao\', :stage_id, :old_value, :new_value, :ip)');
    $audit->execute(array(
        'user_id' => $userId,
        'request_id' => $requestId,
        'action' => $decision,
        'stage_id' => (string) $stage['id'],
        'old_value' => json_encode(array('etapa_status' => 'pendente', 'solicitacao_status' => 'em_aprovacao')),
        'new_value' => json_encode(array('etapa' => $stage['codigo_etapa'], 'decisao' => $decision, 'justificativa' => $justification)),
        'ip' => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null
    ));
    systemLogEvent($decision === 'aprovado' ? 'aprovou_solicitacao' : 'reprovou_solicitacao', 'solicitacao', $requestId, 'sucesso', array('etapa_id' => (int) $stage['id'], 'codigo_etapa' => $stage['codigo_etapa']), null, $userId, $pdo);
    $pdo->commit();
    header('Location: painel.php', true, 303);
    exit;
} catch (WorkflowIntegrityException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    approvalFailure(422, $exception->getMessage());
} catch (RuntimeException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    approvalFailure(403, $exception->getMessage());
} catch (PDOException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Falha ao registrar decisão de workflow: ' . $exception->getMessage());
    systemLogEvent('erro_sistema', 'solicitacao', $requestId, 'falha', array('operacao' => 'decisao_aprovacao', 'etapa_id' => $stageId), $exception->getMessage(), isset($userId) ? $userId : null, $pdo);
    approvalFailure(500, 'Não foi possível registrar a decisão. Tente novamente.');
}
