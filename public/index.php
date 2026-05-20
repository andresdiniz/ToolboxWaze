<?php

// === DEBUG TEMPORARIO - REMOVER APOS RESOLVER O PROBLEMA ===
ini_set('display_errors', 0); // nao exibir na tela (seguranca)
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../var/log/php_errors.log');
error_reporting(E_ALL);

// Loga que chegou no index.php
error_log('[index.php] Iniciando - APP_ENV: ' . ($_SERVER['APP_ENV'] ?? getenv('APP_ENV') ?? 'nao definido'));
error_log('[index.php] REQUEST_URI: ' . ($_SERVER['REQUEST_URI'] ?? 'n/a'));
error_log('[index.php] SCRIPT_FILENAME: ' . ($_SERVER['SCRIPT_FILENAME'] ?? 'n/a'));
error_log('[index.php] DOCUMENT_ROOT: ' . ($_SERVER['DOCUMENT_ROOT'] ?? 'n/a'));
error_log('[index.php] dirname(__DIR__): ' . dirname(__DIR__));
error_log('[index.php] vendor existe: ' . (file_exists(dirname(__DIR__).'/vendor/autoload_runtime.php') ? 'SIM' : 'NAO'));
// === FIM DEBUG ===

use App\Kernel;

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

return static function (array $context) {
    error_log('[index.php] Kernel iniciado com APP_ENV=' . $context['APP_ENV']);
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
