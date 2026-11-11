<?php
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/database.php';
require_once __DIR__ . '/app/email.php';
require_once __DIR__ . '/app/system_log.php';
startApplicationSession();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Referrer-Policy: no-referrer');
$requestMethod = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';

function passwordRecoveryPage($message = '', $messageType = 'error')
{
    $csrfToken = htmlspecialchars(applicationCsrfToken(), ENT_QUOTES, 'UTF-8');
    $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    $requestMode = (empty($GLOBALS['resetTokenValid'])) && empty($GLOBALS['resetCompleted']);
    $safeToken = htmlspecialchars(isset($GLOBALS['resetToken']) ? $GLOBALS['resetToken'] : '', ENT_QUOTES, 'UTF-8');
    ?>
    <!doctype html>
    <html lang="pt-BR">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="referrer" content="no-referrer">
        <title>Recuperar senha · Central de Compras</title>
        <link rel="shortcut icon" href="https://centraldeservicos.logos-ma.com.br/plugins/trademark/front/picture.send.php?path=8d/66be65b142d8d.png" type="image/x-icon">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
        <link rel="stylesheet" href="assets/css/login.css">
    </head>
    <body>
        <div class="login-container">
            <main class="login">
                <div><img src="assets/images/logos.png" alt="Logos" style="width:50%;margin-bottom:1.5rem"></div>
                <h1><?php echo !empty($GLOBALS['resetCompleted']) ? 'Senha alterada' : ($requestMode ? 'Recuperar senha' : 'Criar nova senha'); ?></h1>
                <p class="intro"><?php echo !empty($GLOBALS['resetCompleted']) ? 'Use sua nova senha para entrar na Central de Compras.' : ($requestMode ? 'Informe o e-mail corporativo para receber o link de alteração de senha.' : 'Escolha uma nova senha para sua conta.'); ?></p>
                <?php if ($message !== '') { ?><div class="<?php echo $messageType === 'success' ? 'success' : 'error'; ?>" role="<?php echo $messageType === 'success' ? 'status' : 'alert'; ?>"><?php echo $safeMessage; ?></div><?php } ?>
                <?php if ($requestMode) { ?>
                    <form method="post" action="recuperar_senha.php" autocomplete="on">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                        <div class="field"><label for="email">E-mail</label><input id="email" name="email" type="email" maxlength="254" autocomplete="email" required autofocus></div>
                        <button class="submit" type="submit" name="acao" value="solicitar">Enviar link de alteração</button>
                    </form>
                <?php } elseif (!empty($GLOBALS['resetTokenValid'])) { ?>
                    <form method="post" action="recuperar_senha.php?token=<?php echo $safeToken; ?>" autocomplete="on">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                        <input type="hidden" name="token" value="<?php echo $safeToken; ?>">
                        <div class="field"><label for="password">Nova senha</label><input id="password" name="password" type="password" minlength="12" maxlength="72" autocomplete="new-password" required autofocus><small>Use pelo menos 12 caracteres.</small></div>
                        <div class="field"><label for="password_confirmation">Confirme a nova senha</label><input id="password_confirmation" name="password_confirmation" type="password" minlength="12" maxlength="72" autocomplete="new-password" required></div>
                        <button class="submit" type="submit" name="acao" value="redefinir">Alterar senha</button>
                    </form>
                <?php } ?>
                <a style="<?php echo !empty($GLOBALS['resetTokenValid']) ? 'display: none;' : ''; ?>" href="index.php" id="backToLogin" title="Voltar para login">
                    <i class="bi bi-arrow-left-square"></i>
                </a>
            </main>
            <p style="<?php echo !empty($GLOBALS['resetTokenValid']) ? 'margin-top: 25.6rem;' : ''; ?>" class="title title-reset">Central de Compras</p>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$resetToken = isset($_GET['token']) && is_string($_GET['token']) ? $_GET['token'] : '';
if ($requestMethod === 'POST' && isset($_POST['token']) && is_string($_POST['token'])) {
    $resetToken = $_POST['token'];
}
$GLOBALS['resetToken'] = $resetToken;
$resetTokenValid = preg_match('/^[a-f0-9]{64}$/D', $resetToken) === 1;
$pdo = null;

if ($resetTokenValid) {
    try {
        $pdo = databaseConnection();
        $tokenQuery = $pdo->prepare('SELECT prt.usuario_id FROM password_reset_tokens prt INNER JOIN usuarios u ON u.id = prt.usuario_id WHERE prt.token_hash = :token_hash AND prt.consumido_em IS NULL AND prt.expira_em > NOW() AND u.ativo = 1 LIMIT 1');
        $tokenQuery->execute(array('token_hash' => hash('sha256', $resetToken)));
        $resetTokenValid = (bool) $tokenQuery->fetchColumn();
    } catch (PDOException $exception) {
        error_log('Falha ao validar token de redefinição de senha: ' . $exception->getMessage());
        systemLogEvent('erro_sistema', 'autenticacao', null, 'falha', array('operacao' => 'validar_token_reset_senha'), $exception->getMessage(), null, $pdo);
        http_response_code(500);
        $GLOBALS['resetTokenValid'] = false;
        passwordRecoveryPage('Não foi possível validar o link agora. Tente novamente mais tarde.');
    }
}
$GLOBALS['resetTokenValid'] = $resetTokenValid;

if ($requestMethod === 'POST') {
    if (!validApplicationCsrfToken(isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '')) {
        http_response_code(400);
        systemLogEvent('tentativa_reset_senha', 'autenticacao', null, 'negado', array('motivo' => 'csrf_invalido'), null, null, $pdo);
        passwordRecoveryPage('A sessão do formulário expirou. Atualize a página e tente novamente.');
    }

    $action = isset($_POST['acao']) && is_string($_POST['acao']) ? $_POST['acao'] : '';
    if ($action === 'solicitar') {
        $email = isset($_POST['email']) && is_string($_POST['email']) ? trim($_POST['email']) : '';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
            passwordRecoveryPage('Informe um endereço de e-mail válido.');
        }

        try {
            $pdo = databaseConnection();
            $baseUrl = trim((string) applicationEnv('APP_BASE_URL', ''));
            $baseUrlParts = $baseUrl !== '' ? parse_url($baseUrl) : false;
            $validBaseUrl = is_array($baseUrlParts)
                && isset($baseUrlParts['scheme'], $baseUrlParts['host'])
                && in_array(strtolower($baseUrlParts['scheme']), array('https', 'http'), true)
                && !isset($baseUrlParts['user'])
                && !isset($baseUrlParts['pass'])
                && !isset($baseUrlParts['query'])
                && !isset($baseUrlParts['fragment']);
            if (!$validBaseUrl) {
                throw new RuntimeException('APP_BASE_URL não está configurada corretamente.');
            }
            $pdo->beginTransaction();
            $userQuery = $pdo->prepare('SELECT id, email FROM usuarios WHERE email = :email AND ativo = 1 LIMIT 1 FOR UPDATE');
            $userQuery->execute(array('email' => $email));
            $user = $userQuery->fetch();
            $rawToken = null;
            $tokenHash = null;
            if ($user) {
                $rateLimitQuery = $pdo->prepare('SELECT COUNT(*) FROM password_reset_tokens WHERE usuario_id = :user_id AND solicitado_em >= DATE_SUB(NOW(), INTERVAL 1 HOUR)');
                $rateLimitQuery->execute(array('user_id' => $user['id']));
                if ((int) $rateLimitQuery->fetchColumn() < 3) {
                    $rawToken = bin2hex(random_bytes(32));
                    $tokenHash = hash('sha256', $rawToken);
                    $pdo->prepare('DELETE FROM password_reset_tokens WHERE usuario_id = :user_id AND solicitado_em < DATE_SUB(NOW(), INTERVAL 30 DAY)')->execute(array('user_id' => $user['id']));
                    $pdo->prepare('UPDATE password_reset_tokens SET consumido_em = NOW() WHERE usuario_id = :user_id AND consumido_em IS NULL')->execute(array('user_id' => $user['id']));
                    $insertToken = $pdo->prepare('INSERT INTO password_reset_tokens (token_hash, usuario_id, expira_em, solicitado_em, ip_origem) VALUES (:token_hash, :user_id, DATE_ADD(NOW(), INTERVAL 1 HOUR), NOW(), :ip)');
                    $insertToken->execute(array(
                        'token_hash' => $tokenHash,
                        'user_id' => $user['id'],
                        'ip' => isset($_SERVER['REMOTE_ADDR']) && filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP) ? $_SERVER['REMOTE_ADDR'] : null
                    ));
                }
            }
            $pdo->commit();

            if ($user && $rawToken !== null && $tokenHash !== null) {
                $resetUrl = rtrim($baseUrl, '/') . '/recuperar_senha.php?token=' . rawurlencode($rawToken);
                $subject = 'Redefinicao de senha - Central de Compras';
                $body = "Recebemos uma solicitacao para alterar a senha da sua conta na Central de Compras.\r\n\r\n"
                    . "Use o link abaixo em ate uma hora para escolher uma nova senha:\r\n"
                    . $resetUrl . "\r\n\r\n"
                    . "Se voce nao solicitou essa alteracao, ignore esta mensagem. A senha atual permanecera inalterada.\r\n";
                $mailError = null;
                try {
                    sendApplicationEmail($user['email'], $subject, $body);
                } catch (Throwable $exception) {
                    $mailError = 'Falha ao enviar mensagem pelo servidor SMTP configurado.';
                    $pdo->prepare('UPDATE password_reset_tokens SET consumido_em = NOW() WHERE token_hash = :token_hash')->execute(array('token_hash' => $tokenHash));
                    error_log('Falha no envio de e-mail de redefinição de senha via SMTP (' . get_class($exception) . ').');
                    systemLogEvent('erro_sistema', 'autenticacao', (int) $user['id'], 'falha', array('operacao' => 'enviar_email_reset_senha'), $mailError, (int) $user['id'], $pdo);
                }
                if ($mailError === null) {
                    systemLogEvent('solicitou_reset_senha', 'usuario', (int) $user['id'], 'sucesso', array(), null, (int) $user['id'], $pdo);
                }
            }
            passwordRecoveryPage('Se houver uma conta ativa associada a este e-mail, enviaremos instruções para redefinir a senha.', 'success');
        } catch (Throwable $exception) {
            if ($pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Falha ao solicitar redefinição de senha: ' . $exception->getMessage());
            systemLogEvent('erro_sistema', 'autenticacao', isset($user['id']) ? (int) $user['id'] : null, 'falha', array('operacao' => 'solicitar_reset_senha'), $exception->getMessage(), isset($user['id']) ? (int) $user['id'] : null, $pdo);
            http_response_code(500);
            passwordRecoveryPage('Não foi possível processar a solicitação agora. Tente novamente mais tarde.');
        }
    }

    if ($action === 'redefinir') {
        if (!$resetTokenValid) {
            http_response_code(400);
            passwordRecoveryPage('Este link é inválido, expirou ou já foi utilizado. Solicite um novo link.');
        }
        $password = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
        $confirmation = isset($_POST['password_confirmation']) && is_string($_POST['password_confirmation']) ? $_POST['password_confirmation'] : '';
        $passwordCharacterCount = preg_match_all('/./us', $password, $passwordCharacters);
        if ($passwordCharacterCount === false || $passwordCharacterCount < 12 || strlen($password) > 72) {
            passwordRecoveryPage('A senha deve conter pelo menos 12 caracteres e ter no máximo 72 bytes.');
        }
        if (!hash_equals($password, $confirmation)) {
            passwordRecoveryPage('As senhas informadas não coincidem.');
        }

        try {
            $pdo = databaseConnection();
            $pdo->beginTransaction();
            $lockedTokenQuery = $pdo->prepare('SELECT prt.usuario_id FROM password_reset_tokens prt INNER JOIN usuarios u ON u.id = prt.usuario_id WHERE prt.token_hash = :token_hash AND prt.consumido_em IS NULL AND prt.expira_em > NOW() AND u.ativo = 1 LIMIT 1 FOR UPDATE');
            $lockedTokenQuery->execute(array('token_hash' => hash('sha256', $resetToken)));
            $userId = $lockedTokenQuery->fetchColumn();
            if (!$userId) {
                $pdo->rollBack();
                $GLOBALS['resetTokenValid'] = false;
                http_response_code(400);
                passwordRecoveryPage('Este link é inválido, expirou ou já foi utilizado. Solicite um novo link.');
            }

            $updatePassword = $pdo->prepare('UPDATE usuarios SET senha_hash = :password_hash, senha_temporaria = 0 WHERE id = :user_id AND ativo = 1');
            $updatePassword->execute(array(
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'user_id' => $userId
            ));
            if ($updatePassword->rowCount() !== 1) {
                throw new RuntimeException('A conta não pôde ser atualizada.');
            }
            $consumeTokens = $pdo->prepare('UPDATE password_reset_tokens SET consumido_em = NOW() WHERE usuario_id = :user_id AND consumido_em IS NULL');
            $consumeTokens->execute(array('user_id' => $userId));
            systemLogEvent('redefiniu_senha', 'usuario', (int) $userId, 'sucesso', array(), null, (int) $userId, $pdo);
            $pdo->commit();
            session_regenerate_id(true);
            $_SESSION = array();
            $GLOBALS['resetTokenValid'] = false;
            $GLOBALS['resetCompleted'] = true;
            passwordRecoveryPage('Sua senha foi alterada. Você já pode entrar com a nova senha.', 'success');
        } catch (Throwable $exception) {
            if ($pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Falha ao redefinir senha: ' . $exception->getMessage());
            systemLogEvent('erro_sistema', 'autenticacao', isset($userId) ? (int) $userId : null, 'falha', array('operacao' => 'redefinir_senha'), $exception->getMessage(), isset($userId) ? (int) $userId : null, $pdo);
            http_response_code(500);
            passwordRecoveryPage('Não foi possível alterar a senha agora. Tente novamente mais tarde.');
        }
    }

    http_response_code(400);
    passwordRecoveryPage('Ação inválida.');
}

if ($resetToken !== '' && !$resetTokenValid) {
    http_response_code(400);
    passwordRecoveryPage('Este link é inválido, expirou ou já foi utilizado. Solicite um novo link.');
}

passwordRecoveryPage();
