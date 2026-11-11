<?php
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/database.php';
require_once __DIR__ . '/app/system_log.php';

startApplicationSession();

if (!empty($_SESSION['user_id'])) {
    header('Location: ' . (!empty($_SESSION['must_change_password']) ? 'alterar_senha.php' : 'painel.php'));
    exit;
}

$errorMessage = '';
$matricula = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
    if (!validApplicationCsrfToken($submittedToken)) {
        http_response_code(400);
        systemLogEvent('login_falha', 'usuario', null, 'negado', array('motivo' => 'csrf_invalido'));
        $errorMessage = 'A sessão do formulário expirou. Atualize a página e tente novamente.';
    } else {
        $matricula = isset($_POST['matricula']) && is_string($_POST['matricula']) ? trim($_POST['matricula']) : '';
        $password = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';

        if ($matricula === '' || $password === '') {
            $errorMessage = 'Informe sua matrícula e senha.';
        } else {
            try {
                $pdo = databaseConnection();
                $statement = $pdo->prepare(
                    'SELECT id, nome, email, senha_hash, senha_temporaria FROM usuarios WHERE matricula = :matricula AND ativo = 1 LIMIT 1'
                );
                $statement->execute(array('matricula' => $matricula));
                $user = $statement->fetch();
                $passwordMatches = $user && !empty($user['senha_hash']) && password_verify($password, $user['senha_hash']);
                $developmentPassword = applicationEnv('APP_DEV_LOGIN_PASSWORD', '');
                $developmentPasswordMatches = $user
                    && applicationEnv('APP_ENV', 'production') === 'development'
                    && is_string($developmentPassword)
                    && $developmentPassword !== ''
                    && is_string($password)
                    && hash_equals($developmentPassword, $password);

                if ($user && ($passwordMatches || $developmentPasswordMatches)) {
                    // Nunca substitua a senha real pelo atalho de desenvolvimento.
                    if ($passwordMatches && password_needs_rehash($user['senha_hash'], PASSWORD_DEFAULT)) {
                        $update = $pdo->prepare('UPDATE usuarios SET senha_hash = :senha_hash WHERE id = :id');
                        $update->execute(array(
                            'senha_hash' => password_hash($password, PASSWORD_DEFAULT),
                            'id' => $user['id']
                        ));
                    }

                    $pdo->prepare('UPDATE usuarios SET ultimo_acesso_em = NOW() WHERE id = :id')
                        ->execute(array('id' => $user['id']));
                    $audit = $pdo->prepare(
                        'INSERT INTO auditoria_eventos (ator_id, acao, entidade, entidade_id, ip_origem)
                         VALUES (:user_id, :action, :entity, :entity_id, :ip)'
                    );
                    $audit->execute(array(
                        'user_id' => $user['id'],
                        'action' => 'Login via Sistema',
                        'entity' => 'usuario',
                        'entity_id' => (string) $user['id'],
                        'ip' => isset($_SERVER['REMOTE_ADDR']) && filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP)
                            ? $_SERVER['REMOTE_ADDR']
                            : null
                    ));

                    session_regenerate_id(true);
                    $_SESSION['user_id'] = (int) $user['id'];
                    $_SESSION['user_name'] = $user['nome'];
                    $_SESSION['user_email'] = $user['email'];
                    $_SESSION['auth_method'] = 'local';
                    $_SESSION['must_change_password'] = (int) $user['senha_temporaria'] === 1;
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                    $authMethod = $developmentPasswordMatches && !$passwordMatches ? 'local_senha_desenvolvimento' : 'local';
                    systemLogEvent('login_sucesso', 'usuario', (int) $user['id'], 'sucesso', array(
                        'metodo' => $authMethod,
                        'origem' => 'sistema'
                    ), null, (int) $user['id'], $pdo);
                    header('Location: ' . (!empty($_SESSION['must_change_password']) ? 'alterar_senha.php' : 'painel.php'));
                    exit;
                }

                systemLogEvent('login_falha', 'usuario', null, 'negado', array('metodo' => 'local', 'motivo' => 'credencial_invalida'), null, null, $pdo);
                $errorMessage = 'Matrícula ou senha inválida.';
            } catch (PDOException $exception) {
                error_log('Falha de autenticação local: ' . $exception->getMessage());
                systemLogEvent('erro_sistema', 'autenticacao', null, 'falha', array('operacao' => 'login_local'), $exception->getMessage());
                $errorMessage = 'Não foi possível validar seu acesso agora. Tente novamente mais tarde.';
            }
        }
    }
}

$csrfToken = htmlspecialchars(applicationCsrfToken(), ENT_QUOTES, 'UTF-8');
$safeMatricula = htmlspecialchars($matricula, ENT_QUOTES, 'UTF-8');

?>
<!doctype html>
<html lang="pt-BR">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f5f6f8">
    <title>Central de Compras</title>
    <link rel="shortcut icon" href="https://centraldeservicos.logos-ma.com.br/plugins/trademark/front/picture.send.php?path=8d/66be65b142d8d.png" type="image/x-icon">
    <link rel="stylesheet" href="assets/css/login.css">
</head>

<body>
    <div class="login-container">
        <main class="login">
            <div style="text-align: center;"><img src="assets/images/logos.png" alt="Logos" style="width: 50%; margin-bottom: 1.5rem;"></div>
            <?php if ($errorMessage !== '') { ?><div class="error" role="alert"><?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?></div><?php } ?>
            <form method="post" action="index.php" autocomplete="on">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <div class="field"><label for="matricula">Matrícula</label><input id="matricula" name="matricula" value="<?php echo $safeMatricula; ?>" autocomplete="username" maxlength="40" required autofocus></div>
                <div class="field"><label for="password">Senha</label><input id="password" name="password" type="password" autocomplete="current-password" required></div>
                <button class="submit" type="submit">Entrar</button>
                <button class="disabled" type="submit" disabled>Entrar com Portal SSO</button>
                <a class="login-link" href="recuperar_senha.php">Esqueci minha senha</a>
            </form>
            <div class="foot">O acesso é gerenciado pela organização. Em caso de problemas, procure o administrador do sistema.</div>
            <div class="foot">Também é possível entrar pelo atalho da Central de Compras no Portal de Acesso Integrado.</div>
        </main>
        <p class="title title-login">Central de Compras</p>
    </div>
</body>

</html>
