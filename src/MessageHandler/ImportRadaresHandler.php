<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\ImportRadaresMessage;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\StreamOutput;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class ImportRadaresHandler
{
    public function __construct(
        private readonly KernelInterface $kernel,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {}

    public function __invoke(ImportRadaresMessage $msg): void
    {
        $logFile  = $msg->logFile;
        $doneFile = $logFile . '.done';
        $failFile = $logFile . '.fail';

        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }

        $fh = fopen($logFile, 'ab');
        if ($fh === false) {
            file_put_contents($failFile, '');
            return;
        }

        try {
            // Carrega o kernel de console e executa o comando in-process
            $consoleApp = new Application($this->kernel);
            $consoleApp->setAutoExit(false);
            $consoleApp->setCatchExceptions(true);

            $args = [
                'command' => 'app:import-radares',
                '--uf'    => $msg->uf,
                '--env'   => 'prod',
                '--no-interaction' => true,
            ];
            if ($msg->skipWaze) {
                $args['--skip-waze'] = true;
            }

            $input  = new ArrayInput($args);
            $output = new StreamOutput($fh, StreamOutput::VERBOSITY_NORMAL, false);

            $exitCode = $consoleApp->run($input, $output);

            fwrite($fh, PHP_EOL . 'EXIT:' . $exitCode . PHP_EOL);
            fclose($fh);

            if ($exitCode === 0) {
                file_put_contents($doneFile, (string) time());
            } else {
                file_put_contents($failFile, (string) $exitCode);
            }
        } catch (\Throwable $e) {
            if (is_resource($fh)) {
                fwrite($fh, PHP_EOL . '[EXCEPTION] ' . $e->getMessage() . PHP_EOL);
                fwrite($fh, $e->getTraceAsString() . PHP_EOL);
                fclose($fh);
            }
            file_put_contents($failFile, $e->getMessage());
        }
    }
}
