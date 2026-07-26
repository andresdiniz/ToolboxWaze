<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Executa messenger:consume async --limit=1 via HTTP.
 *
 * Usado como substituto do cron na Hostinger shared hosting,
 * ou para testar se o worker funciona antes de configurar o cron.
 *
 * URL: GET /admin/worker/run
 *   - Requer ROLE_ADMIN (sessao autenticada)
 *   - Param opcional: ?verbose=1 para output detalhado
 */
#[Route('/admin/worker', name: 'admin_worker_')]
#[IsGranted('ROLE_ADMIN')]
final class WorkerRunController extends AbstractController
{
    public function __construct(
        private readonly KernelInterface $kernel,
    ) {}

    #[Route('/run', name: 'run')]
    public function run(Request $req): JsonResponse
    {
        try {
            $verbose = (bool) $req->query->get('verbose', false);

            $app = new Application($this->kernel);
            $app->setAutoExit(false);
            $app->setCatchExceptions(true);

            $input = new ArrayInput([
                'command'      => 'messenger:consume',
                'receivers'    => ['async'],
                '--limit'      => '1',
                '--time-limit' => '55',
                '--env'        => 'prod',
                '--no-interaction' => true,
            ]);
            $input->setInteractive(false);

            $output   = new BufferedOutput(
                $verbose ? BufferedOutput::VERBOSITY_VERBOSE : BufferedOutput::VERBOSITY_NORMAL
            );

            $t0       = microtime(true);
            $exitCode = $app->run($input, $output);
            $elapsed  = round(microtime(true) - $t0, 2);
            $text     = $output->fetch();

            return $this->json([
                'ok'       => $exitCode === 0,
                'exit'     => $exitCode,
                'elapsed'  => $elapsed . 's',
                'output'   => $text ?: '(sem output)',
            ]);

        } catch (\Throwable $e) {
            return $this->json([
                'ok'    => false,
                'error' => $e->getMessage(),
                'class' => get_class($e),
                'file'  => $e->getFile() . ':' . $e->getLine(),
            ], 500);
        }
    }

    /**
     * Endpoint publico (sem sessao) para uso em cron externo.
     * Protegido por token secreto no .env: APP_WORKER_TOKEN
     *
     * Exemplo de cron externo (cron-job.org, UptimeRobot, etc):
     *   GET https://wazetoolbox.acheireviews.com.br/admin/worker/cron?token=SEU_TOKEN
     */
    #[Route('/cron', name: 'cron')]
    public function cron(Request $req): JsonResponse
    {
        $expected = $_ENV['APP_WORKER_TOKEN'] ?? '';
        $provided = (string) $req->query->get('token', '');

        if ($expected === '' || !hash_equals($expected, $provided)) {
            return $this->json(['error' => 'Token invalido.'], 403);
        }

        try {
            $app = new Application($this->kernel);
            $app->setAutoExit(false);
            $app->setCatchExceptions(true);

            $input = new ArrayInput([
                'command'      => 'messenger:consume',
                'receivers'    => ['async'],
                '--limit'      => '3',
                '--time-limit' => '25',
                '--env'        => 'prod',
                '--no-interaction' => true,
            ]);
            $input->setInteractive(false);

            $output   = new BufferedOutput();
            $t0       = microtime(true);
            $exitCode = $app->run($input, $output);
            $elapsed  = round(microtime(true) - $t0, 2);

            return $this->json([
                'ok'      => $exitCode === 0,
                'exit'    => $exitCode,
                'elapsed' => $elapsed . 's',
                'output'  => $output->fetch() ?: '(sem mensagens na fila)',
            ]);

        } catch (\Throwable $e) {
            return $this->json([
                'ok'    => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
