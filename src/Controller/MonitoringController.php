<?php

namespace App\Controller;

use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Doctrine\DBAL\Connection;

#[Route('/monitoring', name: 'monitoring_')]
class MonitoringController extends AbstractController
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly Connection $db,
    ) {}

    /**
     * Recebe eventos de monitoramento do frontend (Web Vitals, erros JS, AJAX, sessão).
     */
    #[Route('/collect', name: 'collect', methods: ['POST'])]
    public function collect(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload) || empty($payload)) {
            return new JsonResponse(['ok' => false], 400);
        }

        $userId  = $this->getUser()?->getId();
        $ip      = $request->getClientIp();
        $ua      = substr($request->headers->get('User-Agent', ''), 0, 512);
        $session = $payload['session_id'] ?? null;
        $page    = substr($payload['page'] ?? $request->headers->get('Referer', ''), 0, 512);
        $type    = $payload['type'] ?? 'unknown';
        $data    = json_encode($payload['data'] ?? []);
        $ts      = new \DateTimeImmutable();

        // Persiste no banco (tabela criada via migração)
        try {
            $this->db->insert('monitoring_event', [
                'type'       => substr($type, 0, 64),
                'page'       => $page,
                'data'       => $data,
                'session_id' => substr((string)$session, 0, 64),
                'user_id'    => $userId,
                'ip'         => $ip,
                'user_agent' => $ua,
                'created_at' => $ts->format('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // Tabela ainda não migrada — só loga
            $this->logger->warning('monitoring_collect db error: ' . $e->getMessage());
        }

        // Loga erros JS e Web Vitals críticos no Symfony logger
        if (in_array($type, ['js_error', 'unhandled_rejection', 'ajax_error'], true)) {
            $this->logger->error('[monitoring] ' . $type, [
                'page'    => $page,
                'user_id' => $userId,
                'data'    => $payload['data'] ?? [],
            ]);
        } elseif ($type === 'web_vitals' && isset($payload['data']['lcp']) && $payload['data']['lcp'] > 4000) {
            $this->logger->warning('[monitoring] LCP crítico: ' . $payload['data']['lcp'] . 'ms', [
                'page' => $page,
                'user_id' => $userId,
            ]);
        }

        return new JsonResponse(['ok' => true]);
    }

    /**
     * Dashboard de monitoramento — apenas admin.
     */
    #[Route('/dashboard', name: 'dashboard')]
    public function dashboard(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $period = $request->query->getInt('days', 7);
        $type   = $request->query->get('type', '');

        try {
            $qb = $this->db->createQueryBuilder()
                ->select('*')
                ->from('monitoring_event')
                ->where('created_at >= :since')
                ->setParameter('since', (new \DateTimeImmutable("-{$period} days"))->format('Y-m-d H:i:s'))
                ->orderBy('created_at', 'DESC')
                ->setMaxResults(500);

            if ($type) {
                $qb->andWhere('type = :type')->setParameter('type', $type);
            }

            $events = $qb->fetchAllAssociative();

            // Agregações
            $byType   = array_count_values(array_column($events, 'type'));
            $byPage   = array_count_values(array_column($events, 'page'));
            arsort($byType); arsort($byPage);
            $topPages = array_slice($byPage, 0, 10, true);

            // Médias de Web Vitals
            $vitals = ['lcp' => [], 'fid' => [], 'cls' => [], 'ttfb' => [], 'inp' => []];
            foreach ($events as $ev) {
                if ($ev['type'] !== 'web_vitals') continue;
                $d = json_decode($ev['data'], true);
                foreach ($vitals as $k => &$arr) {
                    if (isset($d[$k]) && is_numeric($d[$k])) $arr[] = (float)$d[$k];
                }
            }
            $vitalsAvg = [];
            foreach ($vitals as $k => $arr) {
                $vitalsAvg[$k] = count($arr) ? round(array_sum($arr) / count($arr), 1) : null;
            }

            // Erros distintos (mensagem + página)
            $errors = array_filter($events, fn($e) => in_array($e['type'], ['js_error','unhandled_rejection','ajax_error']));

        } catch (\Throwable) {
            $events = $errors = [];
            $byType = $topPages = $vitalsAvg = [];
        }

        return $this->render('monitoring/dashboard.html.twig', [
            'events'     => $events,
            'byType'     => $byType,
            'topPages'   => $topPages,
            'vitalsAvg'  => $vitalsAvg,
            'errors'     => array_values($errors),
            'period'     => $period,
            'filterType' => $type,
        ]);
    }
}
