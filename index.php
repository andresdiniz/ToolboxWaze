<?php
// index.php na RAIZ

// Le o .env manualmente
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value, '"\' ');
            if (!array_key_exists($key, $_ENV) && !array_key_exists($key, $_SERVER)) {
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
                putenv("$key=$value");
            }
        }
    }
}

// === DEBUG TEMPORARIO: forca dev+debug para ver erro real ===
$_ENV['APP_ENV'] = 'dev';
$_SERVER['APP_ENV'] = 'dev';
$_ENV['APP_DEBUG'] = '1';
$_SERVER['APP_DEBUG'] = '1';
putenv('APP_ENV=dev');
putenv('APP_DEBUG=1');
// === FIM DEBUG ===

$_SERVER['DOCUMENT_ROOT'] = __DIR__ . '/public';

use App\Kernel;

require_once __DIR__ . '/vendor/autoload_runtime.php';

return static function (array $context) {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
