<?php
require_once dirname(__DIR__) . '/vendor/autoload.php';

/** Load the local .env once without replacing server-provided values. */
function loadApplicationEnvironment($directory = null)
{
    static $loaded = false;
    if ($loaded) {
        return;
    }

    $loaded = true;
    if ($directory === null) {
        $directory = dirname(__DIR__);
    }

    Dotenv\Dotenv::createImmutable($directory)->safeLoad();
}

/** Read a value from the process environment, then phpdotenv's superglobals. */
function applicationEnv($name, $default = null)
{
    $value = getenv($name);
    if ($value !== false) {
        return $value;
    }
    if (array_key_exists($name, $_ENV)) {
        return $_ENV[$name];
    }
    if (array_key_exists($name, $_SERVER)) {
        return $_SERVER[$name];
    }

    return $default;
}

loadApplicationEnvironment();

// All application date/time values use Brasília time (UTC-03:00).
date_default_timezone_set('America/Sao_Paulo');
