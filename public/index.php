<?php

// === DEBUG TEMPORARIO ===
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', dirname(__DIR__) . '/var/log/php_errors.log');
error_reporting(E_ALL);

set_exception_handler(function (Throwable $e) {
    error_log('[FATAL] ' . get_class($e) . ': ' . $e->getMessage());
    error_log('[FATAL] em ' . $e->getFile() . ':' . $e->getLine());
    error_log('[FATAL] Trace: ' . $e->getTraceAsString());
    http_response_code(500);
    echo '500 - Erro interno. Verifique var/log/php_errors.log';
    exit(1);
});

set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    error_log("[PHP ERROR $errno] $errstr em $errfile:$errline");
    return false;
});

error_log('[public/index.php] APP_ENV=' . ($_SERVER['APP_ENV'] ?? getenv('APP_ENV') ?? 'VAZIO'));
error_log('[public/index.php] APP_DEBUG=' . ($_SERVER['APP_DEBUG'] ?? getenv('APP_DEBUG') ?? 'VAZIO'));
error_log('[public/index.php] DOCUMENT_ROOT=' . ($_SERVER['DOCUMENT_ROOT'] ?? 'n/a'));
error_log('[public/index.php] REQUEST_URI=' . ($_SERVER['REQUEST_URI'] ?? 'n/a'));
// === FIM DEBUG ===

use App\Kernel;

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

return static function (array $context) {
    error_log('[public/index.php] Kernel criado APP_ENV=' . $context['APP_ENV'] . ' DEBUG=' . ($context['APP_DEBUG'] ? 'true' : 'false'));
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
