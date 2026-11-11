<?php
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/database.php';
require_once __DIR__ . '/app/sso.php';

$config = require __DIR__ . '/app/sso_config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    ssoErrorResponse(405, 'Link de acesso inválido. Volte ao Portal e tente novamente.', 'METODO');
}
if (!applicationRequestIsHttps()) {
    ssoErrorResponse(400, 'Este acesso só é permitido por conexão segura (HTTPS).', 'HTTPS_OBRIGATORIO');
}
startApplicationSession();

$token = isset($_GET['token']) && is_string($_GET['token']) ? $_GET['token'] : '';
if ($token === '') {
    ssoErrorResponse(401, 'Link de acesso inválido. Volte ao Portal e tente novamente.', 'TOKEN_AUSENTE');
}

$secret = applicationEnv($config['secret_env']);
if (!is_string($secret) || preg_match('/^[a-fA-F0-9]{64}$/D', $secret) !== 1 || !$config['audiences']) {
    ssoErrorResponse(400, 'SSO não configurado neste servidor.', 'CONFIGURACAO');
}

$validation = ssoValidateToken($token, $config, $secret, time());
unset($secret);
if (!$validation['ok']) {
    $tokenMessage = $validation['reason'] === 'TOKEN_EXPIRADO' ? 'Token SSO expirado. Solicite novo acesso pelo Portal.' : 'Token SSO invalido. Inicie um novo acesso pelo Portal.';
    ssoErrorResponse(401, $tokenMessage, $validation['reason']);
}
$claims = $validation['claims'];

try {
    $pdo = databaseConnection();

    // INSERT atomico: a chave primaria de jti rejeita reuso e requisicoes concorrentes.
    $replayInsert = $pdo->prepare('INSERT INTO sso_tokens_utilizados (jti, expira_em, ip) VALUES (:jti, :expires_at, :ip)');
    try {
        $replayInsert->execute(array(
            'jti' => $claims['jti'],
            'expires_at' => date('Y-m-d H:i:s', $claims['exp']),
            'ip' => ssoClientIp()
        ));
    } catch (PDOException $exception) {
        $errorInfo = $exception->errorInfo;
        if (isset($errorInfo[1]) && (int) $errorInfo[1] === 1062) {
            ssoErrorResponse(401, 'Este link de acesso já foi utilizado. Volte ao Portal e tente novamente.', 'REPLAY');
        }
        throw $exception;
    }

    $user = ssoFindActiveUser($pdo, $claims, $config);
    if (!$user) {
        ssoErrorResponse(400, 'Usuário não encontrado ou inativo neste sistema. Fale com o administrador.', 'USUARIO_NAO_ENCONTRADO');
    }

    $audit = $pdo->prepare('INSERT INTO auditoria_eventos (ator_id, acao, entidade, entidade_id, ip_origem) VALUES (:user_id, :action, :entity, :entity_id, :ip)');
    $audit->execute(array(
        'user_id' => $user['id'],
        'action' => 'Login SSO via Portal',
        'entity' => 'usuario',
        'entity_id' => (string) $user['id'],
        'ip' => ssoClientIp()
    ));
    systemLogEvent('login_sso_sucesso', 'usuario', (int) $user['id'], 'sucesso', array(
        'metodo' => 'sso',
        'origem' => 'sso'
    ), null, (int) $user['id'], $pdo);
    $pdo->prepare('UPDATE usuarios SET ultimo_acesso_em = NOW() WHERE id = :id')->execute(array('id' => $user['id']));
} catch (PDOException $exception) {
    systemLogEvent('erro_sistema', 'autenticacao', isset($user['id']) ? (int) $user['id'] : null, 'falha', array('operacao' => 'login_sso'), $exception->getMessage(), isset($user['id']) ? (int) $user['id'] : null, isset($pdo) ? $pdo : null);
    ssoErrorResponse(503, 'Não foi possível concluir o acesso agora. Tente novamente em instantes.', 'BANCO');
}

$_SESSION = array();
if (!session_regenerate_id(true)) {
    ssoErrorResponse(503, 'Não foi possível concluir o acesso agora. Tente novamente em instantes.', 'SESSAO');
}
$_SESSION['user_id'] = (int) $user['id'];
$_SESSION['user_name'] = $user['nome'];
$_SESSION['user_email'] = $user['email'];
$_SESSION['auth_method'] = 'sso';
$_SESSION['must_change_password'] = (int) $user['senha_temporaria'] === 1;
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

ssoLog('OK', 'LOGIN');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');
header('Location: ' . (!empty($_SESSION['must_change_password']) ? 'alterar_senha.php' : 'painel.php'), true, 302);
exit;
