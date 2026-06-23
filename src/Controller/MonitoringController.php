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
     * Recebe eventos de monitoramento do frontend.
     */
    #[Route('/collect', name: 'collect', methods: ['POST'])]
    public function collect(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload) || empty($payload)) {
            return new JsonResponse(['ok' => false], 400);
        }

        $ip = $request->getClientIp();
        $ua = substr($request->headers->get('User-Agent', ''), 0, 512);
        $userId = $this->getUser()?->getId();

        if (isset($payload['batch']) && is_array($payload['batch'])) {
            $events      = $payload['batch'];
            $rootSession = $payload['session_id'] ?? null;
            $rootPage    = substr($payload['page'] ?? $request->headers->get('Referer', ''), 0, 512);
            foreach ($events as &$ev) {
                if (empty($ev['session_id'])) $ev['session_id'] = $rootSession;
                if (empty($ev['page']))       $ev['page']       = $rootPage;
            }
            unset($ev);
        } else {
            $events = [$payload];
        }

        $inserted = 0;
        foreach ($events as $ev) {
            $type    = substr((string)($ev['type'] ?? 'unknown'), 0, 64);
            $page    = substr((string)($ev['page']    ?? $request->headers->get('Referer', '')), 0, 512);
            $session = substr((string)($ev['session_id'] ?? ''), 0, 64);
            $data    = json_encode($ev['data'] ?? []);
            $ts = isset($ev['ts']) && is_numeric($ev['ts'])
                ? (new \DateTimeImmutable())->setTimestamp((int)($ev['ts'] / 1000))->format('Y-m-d H:i:s')
                : (new \DateTimeImmutable())->format('Y-m-d H:i:s');

            try {
                $this->db->insert('monitoring_event', [
                    'type'       => $type,
                    'page'       => $page,
                    'data'       => $data,
                    'session_id' => $session,
                    'user_id'    => $userId,
                    'ip'         => $ip,
                    'user_agent' => $ua,
                    'created_at' => $ts,
                ]);
                $inserted++;
            } catch (\Throwable $e) {
                $this->logger->warning('monitoring_collect db error: ' . $e->getMessage());
            }

            if (in_array($type, ['js_error', 'unhandled_rejection', 'ajax_error'], true)) {
                $this->logger->error('[monitoring] ' . $type, [
                    'page'    => $page,
                    'user_id' => $userId,
                    'data'    => $ev['data'] ?? [],
                ]);
            } elseif ($type === 'web_vital_critical') {
                $d = $ev['data'] ?? [];
                $this->logger->warning('[monitoring] vital crítico: ' . ($d['name'] ?? '?') . ' = ' . ($d['value'] ?? '?'), [
                    'page'    => $page,
                    'user_id' => $userId,
                    'rating'  => $d['rating'] ?? null,
                ]);
            }
        }

        return new JsonResponse(['ok' => true, 'inserted' => $inserted]);
    }

    /**
     * Dashboard de monitoramento — apenas admin.
     * #7 – Adicionada paginação por cursor via parâmetro `offset`.
     */
    #[Route('/dashboard', name: 'dashboard')]
    public function dashboard(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $period  = $request->query->getInt('days', 7);
        $type    = $request->query->get('type', '');
        $perPage = 100;
        $offset  = $request->query->getInt('offset', 0);

        try {
            $qb = $this->db->createQueryBuilder()
                ->select('*')
                ->from('monitoring_event')
                ->where('created_at >= :since')
                ->setParameter('since', (new \DateTimeImmutable("-{$period} days"))->format('Y-m-d H:i:s'))
                ->orderBy('created_at', 'DESC')
                ->setMaxResults($perPage)
                ->setFirstResult($offset);

            if ($type) {
                $qb->andWhere('type = :type')->setParameter('type', $type);
            }

            $events = $qb->fetchAllAssociative();

            // Total para paginação
            $totalQb = $this->db->createQueryBuilder()
                ->select('COUNT(*)')
                ->from('monitoring_event')
                ->where('created_at >= :since')
                ->setParameter('since', (new \DateTimeImmutable("-{$period} days"))->format('Y-m-d H:i:s'));
            if ($type) {
                $totalQb->andWhere('type = :type')->setParameter('type', $type);
            }
            $total = (int) $totalQb->fetchOne();

            $byType = array_count_values(array_column($events, 'type'));
            $byPage = array_count_values(array_column($events, 'page'));
            arsort($byType);
            arsort($byPage);
            $topPages = array_slice($byPage, 0, 10, true);

            $vitals = ['lcp' => [], 'fcp' => [], 'cls' => [], 'ttfb' => [], 'inp' => []];
            foreach ($events as $ev) {
                if (!in_array($ev['type'], ['web_vitals', 'page_performance', 'web_vital_critical'], true)) {
                    continue;
                }
                $d = json_decode($ev['data'], true) ?? [];
                if ($ev['type'] === 'web_vital_critical') {
                    $name = strtolower($d['name'] ?? '');
                    if (isset($vitals[$name]) && is_numeric($d['value'] ?? null)) {
                        $vitals[$name][] = (float)$d['value'];
                    }
                    continue;
                }
                foreach ($vitals as $k => &$arr) {
                    if (isset($d[$k]) && is_numeric($d[$k])) {
                        $arr[] = (float)$d[$k];
                    }
                }
                unset($arr);
            }

            $vitalsAvg = [];
            foreach ($vitals as $k => $arr) {
                $vitalsAvg[$k] = count($arr) ? round(array_sum($arr) / count($arr), 1) : null;
            }

            $errors     = array_filter($events, fn($e) => in_array($e['type'], ['js_error', 'unhandled_rejection', 'ajax_error']));
            $errorCount = count($errors);

        } catch (\Throwable) {
            $events = $errors = [];
            $byType = $topPages = $vitalsAvg = [];
            $errorCount = $total = 0;
        }

        return $this->render('monitoring/dashboard.html.twig', [
            'events'     => $events,
            'byType'     => $byType,
            'topPages'   => $topPages,
            'vitalsAvg'  => $vitalsAvg,
            'errors'     => array_values($errors),
            'errorCount' => $errorCount,
            'period'     => $period,
            'filterType' => $type,
            'perPage'    => $perPage,
            'offset'     => $offset,
            'total'      => $total ?? 0,
            'prevOffset' => max(0, $offset - $perPage),
            'nextOffset' => ($offset + $perPage < ($total ?? 0)) ? $offset + $perPage : null,
        ]);
    }
}
