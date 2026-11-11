<?php

/**
 * Registra ações e erros técnicos em log_sistema. Não armazena senhas, tokens
 * nem conteúdo livre de formulários. O horário é gravado explicitamente em UTC.
 */
function systemLogEvent($action, $entity = null, $itemId = null, $status = 'sucesso', array $details = array(), $error = null, $userId = null, PDO $pdo = null)
{
    if (!$pdo) {
        try {
            if (!function_exists('databaseConnection')) {
                require_once __DIR__ . '/database.php';
            }
            $pdo = databaseConnection();
        } catch (Throwable $connectionError) {
            error_log('[log_sistema] Não foi possível conectar para registrar evento: ' . $connectionError->getMessage());
            return false;
        }
    }

    if ($userId === null && isset($_SESSION['user_id'])) {
        $userId = (int) $_SESSION['user_id'];
    }
    $userId = $userId ? (int) $userId : null;
    $ip = isset($_SERVER['REMOTE_ADDR']) && filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP)
        ? $_SERVER['REMOTE_ADDR']
        : null;
    $detailsJson = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($detailsJson === false) {
        $detailsJson = '{}';
    }
    if (strlen($detailsJson) > 60000) {
        $detailsJson = '{"conteudo_truncado":true}';
    }
    $errorText = $error !== null ? systemLogUtf8Cut((string) $error, 60000) : null;

    try {
        $statement = $pdo->prepare(
            'INSERT INTO log_sistema (usuario_id, item_id, entidade, acao, status, ip_usuario, ocorrido_em, detalhes, erro)
             VALUES (:user_id, :item_id, :entity, :action, :status, :ip, NOW(6), :details, :error)'
        );
        $statement->execute(array(
            'user_id' => $userId,
            'item_id' => $itemId !== null ? (int) $itemId : null,
            'entity' => $entity !== null ? substr((string) $entity, 0, 80) : null,
            'action' => substr((string) $action, 0, 120),
            'status' => in_array($status, array('sucesso', 'falha', 'negado'), true) ? $status : 'sucesso',
            'ip' => $ip,
            'details' => $detailsJson,
            'error' => $errorText
        ));
        return true;
    } catch (Throwable $loggingError) {
        // A falha do subsistema de log não deve mascarar a resposta da operação original.
        error_log('[log_sistema] Falha ao persistir evento: ' . $loggingError->getMessage());
        return false;
    }
}

function systemLogUtf8Cut($value, $maximumBytes)
{
    $value = substr((string) $value, 0, (int) $maximumBytes);
    while ($value !== '' && preg_match('//u', $value) !== 1) {
        $value = substr($value, 0, -1);
    }
    return $value;
}

function registerSystemFatalLogger()
{
    static $registered = false;
    if ($registered) {
        return;
    }
    $registered = true;

    set_error_handler(function ($severity, $message, $file, $line) {
        $loggedSeverities = array(E_WARNING, E_USER_WARNING, E_RECOVERABLE_ERROR);
        if (!(error_reporting() & $severity) || !in_array($severity, $loggedSeverities, true)) {
            return false;
        }
        systemLogEvent(
            'erro_sistema',
            'sistema',
            null,
            'falha',
            array('arquivo' => $file, 'linha' => (int) $line, 'nivel' => $severity),
            $message
        );
        return false;
    });

    register_shutdown_function(function () {
        $lastError = error_get_last();
        if (!$lastError || !in_array($lastError['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR), true)) {
            return;
        }
        systemLogEvent(
            'erro_sistema',
            'sistema',
            null,
            'falha',
            array('arquivo' => isset($lastError['file']) ? $lastError['file'] : null, 'linha' => isset($lastError['line']) ? (int) $lastError['line'] : null),
            isset($lastError['message']) ? $lastError['message'] : 'Erro fatal sem mensagem.'
        );
    });
}

registerSystemFatalLogger();
