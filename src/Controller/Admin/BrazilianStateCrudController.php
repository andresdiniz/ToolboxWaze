<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\BrazilianState;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/estados', name: 'admin_estados_')]
#[IsGranted('ROLE_ADMIN')]
final class BrazilianStateCrudController extends AbstractController
{
    /** Segundos maximos aguardando o processo (10 minutos) */
    private const MAX_POLL_SECONDS = 600;

    /** Intervalo do heartbeat SSE em segundos */
    private const HEARTBEAT_INTERVAL = 15;

    private const PHP_CLI_CANDIDATES = [
        '/opt/alt/php85/usr/bin/php',
        '/opt/alt/php84/usr/bin/php',
        '/opt/alt/php83/usr/bin/php',
        '/opt/alt/php82/usr/bin/php',
        '/opt/alt/php81/usr/bin/php',
        '/opt/alt/php80/usr/bin/php',
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

        return $this->render('admin/estados/index.html.twig', ['states' => $states]);
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
            'start_url'  => $this->generateUrl('admin_estados_importar_start', [
                'id'        => $id,
                'skip_waze' => $skipWaze ? '1' : '0',
            ]),
        ]);
    }

    // =========================================================================
    // START — inicia o processo em background e retorna o token do log
    // =========================================================================

    #[Route('/{id}/importar/start', name: 'importar_start', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function importarStart(int $id, Request $req): JsonResponse
    {
        if (!$this->isCsrfTokenValid('import_start_' . $id, $req->request->get('_token'))) {
            return $this->json(['error' => 'Token CSRF inválido.'], 403);
        }

        $state = $this->em->find(BrazilianState::class, $id);
        if (!$state) {
            return $this->json(['error' => 'Estado não encontrado.'], 404);
        }

        $skipWaze = (bool) $req->request->get('skip_waze', false);
        $uf       = $state->getUf();
        $phpBin   = $this->resolvePHPCli();

        if ($phpBin === null) {
            return $this->json(['error' => 'PHP CLI não localizado no servidor.'], 500);
        }

        // Prepara arquivo de log com token unico
        $logDir = $this->projectDir . '/var/log';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }

        $token   = bin2hex(random_bytes(12));
        $logFile = sprintf('%s/import_%s_%s.log', $logDir, $uf, $token);
        $doneFile= $logFile . '.done';
        $failFile= $logFile . '.fail';

        // Monta comando
        $console = $this->projectDir . '/bin/console';
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
        $cmdStr = implode(' ', $args);

        // Wrapper shell: redireciona output para o log, grava .done ou .fail ao fim
        $shellScript = sprintf(
            '(%s >> %s 2>&1 && echo "EXIT:0" >> %s && touch %s) || (echo "EXIT:$?" >> %s && touch %s)',
            $cmdStr,
            escapeshellarg($logFile),
            escapeshellarg($logFile),
            escapeshellarg($doneFile),
            escapeshellarg($logFile),
            escapeshellarg($failFile)
        );

        // Dispara em background e retorna imediatamente
        // nohup + redirec stdout/stderr para /dev/null (ja esta no log)
        // O & no final desacopla o processo do request HTTP
        $bgCmd = sprintf('nohup bash -c %s > /dev/null 2>&1 &', escapeshellarg($shellScript));
        shell_exec($bgCmd);

        return $this->json([
            'token'    => $token,
            'uf'       => $uf,
            'log_file' => basename($logFile),
            'poll_url' => $this->generateUrl('admin_estados_importar_poll', ['token' => $token]),
        ]);
    }

    // =========================================================================
    // POLL — SSE que le o log e envia linha a linha
    // =========================================================================

    #[Route('/importar/poll', name: 'importar_poll')]
    public function importarPoll(Request $req): StreamedResponse
    {
        $token = preg_replace('/[^a-f0-9]/', '', (string) $req->query->get('token', ''));

        if ($token === '') {
            return new StreamedResponse(static function () {
                echo "data: [ERRO] Token inválido.\n\nevent: done\ndata: 1\n\n";
                flush();
            }, 400, ['Content-Type' => 'text/event-stream']);
        }

        $logDir  = $this->projectDir . '/var/log';
        $logGlob = $logDir . '/import_*_' . $token . '.log';
        $projectDir = $this->projectDir;

        $response = new StreamedResponse(function () use ($token, $logGlob, $projectDir) {
            set_time_limit(0);
            ignore_user_abort(true);

            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            $send = static function (string $line, string $event = 'message') {
                $line = str_replace(["\r\n", "\r", "\n"], ' ', $line);
                if ($event !== 'message') {
                    echo 'event: ' . $event . "\n";
                }
                echo 'data: ' . $line . "\n\n";
                flush();
            };

            // Espera o arquivo de log aparecer (processo pode demorar 1-2s para iniciar)
            $waited   = 0;
            $logFile  = null;
            while ($waited < 10) {
                $files = glob($logGlob);
                if (!empty($files)) {
                    $logFile = $files[0];
                    break;
                }
                sleep(1);
                $waited++;
            }

            if ($logFile === null) {
                $send('❌ Arquivo de log não encontrado. O processo pode não ter iniciado.');
                echo "event: done\ndata: 1\n\n";
                flush();
                return;
            }

            $doneFile = $logFile . '.done';
            $failFile = $logFile . '.fail';

            $fh = fopen($logFile, 'rb');
            if ($fh === false) {
                $send('❌ Não foi possível abrir o arquivo de log.');
                echo "event: done\ndata: 1\n\n";
                flush();
                return;
            }

            $send('🚀 Processo iniciado em background — lendo log em tempo real…');

            $elapsed       = 0;
            $lastHeartbeat = microtime(true);
            $buffer        = '';

            while ($elapsed < self::MAX_POLL_SECONDS) {
                // Le novas linhas do arquivo de log
                $chunk = fread($fh, 8192);
                if ($chunk !== false && $chunk !== '') {
                    $buffer .= $chunk;
                }

                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line   = rtrim(substr($buffer, 0, $pos), "\r");
                    $buffer = substr($buffer, $pos + 1);
                    if (trim($line) !== '') {
                        $send($line);
                    }
                }

                // Heartbeat
                if ((microtime(true) - $lastHeartbeat) >= self::HEARTBEAT_INTERVAL) {
                    echo ": heartbeat\n\n";
                    flush();
                    $lastHeartbeat = microtime(true);
                }

                // Verifica se o processo terminou
                if (file_exists($doneFile)) {
                    // Drena o restante do log
                    while (!feof($fh)) {
                        $chunk = fread($fh, 8192);
                        if ($chunk !== false && $chunk !== '') { $buffer .= $chunk; }
                    }
                    while (($pos = strpos($buffer, "\n")) !== false) {
                        $line   = rtrim(substr($buffer, 0, $pos), "\r");
                        $buffer = substr($buffer, $pos + 1);
                        if (trim($line) !== '') { $send($line); }
                    }
                    if (trim($buffer) !== '') { $send(trim($buffer)); }
                    fclose($fh);
                    $send('✅ Importação concluída com sucesso!');
                    echo "event: done\ndata: 0\n\n";
                    flush();
                    return;
                }

                if (file_exists($failFile)) {
                    while (!feof($fh)) {
                        $chunk = fread($fh, 8192);
                        if ($chunk !== false && $chunk !== '') { $buffer .= $chunk; }
                    }
                    while (($pos = strpos($buffer, "\n")) !== false) {
                        $line   = rtrim(substr($buffer, 0, $pos), "\r");
                        $buffer = substr($buffer, $pos + 1);
                        if (trim($line) !== '') { $send($line); }
                    }
                    if (trim($buffer) !== '') { $send(trim($buffer)); }
                    fclose($fh);
                    $send('❌ Processo encerrou com erro. Verifique o log acima.');
                    echo "event: done\ndata: 1\n\n";
                    flush();
                    return;
                }

                usleep(500_000); // 500ms
                $elapsed += 0.5;
            }

            fclose($fh);
            $send('⏰ Timeout: o processo ultrapassou ' . self::MAX_POLL_SECONDS . 's.');
            $send('ℹ️  Verifique o log em: var/log/' . basename((string) $logFile));
            echo "event: done\ndata: 1\n\n";
            flush();
        });

        $response->headers->set('Content-Type',      'text/event-stream');
        $response->headers->set('Cache-Control',     'no-cache, no-store');
        $response->headers->set('X-Accel-Buffering', 'no');
        $response->headers->set('Connection',        'keep-alive');

        return $response;
    }

    // =========================================================================
    // Helper: detecta PHP CLI real
    // =========================================================================

    private function resolvePHPCli(): ?string
    {
        $phpBinary = PHP_BINARY;
        if (
            !str_contains($phpBinary, 'cgi')
            && !str_contains($phpBinary, 'fpm')
            && !str_contains($phpBinary, 'lsphp')
            && is_executable($phpBinary)
        ) {
            return $phpBinary;
        }

        foreach (self::PHP_CLI_CANDIDATES as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        $which = trim((string) shell_exec('which php 2>/dev/null'));
        if ($which !== '' && is_executable($which) && !str_contains($which, 'lsphp')) {
            return $which;
        }

        return null;
    }
}
