<?php
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/system_log.php';

startApplicationSession();
$userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
if ($userId) {
    systemLogEvent('logout', 'usuario', $userId, 'sucesso', array(), null, $userId);
}
$_SESSION = array();

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}

session_destroy();
header('Location: index.php');
exit;
