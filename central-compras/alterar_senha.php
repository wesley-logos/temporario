<?php
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/database.php';
require_once __DIR__ . '/app/authorization.php';
require_once __DIR__ . '/app/system_log.php';

startApplicationSession();
requireLocalUser(true);

$pdo = databaseConnection();
$userId = (int) $_SESSION['user_id'];
$errorMessage = '';

try {
    $userQuery = $pdo->prepare('SELECT senha_temporaria, ativo FROM usuarios WHERE id = :user_id');
    $userQuery->execute(array('user_id' => $userId));
    $user = $userQuery->fetch();
    if (!$user || (int) $user['ativo'] !== 1) {
        $_SESSION = array();
        session_regenerate_id(true);
        header('Location: index.php');
        exit;
    }
    if ((int) $user['senha_temporaria'] !== 1) {
        $_SESSION['must_change_password'] = false;
        header('Location: painel.php');
        exit;
    }
} catch (PDOException $exception) {
    error_log('Falha ao validar troca obrigatória de senha: ' . $exception->getMessage());
    systemLogEvent('erro_sistema', 'autenticacao', $userId, 'falha', array('operacao' => 'validar_troca_senha_temporaria'), $exception->getMessage(), $userId, $pdo);
    http_response_code(500);
    exit('Não foi possível validar a troca de senha agora. Tente novamente mais tarde.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validApplicationCsrfToken(isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '')) {
        http_response_code(400);
        $errorMessage = 'A sessão do formulário expirou. Atualize a página e tente novamente.';
        systemLogEvent('troca_senha_obrigatoria', 'usuario', $userId, 'negado', array('motivo' => 'csrf_invalido'), null, $userId, $pdo);
    } else {
        $password = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
        $confirmation = isset($_POST['password_confirmation']) && is_string($_POST['password_confirmation']) ? $_POST['password_confirmation'] : '';
        $passwordLength = function_exists('mb_strlen') ? mb_strlen($password, 'UTF-8') : strlen($password);

        if ($passwordLength < 12 || strlen($password) > 72) {
            $errorMessage = 'A senha deve conter pelo menos 12 caracteres e ter no máximo 72 bytes.';
        } elseif (!hash_equals($password, $confirmation)) {
            $errorMessage = 'As senhas informadas não coincidem.';
        } else {
            try {
                $pdo->beginTransaction();
                $lockedUser = $pdo->prepare('SELECT senha_temporaria FROM usuarios WHERE id = :user_id AND ativo = 1 FOR UPDATE');
                $lockedUser->execute(array('user_id' => $userId));
                if ((int) $lockedUser->fetchColumn() !== 1) {
                    $pdo->rollBack();
                    $_SESSION['must_change_password'] = false;
                    header('Location: painel.php');
                    exit;
                }

                $updatePassword = $pdo->prepare('UPDATE usuarios SET senha_hash = :password_hash, senha_temporaria = 0 WHERE id = :user_id AND ativo = 1');
                $updatePassword->execute(array(
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    'user_id' => $userId
                ));
                if ($updatePassword->rowCount() !== 1) {
                    throw new RuntimeException('A senha temporária não pôde ser atualizada.');
                }

                systemLogEvent('alterou_senha_temporaria', 'usuario', $userId, 'sucesso', array(), null, $userId, $pdo);
                $pdo->commit();
                session_regenerate_id(true);
                $_SESSION['must_change_password'] = false;
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                header('Location: painel.php');
                exit;
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Falha ao alterar senha temporária: ' . $exception->getMessage());
                systemLogEvent('erro_sistema', 'autenticacao', $userId, 'falha', array('operacao' => 'alterar_senha_temporaria'), $exception->getMessage(), $userId, $pdo);
                http_response_code(500);
                $errorMessage = 'Não foi possível alterar a senha agora. Tente novamente mais tarde.';
            }
        }
    }
}

$csrfToken = htmlspecialchars(applicationCsrfToken(), ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="pt-BR">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f5f6f8">
    <title>Alterar senha · Central de Compras</title>
    <link rel="shortcut icon" href="https://centraldeservicos.logos-ma.com.br/plugins/trademark/front/picture.send.php?path=8d/66be65b142d8d.png" type="image/x-icon">
    <link rel="stylesheet" href="assets/css/login.css">
</head>

<body>
    <div class="login-container">
        <main class="login">
            <div style="text-align: center;"><img src="assets/images/logos.png" alt="Logos" style="width: 50%; margin-bottom: 1.5rem"></div>
            <h1>Altere sua senha</h1>
            <p class="intro">Por segurança, você precisa substituir a senha temporária antes de continuar.</p>
            <?php if ($errorMessage !== '') { ?><div class="error" role="alert"><?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?></div><?php } ?>
            <form method="post" action="alterar_senha.php" autocomplete="on">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <div class="field"><label for="password">Nova senha</label><input id="password" name="password" type="password" minlength="12" maxlength="72" autocomplete="new-password" required autofocus><small>Use pelo menos 12 caracteres.</small></div>
                <div class="field"><label for="password_confirmation">Confirme a nova senha</label><input id="password_confirmation" name="password_confirmation" type="password" minlength="12" maxlength="72" autocomplete="new-password" required></div>
                <button class="submit" type="submit">Salvar nova senha</button>
            </form>
            <a class="login-link" href="logout.php">Sair</a>
        </main>
        <p class="title title-login">Central de Compras</p>
    </div>
</body>

</html>
