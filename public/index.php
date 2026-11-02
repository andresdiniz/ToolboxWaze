<?php

use App\Kernel;

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

// Força ambiente de produção, sobrescrevendo o .env.local
$_SERVER['APP_ENV'] = 'prod';
$_SERVER['APP_DEBUG'] = '0';

return static function (array $context) {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};