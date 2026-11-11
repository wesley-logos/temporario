<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/database.php';

try {
    $pdo = databaseConnection();
    $statement = $pdo->exec('DELETE FROM sso_tokens_utilizados WHERE expira_em < NOW() - INTERVAL 1 DAY');
    systemLogEvent('limpou_tokens_sso', 'sso_tokens_utilizados', null, 'sucesso', array('quantidade_removida' => (int) $statement), null, null, $pdo);
    fwrite(STDOUT, 'Tokens SSO expirados removidos: ' . (int) $statement . PHP_EOL);
} catch (PDOException $exception) {
    error_log('[SSO] falha na limpeza da tabela anti-replay');
    systemLogEvent('erro_sistema', 'sso_tokens_utilizados', null, 'falha', array('operacao' => 'limpeza_sso'), $exception->getMessage(), null, isset($pdo) ? $pdo : null);
    fwrite(STDERR, 'Falha na limpeza da tabela de tokens SSO.' . PHP_EOL);
    exit(1);
}
