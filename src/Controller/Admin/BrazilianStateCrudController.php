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
use Symfony\Component\Process\Process;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/estados', name: 'admin_estados_')]
#[IsGranted('ROLE_ADMIN')]
final class BrazilianStateCrudController extends AbstractController
{
    /** Intervalo (em segundos) do heartbeat SSE para evitar timeout de proxy */
    private const HEARTBEAT_INTERVAL = 20;

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
    // SSE STREAM — executa o command e envia linhas em tempo real
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
        $php        = PHP_BINARY;
        $console    = $this->projectDir . '/bin/console';

        $cmd = [$php, $console, 'app:import-radares', '--uf=' . $uf, '--env=prod', '--no-interaction'];
        if ($skipWaze) {
            $cmd[] = '--skip-waze';
        }

        // Timeout do processo: 10 min (suficiente para qualquer UF)
        $process    = new Process($cmd, $this->projectDir, null, null, 600);
        $projectDir = $this->projectDir;

        $response = new StreamedResponse(function () use ($process, $uf, $skipWaze) {
            // ── Sem limite de tempo para esta requisição ──────────────
            set_time_limit(0);
            ignore_user_abort(true);

            // ── Limpa qualquer buffer de saída ────────────────────────
            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            $heartbeatInterval = self::HEARTBEAT_INTERVAL;
            $lastHeartbeat     = time();

            /**
             * Envia uma linha SSE ao cliente.
             * Quebras internas são normalizadas para não quebrar o protocolo.
             */
            $send = static function (string $line, string $event = 'message') {
                $line = str_replace(["\r\n", "\r", "\n"], ' ', $line);
                if ($event !== 'message') {
                    echo 'event: ' . $event . "\n";
                }
                echo 'data: ' . $line . "\n\n";
                flush();
            };

            /**
             * Envia um comentário SSE (heartbeat) para manter a conexão viva.
             * Comentários SSE começam com ':' e são ignorados pelo EventSource.
             */
            $heartbeat = static function () {
                echo ": heartbeat\n\n";
                flush();
            };

            $send(sprintf(
                '🚀 Iniciando importação de <strong>%s</strong>%s…',
                $uf,
                $skipWaze ? ' <span class="badge-skip">(sem Waze)</span>' : ''
            ));

            $process->start();

            $buffer = '';

            while ($process->isRunning()) {
                // Lê o que houver disponível (não-bloqueante)
                $chunk = $process->getIncrementalOutput();
                $err   = $process->getIncrementalErrorOutput();

                $buffer .= $chunk;
                if ($err !== '') {
                    $buffer .= $err;
                }

                // Envia linha a linha
                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line   = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 1);
                    if (trim($line) !== '') {
                        $send($line);
                    }
                }

                // Heartbeat periódico para evitar timeout do proxy
                if ((time() - $lastHeartbeat) >= $heartbeatInterval) {
                    $heartbeat();
                    $lastHeartbeat = time();
                }

                usleep(100_000); // 100 ms — não desperdiça CPU
            }

            // Drena o que sobrou no buffer após o processo terminar
            $chunk  = $process->getIncrementalOutput();
            $err    = $process->getIncrementalErrorOutput();
            $buffer .= $chunk . $err;

            while (($pos = strpos($buffer, "\n")) !== false) {
                $line   = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);
                if (trim($line) !== '') {
                    $send($line);
                }
            }
            if (trim($buffer) !== '') {
                $send($buffer);
            }

            $exitCode = $process->getExitCode() ?? 1;

            if ($exitCode === 0) {
                $send('✅ Importação concluída com sucesso! (exit 0)');
            } else {
                $send(sprintf('❌ Processo encerrado com código %d.', $exitCode));
            }

            // Sinal de fim para o JS — fecha o EventSource no cliente
            echo "event: done\ndata: {$exitCode}\n\n";
            flush();
        });

        $response->headers->set('Content-Type',     'text/event-stream');
        $response->headers->set('Cache-Control',    'no-cache, no-store');
        $response->headers->set('X-Accel-Buffering','no');   // Nginx: desativa buffering
        $response->headers->set('Connection',       'keep-alive');

        return $response;
    }
}
