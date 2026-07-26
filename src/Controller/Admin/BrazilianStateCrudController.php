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
    /** Segundos entre heartbeats SSE (mantém a conexão viva em proxies) */
    private const HEARTBEAT_INTERVAL = 20;

    /**
     * Caminhos candidatos ao PHP CLI na Hostinger e em servidores Linux genéricos.
     * O primeiro que existir e for executável será usado.
     */
    private const PHP_CLI_CANDIDATES = [
        '/usr/local/php83/bin/php',
        '/usr/local/php82/bin/php',
        '/usr/local/php81/bin/php',
        '/usr/local/php80/bin/php',
        '/usr/bin/php83',
        '/usr/bin/php82',
        '/usr/bin/php81',
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

            $linkBase       = trim((string) $req->request->get('link_base_radares', ''));
            $linkReferencia = trim((string) $req->request->get('link_referencia_radares', ''));

            $state->setLinkBaseRadares($linkBase ?: null);
            $state->setLinkReferenciaRadares($linkReferencia ?: null);

            $this->em->flush();

            $this->addFlash('success', sprintf('Estado %s atualizado com sucesso.', $state->getUf()));

            return $this->redirectToRoute('admin_estados_index');
        }

        return $this->render('admin/estados/edit.html.twig', [
            'state' => $state,
        ]);
    }

    // =========================================================================
    // TELA DE LOG (página HTML)
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
    // SSE STREAM — detecta PHP CLI correto e executa o command em tempo real
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
            // ── Sem limite de execução e sem abortar ao fechar cliente ───
            set_time_limit(0);
            ignore_user_abort(true);

            // ── Limpa buffers do PHP/Apache/Nginx ─────────────────────
            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            /**
             * Envia uma linha ao cliente via SSE.
             * Remove quebras de linha internas para não quebrar o protocolo.
             */
            $send = static function (string $line, string $event = 'message') {
                $line = str_replace(["\r\n", "\r", "\n"], ' ', $line);
                if ($event !== 'message') {
                    echo 'event: ' . $event . "\n";
                }
                echo 'data: ' . $line . "\n\n";
                flush();
            };

            // ── Detecta o PHP CLI real (evita php-cgi / php-fpm) ───────────
            $phpBin = $this->resolvePHPCli();

            if ($phpBin === null) {
                $send('❌ Não foi possível localizar o PHP CLI no servidor.');
                $send('ℹ️  Tente adicionar o caminho correto em PHP_CLI_CANDIDATES no controller.');
                echo "event: done\ndata: 1\n\n";
                flush();
                return;
            }

            $send(sprintf(
                '🚀 Iniciando importação de <strong>%s</strong>%s…',
                $uf,
                $skipWaze ? ' <span class="badge-skip">(sem Waze)</span>' : ''
            ));
            $send('🔧 PHP CLI: ' . $phpBin);

            // ── Monta o comando ─────────────────────────────────────────
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

            // ── Abre o processo via proc_open (controle total de I/O) ───
            $descriptors = [
                0 => ['pipe', 'r'],   // stdin
                1 => ['pipe', 'w'],   // stdout
                2 => ['pipe', 'w'],   // stderr (redirecionado para stdout via 2>&1)
            ];

            $proc = proc_open($cmdStr, $descriptors, $pipes, $projectDir);

            if (!is_resource($proc)) {
                $send('❌ Falha ao iniciar o processo com proc_open.');
                echo "event: done\ndata: 1\n\n";
                flush();
                return;
            }

            // Fecha stdin imediatamente
            fclose($pipes[0]);

            // Coloca stdout em modo não-bloqueante
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);

            $buffer       = '';
            $lastHeartbeat = microtime(true);
            $heartbeatSec  = self::HEARTBEAT_INTERVAL;

            while (true) {
                $status = proc_get_status($proc);

                // Lê o que houver nos pipes (não-bloqueante)
                $chunk  = fread($pipes[1], 8192);
                $chunk2 = fread($pipes[2], 8192);

                if ($chunk !== false && $chunk !== '') {
                    $buffer .= $chunk;
                }
                if ($chunk2 !== false && $chunk2 !== '') {
                    $buffer .= $chunk2;
                }

                // Envia linha a linha
                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line   = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 1);
                    if (trim($line) !== '') {
                        $send(rtrim($line, "\r"));
                    }
                }

                // Heartbeat SSE para manter a conexão viva
                if ((microtime(true) - $lastHeartbeat) >= $heartbeatSec) {
                    echo ": heartbeat\n\n";
                    flush();
                    $lastHeartbeat = microtime(true);
                }

                // Verifica se o processo já terminou
                if (!$status['running']) {
                    break;
                }

                usleep(100_000); // 100 ms
            }

            // Drena o que sobrou nos pipes após o término
            $remaining  = stream_get_contents($pipes[1]);
            $remaining .= stream_get_contents($pipes[2]);
            $buffer    .= ($remaining !== false ? $remaining : '');

            while (($pos = strpos($buffer, "\n")) !== false) {
                $line   = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);
                if (trim($line) !== '') {
                    $send(rtrim($line, "\r"));
                }
            }
            if (trim($buffer) !== '') {
                $send(trim($buffer));
            }

            fclose($pipes[1]);
            fclose($pipes[2]);

            $exitCode = proc_close($proc);

            if ($exitCode === 0) {
                $send('✅ Importação concluída com sucesso! (exit 0)');
            } else {
                $send(sprintf('❌ Processo encerrado com código %d.', $exitCode));
            }

            // Sinal de fim para o EventSource no frontend
            echo "event: done\ndata: {$exitCode}\n\n";
            flush();
        });

        $response->headers->set('Content-Type',      'text/event-stream');
        $response->headers->set('Cache-Control',     'no-cache, no-store');
        $response->headers->set('X-Accel-Buffering', 'no');   // Nginx: desativa buffering
        $response->headers->set('Connection',        'keep-alive');

        return $response;
    }

    // =========================================================================
    // Helper: detecta PHP CLI real (evita php-cgi / php-fpm)
    // =========================================================================

    private function resolvePHPCli(): ?string
    {
        // 1. PHP_BINARY só é confiável se NÃO for CGI
        $phpBinary = PHP_BINARY;
        if (
            !str_contains($phpBinary, 'cgi')
            && !str_contains($phpBinary, 'fpm')
            && is_executable($phpBinary)
        ) {
            return $phpBinary;
        }

        // 2. Procura nos caminhos conhecidos da Hostinger
        foreach (self::PHP_CLI_CANDIDATES as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        // 3. Tenta `which php` como último recurso
        $which = trim((string) shell_exec('which php 2>/dev/null'));
        if ($which !== '' && is_executable($which)) {
            return $which;
        }

        return null;
    }
}
