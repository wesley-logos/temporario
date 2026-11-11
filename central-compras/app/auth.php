<?php
require_once __DIR__ . '/environment.php';

function startApplicationSession()
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $isHttps = applicationRequestIsHttps();
    ini_set('session.use_strict_mode', '1');
    session_name('central_compras_session');
    session_set_cookie_params(array(
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax'
    ));
    session_start();
}

function applicationRequestIsHttps()
{
    $isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && strtolower($_SERVER['HTTPS']) !== 'off';
    if ($isHttps) {
        return true;
    }

    // VALOR GENERICO: habilite APP_TRUST_X_FORWARDED_PROTO=1 somente se um proxy confiavel remover/substituir esse cabecalho.
    if (applicationEnv('APP_TRUST_X_FORWARDED_PROTO', '0') === '1' && isset($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $forwardedProto = strtolower(trim(explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'])[0]));
        return $forwardedProto === 'https';
    }

    return false;
}

function applicationCsrfToken()
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function validApplicationCsrfToken($token)
{
    return isset($_SESSION['csrf_token']) && is_string($token)
        && hash_equals($_SESSION['csrf_token'], $token);
}
