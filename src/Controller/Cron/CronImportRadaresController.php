<?php

declare(strict_types=1);

namespace App\Controller\Cron;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Endpoint de cron para importar radares de todos os estados (ou UFs específicas).
 *
 * Protegido por CRON_SECRET definido em .env / .env.local:
 *   CRON_SECRET=sua_chave_super_secreta_aqui
 *
 * Uso (wget na Hostinger):
 *   wget -q -O - --timeout=7200 \
 *     "https://wazetoolbox.acheireviews.com.br/cron/import-radares?secret=SUA_CHAVE"
 *
 * Parâmetros opcionais:
 *   ?uf=SP,RJ,MG     — filtra por UFs (separadas por vírgula)
 *   ?skip_waze=1     — pula a etapa de links Waze
 *   ?skip_notify=1   — pula notificações por e-mail
 *
 * O endpoint retorna imediatamente com {"ok":true,"pid":...} e o processo
 * continua em background. O log é gravado em var/log/cron_import_YYYYMMDD_HHii.log
 */
#[Route('/cron/import-radares', name: 'cron_import_radares', methods: ['GET', 'POST'])]
final class CronImportRadaresController extends AbstractController
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        #[Autowire('%env(CRON_SECRET)%')]
        private readonly string $cronSecret,
    ) {}

    public function __invoke(Request $req): JsonResponse
    {
        // ── Autenticação por secret ──────────────────────────────────────────
        $provided = (string) ($req->query->get('secret') ?? $req->request->get('secret', ''));

        if ($this->cronSecret === '' || !hash_equals($this->cronSecret, $provided)) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        // ── Parâmetros ───────────────────────────────────────────────────────
        $rawUf      = trim((string) ($req->query->get('uf') ?? ''));
        $skipWaze   = (bool) ($req->query->get('skip_waze',   '0'));
        $skipNotify = (bool) ($req->query->get('skip_notify', '0'));

        // ── Monta o comando ──────────────────────────────────────────────────
        $php     = PHP_BINARY;
        $console = $this->projectDir . '/bin/console';
        $logDir  = $this->projectDir . '/var/log';

        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }

        $timestamp = date('Ymd_Hi');
        $logFile   = sprintf('%s/cron_import_%s.log', $logDir, $timestamp);

        // Constrói args
        $args = ['app:import-radares', '--env=prod'];

        if ($rawUf !== '') {
            foreach (explode(',', $rawUf) as $uf) {
                $uf = strtoupper(trim($uf));
                if ($uf !== '') {
                    $args[] = '--uf=' . escapeshellarg($uf);
                }
            }
        }

        if ($skipWaze) {
            $args[] = '--skip-waze';
        }

        if ($skipNotify) {
            $args[] = '--skip-notify';
        }

        $cmd = sprintf(
            '%s %s %s >> %s 2>&1',
            escapeshellcmd($php),
            escapeshellarg($console),
            implode(' ', $args),
            escapeshellarg($logFile)
        );

        // ── Executa em background (non-blocking) ─────────────────────────────
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', '/dev/null', 'a'],
            2 => ['file', '/dev/null', 'a'],
        ];

        $proc = proc_open(
            'nohup ' . $cmd . ' &',
            $descriptors,
            $pipes,
            $this->projectDir
        );

        $pid = null;
        if (is_resource($proc)) {
            $status = proc_get_status($proc);
            $pid    = $status['pid'] ?? null;
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            proc_close($proc);
        }

        return $this->json([
            'ok'       => true,
            'pid'      => $pid,
            'log_file' => basename($logFile),
            'uf'       => $rawUf ?: 'todos',
            'skip_waze'   => $skipWaze,
            'skip_notify' => $skipNotify,
            'started_at'  => date('c'),
        ]);
    }
}
