#!/usr/bin/env php
<?php

/**
 * Script de verificação de migrations pendentes para CI.
 * Uso: php bin/check-migrations.php
 * Retorna exit code 1 se houver migrations não executadas.
 *
 * Adicione ao seu pipeline:
 *   - run: php bin/check-migrations.php
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

$kernel = new \App\Kernel($_SERVER['APP_ENV'] ?? 'prod', (bool) ($_SERVER['APP_DEBUG'] ?? false));
$kernel->boot();

$container = $kernel->getContainer();

/** @var \Doctrine\Migrations\DependencyFactory $factory */
$factory = $container->get('doctrine.migrations.dependency_factory');

$statusCalculator = $factory->getStatusCalculator();
$infosAfter       = $statusCalculator->getMigrationsInfoAfterExecution();

$pending = $infosAfter->getNewMigrations();

if (count($pending) === 0) {
    echo "[OK] Nenhuma migration pendente.\n";
    exit(0);
}

echo "[ERRO] Há " . count($pending) . " migration(s) pendente(s):\n";
foreach ($pending as $migration) {
    echo '  - ' . $migration->getVersion() . "\n";
}
echo "\nRode: php bin/console doctrine:migrations:migrate --no-interaction\n";
exit(1);
