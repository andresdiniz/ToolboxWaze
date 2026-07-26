<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\BrazilianState;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/estados', name: 'admin_estados_')]
#[IsGranted('ROLE_ADMIN')]
final class BrazilianStateCrudController extends AbstractController
{
    private const HEARTBEAT_INTERVAL = 20;

    /**
     * Candidatos ao PHP CLI.
     *
     * IMPORTANTE: na CloudLinux/cPanel (Hostinger), o binario CLI fica em
     *   /opt/alt/phpXX/usr/bin/php   <-- CLI real
     *   /opt/alt/phpXX/usr/bin/lsphp <-- LiteSpeed CGI (NAO serve)
     *
     * Os padroes sao testados em ordem; o primeiro executavel vence.
     */
    private const PHP_CLI_CANDIDATES = [
        // CloudLinux / cPanel (Hostinger) - php85, php84, php83, php82, php81
        '/opt/alt/php85/usr/bin/php',
        '/opt/alt/php84/usr/bin/php',
        '/opt/alt/php83/usr/bin/php',
        '/opt/alt/php82/usr/bin/php',
        '/opt/alt/php81/usr/bin/php',
        '/opt/alt/php80/usr/bin/php',
        // Hostinger VPS / Ubuntu
        '/usr/local/php83/bin/php',
        '/usr/local/php82/bin/php',
        '/usr/local/php81/bin/php',
        '/usr/bin/php8.5',
        '/usr/bin/php8.4',
        '/usr/bin/php8.3',
        '/usr/bin/php8.2',
        '/usr/bin/php8.1',
        '/usr/bin/php8',
        '/usr/bin/php',
        '/usr/local/bin/php',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {}

    // =========================================================================
    // LIST
    // =========================================================================

    #[Route('', name: 'index')]
    public function index(): Response
    {
        $states = $this->em
            ->getRepository(BrazilianState::class)
            ->findBy([], ['uf' => 'ASC']);

        return $this->render('admin/estados/index.html.twig', [
            'states' => $states,
        ]);
    }

    // =========================================================================
    // EDIT
    // =========================================================================

    #[Route('/{id}/editar', name: 'edit', requirements: ['id' => '\\d+'])]
    public function edit(int $id, Request $req): Response
    {
        $state = $this->em->find(BrazilianState::class, $id);
        if (!$state) {
            throw $this->createNotFoundException('Estado não encontrado.');
        }

        if ($req->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('edit_state_' . $id, $req->request->get('_token'))) {
                $this->addFlash('error', 'Token inválido.');
                return $this->redirectToRoute('admin_estados_index');
            }

            $state->setLinkBaseRadares(trim((string) $req->request->get('link_base_radares', '')) ?: null);
            $state->setLinkReferenciaRadares(trim((string) $req->request->get('link_referencia_radares', '')) ?: null);
            $this->em->flush();
            $this->addFlash('success', sprintf('Estado %s atualizado.', $state->getUf()));

            return $this->redirectToRoute('admin_estados_index');
        }

        return $this->render('admin/estados/edit.html.twig', ['state' => $state]);
    }

    // =========================================================================
    // TELA DE LOG
    // =========================================================================

    #[Route('/{id}/importar', name: 'importar', requirements: ['id' => '\\d+'])]
    public function importar(int $id, Request $req): Response
    {
        $state = $this->em->find(BrazilianState::class, $id);
        if (!$state) {
            throw $this->createNotFoundException('Estado não encontrado.');
        }

        $skipWaze = (bool) $req->query->get('skip_waze', false);

        return $this->render('admin/estados/importar_log.html.twig', [
            'state'      => $state,
            'skip_waze'  => $skipWaze,
            'stream_url' => $this->generateUrl('admin_estados_importar_stream', [
                'id'        => $id,
                'skip_waze' => $skipWaze ? '1' : '0',
            ]),
        ]);
    }

    // =========================================================================
    // SSE STREAM
    // =========================================================================

    #[Route('/{id}/importar/stream', name: 'importar_stream', requirements: ['id' => '\\d+'])]
    public function importarStream(int $id, Request $req): StreamedResponse
    {
        $state = $this->em->find(BrazilianState::class, $id);

        if (!$state) {
            return new StreamedResponse(static function () {
                echo "data: [ERRO] Estado não encontrado.\n\n";
                flush();
            }, 404, ['Content-Type' => 'text/event-stream']);
        }

        $skipWaze   = (bool) $req->query->get('skip_waze', false);
        $uf         = $state->getUf();
        $projectDir = $this->projectDir;
        $console    = $projectDir . '/bin/console';

        $response = new StreamedResponse(function () use ($uf, $skipWaze, $projectDir, $console) {
            set_time_limit(0);
            ignore_user_abort(true);

            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            // Log em arquivo para diagnóstico mesmo quando SSE cair
            $logDir  = $projectDir . '/var/log';
            if (!is_dir($logDir)) {
                @mkdir($logDir, 0755, true);
            }
            $logFile = sprintf('%s/import_%s_%s.log', $logDir, $uf, date('Ymd_His'));
            $logFh   = fopen($logFile, 'wb');

            $log = static function (string $line) use ($logFh) {
                if ($logFh) {
                    fwrite($logFh, '[' . date('H:i:s') . '] ' . $line . "\n");
                    fflush($logFh);
                }
            };

            $send = static function (string $line, string $event = 'message') use ($log) {
                $log($line);
                $line = str_replace(["\r\n", "\r", "\n"], ' ', $line);
                if ($event !== 'message') {
                    echo 'event: ' . $event . "\n";
                }
                echo 'data: ' . $line . "\n\n";
                flush();
            };

            // ── Detecta PHP CLI ───────────────────────────────────────
            $phpBin = $this->resolvePHPCli();

            $send(sprintf(
                '🚀 Iniciando importação de <strong>%s</strong>%s…',
                $uf,
                $skipWaze ? ' <span class="badge-skip">(sem Waze)</span>' : ''
            ));

            if ($phpBin === null) {
                $send('❌ PHP CLI não localizado. Verifique PHP_CLI_CANDIDATES no controller.');
                $send('📊 Candidatos testados: ' . implode(', ', self::PHP_CLI_CANDIDATES));
                echo "event: done\ndata: 1\n\n";
                flush();
                if ($logFh) fclose($logFh);
                return;
            }

            $send('🔧 PHP CLI: ' . $phpBin);
            $send('📝 Log: ' . $logFile);

            // ── Monta comando ──────────────────────────────────────────
            $args = [
                escapeshellarg($phpBin),
                escapeshellarg($console),
                'app:import-radares',
                '--uf=' . escapeshellarg($uf),
                '--env=prod',
                '--no-interaction',
            ];
            if ($skipWaze) {
                $args[] = '--skip-waze';
            }
            $cmdStr = implode(' ', $args) . ' 2>&1';

            $log('CMD: ' . $cmdStr);

            // ── Tenta proc_open ─────────────────────────────────────────
            $procOpenAvailable = function_exists('proc_open')
                && !in_array('proc_open', array_map('trim', explode(',', ini_get('disable_functions'))), true);

            if ($procOpenAvailable) {
                $log('Usando proc_open');
                $exitCode = $this->runViaProcOpen($cmdStr, $projectDir, $send, $log);
            } else {
                // Fallback: roda tudo de uma vez e envia no final
                $log('proc_open indisponível — usando shell_exec (sem streaming)');
                $send('⚠️ proc_open bloqueado no servidor — aguarde, o log aparecerá ao final.');
                $exitCode = $this->runViaShellExec($cmdStr, $send, $log);
            }

            if ($exitCode === 0) {
                $send('✅ Importação concluída! (exit 0)');
            } else {
                $send(sprintf('❌ Processo encerrado com código %d.', $exitCode));
            }

            echo "event: done\ndata: {$exitCode}\n\n";
            flush();

            if ($logFh) fclose($logFh);
        });

        $response->headers->set('Content-Type',      'text/event-stream');
        $response->headers->set('Cache-Control',     'no-cache, no-store');
        $response->headers->set('X-Accel-Buffering', 'no');
        $response->headers->set('Connection',        'keep-alive');

        return $response;
    }

    // =========================================================================
    // Runner: proc_open (streaming em tempo real)
    // =========================================================================

    private function runViaProcOpen(string $cmdStr, string $cwd, callable $send, callable $log): int
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $proc = proc_open($cmdStr, $descriptors, $pipes, $cwd);

        if (!is_resource($proc)) {
            $send('❌ proc_open falhou ao abrir o processo.');
            $log('ERRO: proc_open retornou false');
            return 1;
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $buffer        = '';
        $lastHeartbeat = microtime(true);

        while (true) {
            $status = proc_get_status($proc);

            $chunk  = fread($pipes[1], 8192);
            $chunk2 = fread($pipes[2], 8192);

            if ($chunk !== false && $chunk !== '')  { $buffer .= $chunk; }
            if ($chunk2 !== false && $chunk2 !== '') { $buffer .= $chunk2; }

            while (($pos = strpos($buffer, "\n")) !== false) {
                $line   = rtrim(substr($buffer, 0, $pos), "\r");
                $buffer = substr($buffer, $pos + 1);
                if (trim($line) !== '') {
                    $send($line);
                }
            }

            if ((microtime(true) - $lastHeartbeat) >= self::HEARTBEAT_INTERVAL) {
                echo ": heartbeat\n\n";
                flush();
                $lastHeartbeat = microtime(true);
            }

            if (!$status['running']) {
                break;
            }

            usleep(100_000);
        }

        // Drena o restante
        $buffer .= (string) stream_get_contents($pipes[1]);
        $buffer .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        foreach (explode("\n", $buffer) as $line) {
            $line = rtrim($line, "\r");
            if (trim($line) !== '') {
                $send($line);
            }
        }

        return proc_close($proc);
    }

    // =========================================================================
    // Runner: shell_exec (fallback sem streaming)
    // =========================================================================

    private function runViaShellExec(string $cmdStr, callable $send, callable $log): int
    {
        // Captura stdout + stderr e exit code
        $outputFile = tempnam(sys_get_temp_dir(), 'imp_out_');
        $exitFile   = tempnam(sys_get_temp_dir(), 'imp_exit_');

        $fullCmd = sprintf('%s > %s 2>&1; echo $? > %s',
            $cmdStr,
            escapeshellarg((string) $outputFile),
            escapeshellarg((string) $exitFile)
        );

        $log('shell_exec CMD: ' . $fullCmd);
        shell_exec($fullCmd);

        // Envia o output linha a linha
        if ($outputFile && file_exists((string) $outputFile)) {
            $content = file_get_contents((string) $outputFile);
            if ($content !== false) {
                foreach (explode("\n", $content) as $line) {
                    $line = rtrim($line, "\r");
                    if (trim($line) !== '') {
                        $send($line);
                    }
                }
            }
            @unlink((string) $outputFile);
        }

        $exitCode = 0;
        if ($exitFile && file_exists((string) $exitFile)) {
            $exitCode = (int) trim((string) file_get_contents((string) $exitFile));
            @unlink((string) $exitFile);
        }

        return $exitCode;
    }

    // =========================================================================
    // Helper: detecta PHP CLI real
    // =========================================================================

    private function resolvePHPCli(): ?string
    {
        // 1. PHP_BINARY só é confiável se não for cgi/fpm/lsphp
        $phpBinary = PHP_BINARY;
        if (
            !str_contains($phpBinary, 'cgi')
            && !str_contains($phpBinary, 'fpm')
            && !str_contains($phpBinary, 'lsphp')
            && is_executable($phpBinary)
        ) {
            return $phpBinary;
        }

        // 2. Candidatos conhecidos
        foreach (self::PHP_CLI_CANDIDATES as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        // 3. which php
        $which = trim((string) shell_exec('which php 2>/dev/null'));
        if ($which !== '' && is_executable($which) && !str_contains($which, 'lsphp')) {
            return $which;
        }

        return null;
    }
}
