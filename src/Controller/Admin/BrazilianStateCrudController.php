<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\BrazilianState;
use App\Message\ImportRadaresMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/estados', name: 'admin_estados_')]
#[IsGranted('ROLE_ADMIN')]
final class BrazilianStateCrudController extends AbstractController
{
    private const MAX_POLL_SECONDS   = 600;
    private const HEARTBEAT_INTERVAL = 15;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface    $bus,
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
            'state'     => $state,
            'skip_waze' => $skipWaze,
            'start_url' => $this->generateUrl('admin_estados_importar_start', [
                'id'        => $id,
                'skip_waze' => $skipWaze ? '1' : '0',
            ]),
        ]);
    }

    // =========================================================================
    // START — despacha mensagem para fila Doctrine, retorna token JSON
    // =========================================================================

    #[Route('/{id}/importar/start', name: 'importar_start', requirements: ['id' => '\\d+'])]
    public function importarStart(int $id, Request $req): JsonResponse
    {
        try {
            $state = $this->em->find(BrazilianState::class, $id);
            if (!$state) {
                return $this->json(['error' => 'Estado não encontrado.'], 404);
            }

            $skipWaze = (bool) ($req->query->get('skip_waze') ?? $req->request->get('skip_waze', '0'));
            $uf       = $state->getUf();

            $logDir = $this->projectDir . '/var/log';
            if (!is_dir($logDir)) {
                @mkdir($logDir, 0755, true);
            }

            $token   = bin2hex(random_bytes(12));
            $logFile = sprintf('%s/import_%s_%s.log', $logDir, $uf, $token);

            $this->bus->dispatch(new ImportRadaresMessage(
                uf:       $uf,
                skipWaze: $skipWaze,
                logFile:  $logFile,
                token:    $token,
            ));

            return $this->json([
                'ok'       => true,
                'token'    => $token,
                'uf'       => $uf,
                'log_file' => basename($logFile),
                'poll_url' => $this->generateUrl('admin_estados_importar_poll', ['token' => $token]),
                'mode'     => 'messenger',
            ]);

        } catch (\Throwable $e) {
            return $this->json([
                'error' => $e->getMessage(),
                'class' => get_class($e),
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
            ], 500);
        }
    }

    // =========================================================================
    // POLL — SSE que lê o log e envia linha a linha
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

        $response = new StreamedResponse(function () use ($logGlob) {
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

            // Aguarda o handler criar o arquivo de log (cron/consumer leva alguns segundos)
            $waited  = 0;
            $logFile = null;
            while ($waited < 60) {
                $files = glob($logGlob);
                if (!empty($files)) {
                    $logFile = $files[0];
                    break;
                }
                if ($waited === 0) {
                    $send('⏳ Aguardando o worker processar a fila… (pode levar até 60s dependendo do cron)');
                }
                sleep(2);
                $waited += 2;
            }

            if ($logFile === null) {
                $send('❌ Arquivo de log não encontrado após 60s.');
                $send('ℹ️  Verifique se o cron `messenger:consume async` está configurado na Hostinger.');
                $send('ℹ️  Comando: php bin/console messenger:consume async --limit=1 --env=prod');
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

            $send('🚀 Mensagem enfileirada — worker processando via Messenger/Doctrine…');
            $send('📝 Log: ' . basename((string) $logFile));

            $elapsed       = 0;
            $lastHeartbeat = microtime(true);
            $buffer        = '';

            while ($elapsed < self::MAX_POLL_SECONDS) {
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

                if ((microtime(true) - $lastHeartbeat) >= self::HEARTBEAT_INTERVAL) {
                    echo ": heartbeat\n\n";
                    flush();
                    $lastHeartbeat = microtime(true);
                }

                if (file_exists($doneFile)) {
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
                    $send('❌ Processo encerrou com erro.');
                    echo "event: done\ndata: 1\n\n";
                    flush();
                    return;
                }

                usleep(500_000);
                $elapsed += 0.5;
            }

            fclose($fh);
            $send('⏰ Timeout de poll (' . self::MAX_POLL_SECONDS . 's). Processo pode ainda estar rodando.');
            $send('ℹ️  Verifique var/log/' . basename((string) $logFile));
            echo "event: done\ndata: 1\n\n";
            flush();
        });

        $response->headers->set('Content-Type',      'text/event-stream');
        $response->headers->set('Cache-Control',     'no-cache, no-store');
        $response->headers->set('X-Accel-Buffering', 'no');
        $response->headers->set('Connection',        'keep-alive');

        return $response;
    }
}
