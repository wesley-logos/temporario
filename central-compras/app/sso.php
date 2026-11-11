<?php
require_once __DIR__ . '/environment.php';
require_once __DIR__ . '/system_log.php';

function ssoBase64UrlDecode($value)
{
    if (!is_string($value) || $value === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $value) !== 1) {
        return false;
    }
    if (strlen($value) % 4 === 1) {
        return false;
    }

    $base64 = strtr($value, '-_', '+/');
    $base64 .= str_repeat('=', (4 - strlen($base64) % 4) % 4);
    return base64_decode($base64, true);
}

function ssoValidateToken($token, array $config, $secret, $now)
{
    if (!is_string($token) || strlen($token) > $config['max_token_length']) {
        return array('ok' => false, 'reason' => 'TOKEN_TAMANHO');
    }

    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return array('ok' => false, 'reason' => 'TOKEN_FORMATO');
    }

    $headerJson = ssoBase64UrlDecode($parts[0]);
    $payloadJson = ssoBase64UrlDecode($parts[1]);
    $signature = ssoBase64UrlDecode($parts[2]);
    if ($headerJson === false || $payloadJson === false || $signature === false) {
        return array('ok' => false, 'reason' => 'TOKEN_BASE64');
    }

    $header = json_decode($headerJson, true);
    $claims = json_decode($payloadJson, true);
    if (!is_array($header) || !is_array($claims) || json_last_error() !== JSON_ERROR_NONE) {
        return array('ok' => false, 'reason' => 'TOKEN_JSON');
    }
    if (!isset($header['alg']) || !is_string($header['alg']) || $header['alg'] !== 'HS256') {
        return array('ok' => false, 'reason' => 'TOKEN_ALG');
    }

    $expectedSignature = hash_hmac('sha256', $parts[0] . '.' . $parts[1], $secret, true);
    if (!hash_equals($expectedSignature, $signature)) {
        return array('ok' => false, 'reason' => 'TOKEN_ASSINATURA');
    }

    if (!isset($claims['iss']) || !is_string($claims['iss']) || $claims['iss'] !== $config['issuer']) {
        return array('ok' => false, 'reason' => 'TOKEN_ISS');
    }
    if (!isset($claims['aud']) || !is_string($claims['aud']) || !in_array(strtolower($claims['aud']), $config['audiences'], true)) {
        return array('ok' => false, 'reason' => 'TOKEN_AUD');
    }
    foreach (array('sub', 'matricula', 'email', 'nome') as $requiredIdentityClaim) {
        if (!isset($claims[$requiredIdentityClaim]) || !is_string($claims[$requiredIdentityClaim])) {
            return array('ok' => false, 'reason' => 'TOKEN_CLAIMS_IDENTIDADE');
        }
    }
    if (!filter_var($claims['email'], FILTER_VALIDATE_EMAIL)) {
        return array('ok' => false, 'reason' => 'TOKEN_CLAIMS_IDENTIDADE');
    }
    if (isset($claims['login_destino']) && !is_string($claims['login_destino'])) {
        return array('ok' => false, 'reason' => 'TOKEN_CLAIMS_IDENTIDADE');
    }
    if (!isset($claims['iat'], $claims['exp']) || !is_int($claims['iat']) || !is_int($claims['exp'])
        || $claims['iat'] <= 0 || $claims['exp'] <= 0
        || $claims['iat'] > $now + $config['max_iat_future_seconds']) {
        return array('ok' => false, 'reason' => 'TOKEN_TEMPO');
    }
    if ($claims['exp'] <= $now) {
        return array('ok' => false, 'reason' => 'TOKEN_EXPIRADO');
    }
    if ($claims['exp'] <= $claims['iat'] || $claims['exp'] - $claims['iat'] > $config['max_token_age_seconds']) {
        return array('ok' => false, 'reason' => 'TOKEN_VALIDADE');
    }
    $jtiLength = isset($claims['jti']) && is_string($claims['jti'])
        ? preg_match_all('/./us', $claims['jti'])
        : false;
    if (!isset($claims['jti']) || !is_string($claims['jti'])
        || preg_match('//u', $claims['jti']) !== 1
        || preg_match('/[\x00-\x1F\x7F]/u', $claims['jti']) === 1
        || $jtiLength === false || $jtiLength < 16 || $jtiLength > 64) {
        return array('ok' => false, 'reason' => 'TOKEN_JTI');
    }

    return array('ok' => true, 'claims' => $claims);
}

function ssoFindActiveUser(PDO $pdo, array $claims, array $config)
{
    if (isset($claims['login_destino']) && is_string($claims['login_destino'])) {
        $user = ssoFindUserByLogin($pdo, $claims['login_destino'], $config);
        if ($user) {
            return $user;
        }
    }

    $user = ssoFindUserByMatricula($pdo, $claims['matricula'], $config);
    if ($user) {
        return $user;
    }

    $user = ssoFindUserByLogin($pdo, $claims['sub'], $config);
    if ($user) {
        return $user;
    }

    if (filter_var($claims['email'], FILTER_VALIDATE_EMAIL)) {
        return ssoFindUserBy($pdo, 'email', $claims['email']);
    }

    return false;
}

function ssoFindUserByLogin(PDO $pdo, $login, array $config)
{
    if (!is_string($login) || trim($login) === '' || strlen($login) > 254 || preg_match('//u', $login) !== 1) {
        return false;
    }

    $user = ssoFindUserByMatricula($pdo, $login, $config);
    if ($user) {
        return $user;
    }

    if (filter_var($login, FILTER_VALIDATE_EMAIL)) {
        return ssoFindUserBy($pdo, 'email', $login);
    }

    return false;
}

function ssoFindUserByMatricula(PDO $pdo, $matricula, array $config)
{
    if (!is_string($matricula) || trim($matricula) === '' || strlen($matricula) > 40 || preg_match('//u', $matricula) !== 1) {
        return false;
    }

    $candidates = array($matricula);
    if (preg_match('/^\d+$/D', $matricula) === 1 && $config['matricula_digits'] > 0) {
        $digits = (int) $config['matricula_digits'];
        if (strlen($matricula) <= $digits) {
            $candidates[] = str_pad($matricula, $digits, '0', STR_PAD_LEFT);
        }
        if (strlen($matricula) === $digits) {
            $candidates[] = ltrim($matricula, '0');
        }
    }

    foreach (array_unique($candidates) as $candidate) {
        $user = ssoFindUserBy($pdo, 'matricula', $candidate);
        if ($user) {
            return $user;
        }
    }

    return false;
}

function ssoFindUserBy(PDO $pdo, $column, $value)
{
    if ($column !== 'matricula' && $column !== 'email') {
        return false;
    }
    $statement = $pdo->prepare('SELECT id, matricula, email, nome, senha_temporaria FROM usuarios WHERE ' . $column . ' = :value AND ativo = 1 LIMIT 1');
    $statement->execute(array('value' => $value));
    $user = $statement->fetch();
    return $user ? $user : false;
}

function ssoClientIp()
{
    // Somente confie no cabecalho quando o proxy estiver explicitamente configurado e for a unica origem aceita.
    if (applicationEnv('APP_TRUST_X_FORWARDED_PROTO', '0') === '1' && isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $forwardedIps = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $forwardedIp = trim($forwardedIps[0]);
        if (filter_var($forwardedIp, FILTER_VALIDATE_IP)) {
            return $forwardedIp;
        }
    }

    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null;
}

function ssoLog($result, $reason)
{
    $ip = ssoClientIp();
    error_log('[SSO] resultado=' . $result . ' motivo=' . preg_replace('/[^A-Z0-9_]/', '', strtoupper($reason)) . ' ip=' . ($ip ? $ip : 'indisponivel'));
}

function ssoErrorResponse($status, $message, $reason)
{
    ssoLog('FALHA', $reason);
    systemLogEvent('login_sso_falha', 'autenticacao', null, $status >= 500 ? 'falha' : 'negado', array('motivo' => $reason), $status >= 500 ? $reason : null);
    http_response_code($status);
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('Referrer-Policy: no-referrer');
    if ($status === 401) {
        header('Content-Type: application/json; charset=UTF-8');
        $errorCode = 'token_invalid';
        if ($reason === 'TOKEN_EXPIRADO') {
            $errorCode = 'token_expired';
        } elseif ($reason === 'REPLAY') {
            $errorCode = 'token_replay';
        }
        echo json_encode(array('error' => $errorCode, 'message' => $message), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html lang="pt-BR"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Acesso pelo Portal</title><body style="font:16px Arial,sans-serif;background:#f5f6f8;color:#23272f;text-align:center;padding:12vh 20px"><h2>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</h2><p><a href="index.php">Ir para o login</a></p></body></html>';
    exit;
}
