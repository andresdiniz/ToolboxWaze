<?php
// index.php na RAIZ - encaminha para o front controller do Symfony em public/

// Garante que o APP_ENV seja lido do .env caso nao esteja no ambiente
if (!isset($_SERVER['APP_ENV']) && !getenv('APP_ENV')) {
    // Le o .env manualmente para garantir APP_ENV
    $envFile = __DIR__ . '/.env';
    if (file_exists($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) continue;
            if (strpos($line, '=') !== false) {
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);
                if (!isset($_ENV[$key]) && !isset($_SERVER[$key])) {
                    $_ENV[$key] = $value;
                    $_SERVER[$key] = $value;
                    putenv("$key=$value");
                }
            }
        }
    }
}

$_SERVER['DOCUMENT_ROOT'] = __DIR__ . '/public';

require_once __DIR__ . '/public/index.php';
