<?php
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/database.php';
require_once __DIR__ . '/app/authorization.php';
require_once __DIR__ . '/app/system_log.php';
require_once __DIR__ . '/app/user_menu.php';
require_once __DIR__ . '/app/application_sidebar.php';

startApplicationSession();
requireLocalUser();

$pdo = databaseConnection();
$userId = (int) $_SESSION['user_id'];
$profiles = loadActiveProfiles($pdo, $userId);
$errorMessage = '';
$successMessage = isset($_SESSION['profile_message']) ? $_SESSION['profile_message'] : '';
unset($_SESSION['profile_message']);
$openPasswordDialog = false;

try {
    $userQuery = $pdo->prepare('SELECT matricula, nome, email, senha_hash, ativo FROM usuarios WHERE id = :user_id');
    $userQuery->execute(array('user_id' => $userId));
    $user = $userQuery->fetch();
    if (!$user || (int) $user['ativo'] !== 1) {
        $_SESSION = array();
        session_regenerate_id(true);
        header('Location: index.php');
        exit;
    }
} catch (PDOException $exception) {
    error_log('Falha ao carregar perfil do usuário: ' . $exception->getMessage());
    systemLogEvent('erro_sistema', 'usuario', $userId, 'falha', array('operacao' => 'carregar_perfil'), $exception->getMessage(), $userId, $pdo);
    http_response_code(500);
    exit('Não foi possível carregar seus dados agora. Tente novamente mais tarde.');
}

$profileNameValue = $user['nome'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';
    if (!validApplicationCsrfToken(isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '')) {
        http_response_code(400);
        $errorMessage = 'A sessão do formulário expirou. Atualize a página e tente novamente.';
        $openPasswordDialog = $action === 'change_password';
    } elseif ($action === 'update_profile') {
        $name = isset($_POST['nome']) && is_string($_POST['nome']) ? trim($_POST['nome']) : '';
        $profileNameValue = $name;
        $nameLength = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
        if ($name === '' || $nameLength > 180) {
            $errorMessage = 'Informe um nome válido com até 180 caracteres.';
        } else {
            try {
                $updateName = $pdo->prepare('UPDATE usuarios SET nome = :name WHERE id = :user_id AND ativo = 1');
                $updateName->execute(array('name' => $name, 'user_id' => $userId));
                $_SESSION['user_name'] = $name;
                systemLogEvent('atualizou_perfil', 'usuario', $userId, 'sucesso', array('campos' => array('nome')), null, $userId, $pdo);
                $_SESSION['profile_message'] = 'Seus dados foram atualizados.';
                header('Location: meu_perfil.php', true, 303);
                exit;
            } catch (PDOException $exception) {
                error_log('Falha ao atualizar perfil do usuário: ' . $exception->getMessage());
                systemLogEvent('erro_sistema', 'usuario', $userId, 'falha', array('operacao' => 'atualizar_perfil'), $exception->getMessage(), $userId, $pdo);
                http_response_code(500);
                $errorMessage = 'Não foi possível salvar seus dados agora. Tente novamente mais tarde.';
            }
        }
    } elseif ($action === 'change_password') {
        $openPasswordDialog = true;
        $currentPassword = isset($_POST['senha_atual']) && is_string($_POST['senha_atual']) ? $_POST['senha_atual'] : '';
        $newPassword = isset($_POST['senha_nova']) && is_string($_POST['senha_nova']) ? $_POST['senha_nova'] : '';
        $confirmation = isset($_POST['confirmacao_senha']) && is_string($_POST['confirmacao_senha']) ? $_POST['confirmacao_senha'] : '';
        $passwordLength = function_exists('mb_strlen') ? mb_strlen($newPassword, 'UTF-8') : strlen($newPassword);

        if ($currentPassword === '') {
            $errorMessage = 'Informe sua senha atual.';
        } elseif ($passwordLength < 12 || strlen($newPassword) > 72) {
            $errorMessage = 'A nova senha deve conter pelo menos 12 caracteres e ter no máximo 72 bytes.';
        } elseif (!hash_equals($newPassword, $confirmation)) {
            $errorMessage = 'A confirmação não corresponde à nova senha.';
        } elseif (hash_equals($currentPassword, $newPassword)) {
            $errorMessage = 'A nova senha deve ser diferente da senha atual.';
        } else {
            try {
                $pdo->beginTransaction();
                $lockedUserQuery = $pdo->prepare('SELECT senha_hash FROM usuarios WHERE id = :user_id AND ativo = 1 FOR UPDATE');
                $lockedUserQuery->execute(array('user_id' => $userId));
                $lockedPasswordHash = $lockedUserQuery->fetchColumn();
                if (!$lockedPasswordHash || !password_verify($currentPassword, $lockedPasswordHash)) {
                    $pdo->rollBack();
                    systemLogEvent('alterou_senha', 'usuario', $userId, 'negado', array('motivo' => 'senha_atual_invalida'), null, $userId, $pdo);
                    $errorMessage = 'A senha atual está incorreta.';
                } else {
                    $updatePassword = $pdo->prepare('UPDATE usuarios SET senha_hash = :password_hash, senha_temporaria = 0 WHERE id = :user_id AND ativo = 1');
                    $updatePassword->execute(array(
                        'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
                        'user_id' => $userId
                    ));
                    if ($updatePassword->rowCount() !== 1) {
                        throw new RuntimeException('A senha do usuário não pôde ser atualizada.');
                    }
                    systemLogEvent('alterou_senha', 'usuario', $userId, 'sucesso', array(), null, $userId, $pdo);
                    $pdo->commit();
                    session_regenerate_id(true);
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                    $_SESSION['profile_message'] = 'Sua senha foi alterada com sucesso.';
                    header('Location: meu_perfil.php', true, 303);
                    exit;
                }
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Falha ao alterar senha pelo perfil: ' . $exception->getMessage());
                systemLogEvent('erro_sistema', 'usuario', $userId, 'falha', array('operacao' => 'alterar_senha_perfil'), $exception->getMessage(), $userId, $pdo);
                http_response_code(500);
                $errorMessage = 'Não foi possível alterar sua senha agora. Tente novamente mais tarde.';
            }
        }
    } else {
        http_response_code(400);
        $errorMessage = 'A ação solicitada não é válida.';
    }
}

$profileQuery = $pdo->prepare('SELECT p.nome FROM usuario_perfis up INNER JOIN perfis p ON p.id = up.perfil_id WHERE up.usuario_id = :user_id AND p.ativo = 1 AND (up.valido_ate IS NULL OR up.valido_ate >= CURDATE()) ORDER BY up.id LIMIT 1');
$profileQuery->execute(array('user_id' => $userId));
$profileName = $profileQuery->fetchColumn();
$csrfToken = htmlspecialchars(applicationCsrfToken(), ENT_QUOTES, 'UTF-8');
function profileH($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Meu perfil · Central de Compras</title>
    <link rel="shortcut icon" href="https://centraldeservicos.logos-ma.com.br/plugins/trademark/front/picture.send.php?path=8d/66be65b142d8d.png" type="image/x-icon">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.2/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/css/admin.css">
    <link rel="stylesheet" href="assets/css/account.css">
</head>
<body>
    <main class="admin-layout" id="adminLayout">
        <?php renderApplicationSidebar($pdo, $userId, $profiles, 'profile'); ?>
        <div class="admin-content">
            <header class="top">
                <div class="brand">Central de Compras</div>
                <?php renderApplicationUserMenu(); ?>
            </header>
            <div class="account-page">
        <h1>Meu perfil</h1>
        <p class="intro">Confira seus dados e mantenha seu nome atualizado.</p>
        <?php if ($errorMessage !== '' && !$openPasswordDialog) { ?><div class="account-message error" role="alert"><?php echo profileH($errorMessage); ?></div><?php } ?>
        <?php if ($successMessage !== '') { ?><div class="account-message" role="status"><?php echo profileH($successMessage); ?></div><?php } ?>
        <section class="account-panel">
            <h2>Dados da conta</h2>
            <form method="post" action="meu_perfil.php">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="update_profile">
                <div class="account-form-grid">
                    <div class="account-field">
                        <label for="nome">Nome</label>
                        <input id="nome" name="nome" type="text" maxlength="180" value="<?php echo profileH($profileNameValue); ?>" autocomplete="name" required>
                    </div>
                    <div class="account-field">
                        <label for="matricula">Matrícula</label>
                        <input id="matricula" type="text" value="<?php echo profileH($user['matricula']); ?>" readonly>
                        <small>A matrícula não pode ser alterada.</small>
                    </div>
                    <div class="account-field">
                        <label for="email">E-mail</label>
                        <input id="email" type="email" value="<?php echo profileH($user['email']); ?>" readonly>
                        <small>O e-mail não pode ser alterado nesta página.</small>
                    </div>
                    <div class="account-field">
                        <label>Perfil</label>
                    <?php if ($profileName) { ?><input type="text" value="<?php echo profileH($profileName); ?>" readonly><?php } else { ?><input type="text" value="Nenhum perfil ativo" readonly><?php } ?>
                        <small>Os perfis são gerenciados pela Administração.</small>
                    </div>
                </div>
                <div class="account-actions">
                    <button class="account-button" type="submit">Salvar alterações</button>
                    <button class="account-button secondary" type="button" id="openPasswordDialog">Alterar senha</button>
                    <a class="account-button secondary" href="painel.php">Voltar ao painel</a>
                </div>
            </form>
        </section>
            </div>
        </div>
    </main>
    <dialog class="password-dialog" id="passwordDialog" aria-labelledby="passwordDialogTitle">
        <div class="modal-heading">
            <div><h2 id="passwordDialogTitle">Alterar senha</h2><p>Use uma senha nova com pelo menos 12 caracteres.</p></div>
            <button class="modal-close" type="button" id="closePasswordDialog" aria-label="Fechar">×</button>
        </div>
        <?php if ($openPasswordDialog && $errorMessage !== '') { ?><div class="account-message error" role="alert"><?php echo profileH($errorMessage); ?></div><?php } ?>
        <form method="post" action="meu_perfil.php">
            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
            <input type="hidden" name="action" value="change_password">
            <div class="account-field">
                <label for="senha_atual">Senha atual</label>
                <input id="senha_atual" name="senha_atual" type="password" autocomplete="current-password" required>
            </div>
            <div class="account-field">
                <label for="senha_nova">Nova senha</label>
                <input id="senha_nova" name="senha_nova" type="password" minlength="12" maxlength="72" autocomplete="new-password" required>
            </div>
            <div class="account-field">
                <label for="confirmacao_senha">Confirme a nova senha</label>
                <input id="confirmacao_senha" name="confirmacao_senha" type="password" minlength="12" maxlength="72" autocomplete="new-password" required>
            </div>
            <div class="account-actions">
                <button class="account-button" type="submit">Salvar nova senha</button>
                <button class="account-button secondary" type="button" id="cancelPasswordDialog">Cancelar</button>
            </div>
        </form>
    </dialog>
    <script>
        (function () {
            var dialog = document.getElementById('passwordDialog');
            var openButton = document.getElementById('openPasswordDialog');
            var closeButtons = [document.getElementById('closePasswordDialog'), document.getElementById('cancelPasswordDialog')];
            openButton.addEventListener('click', function () { dialog.showModal(); });
            closeButtons.forEach(function (button) {
                button.addEventListener('click', function () { dialog.close(); });
            });
            dialog.addEventListener('click', function (event) {
                if (event.target === dialog) {
                    dialog.close();
                }
            });
            <?php if ($openPasswordDialog) { ?>dialog.showModal();<?php } ?>
        }());
    </script>
    <script src="assets/js/application-sidebar.js"></script>
</body>
</html>
