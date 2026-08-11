<?php
declare(strict_types=1);

namespace App\Service;

use App\Service\DTO\RadarKpiDto;
use App\Service\DTO\PostoKpiDto;
use App\Service\DTO\SolicitacaoKpiDto;
use Doctrine\DBAL\Connection;

class DashboardDataProvider
{
    public function __construct(
        private readonly Connection $db,
        private readonly PostoStatsService $postoStats,
        private readonly RadarStatsService $radarStats,
        private readonly DashboardService $dashService,
        private readonly DashboardCacheService $cache,
    ) {
    }

    public function getRadarKpis(?array $allowedUfs): RadarKpiDto
    {
        $cacheKey = 'radar_kpis_' . ($allowedUfs ? implode('_', $allowedUfs) : 'all');
        return $this->cache->getCached($cacheKey, function () use ($allowedUfs) {
            $stats = $this->radarStats->getKpis($allowedUfs);
            return new RadarKpiDto(
                total: (int) ($stats['total'] ?? 0),
                aprovados: (int) ($stats['aprovados'] ?? 0),
                reprovados: (int) ($stats['reprovados'] ?? 0),
                vencidos: (int) ($stats['vencidos'] ?? 0),
                vencendo: (int) ($stats['vencendo'] ?? 0),
                comWaze: (int) ($stats['comWaze'] ?? 0),
                pctWaze: (float) ($stats['pctWaze'] ?? 0.0),
            );
        }, 300);
    }

    public function getRadarPorUf(?array $allowedUfs): array
    {
        $key = 'radar_por_uf_' . ($allowedUfs ? implode('_', $allowedUfs) : 'all');
        return $this->cache->getCached($key, fn() => $this->radarStats->getPorUf($allowedUfs), 600);
    }

    public function getRadarResultado(?array $allowedUfs): array
    {
        $key = 'radar_resultado_' . ($allowedUfs ? implode('_', $allowedUfs) : 'all');
        return $this->cache->getCached($key, fn() => $this->radarStats->getPorResultado($allowedUfs), 600);
    }

    public function getRadarMensais(?array $allowedUfs): array
    {
        $key = 'radar_mensais_' . ($allowedUfs ? implode('_', $allowedUfs) : 'all');
        return $this->cache->getCached($key, fn() => $this->radarStats->getVerificacoesMensais($allowedUfs), 600);
    }

    public function getRadarCobertura(?array $allowedUfs): array
    {
        $key = 'radar_cobertura_' . ($allowedUfs ? implode('_', $allowedUfs) : 'all');
        return $this->cache->getCached($key, fn() => $this->radarStats->getCoberturaWazePorUf($allowedUfs), 600);
    }

    public function getRadarSemWaze(?array $allowedUfs, int $limit = 8): array
    {
        $key = 'radar_sem_waze_' . ($allowedUfs ? implode('_', $allowedUfs) : 'all') . '_' . $limit;
        return $this->cache->getCached($key, fn() => $this->radarStats->getSemWazePrioritarios($allowedUfs, $limit), 300);
    }

    public function getPostoKpis(?array $allowedUfs): PostoKpiDto
{
    $cacheKey = 'posto_kpis_' . ($allowedUfs ? implode('_', $allowedUfs) : 'all');
    return $this->cache->getCached($cacheKey, function () use ($allowedUfs) {
        $stats = $this->postoStats->getKpis($allowedUfs);
        return new PostoKpiDto(
            total: (int) ($stats['total'] ?? 0),
            comWaze: (int) ($stats['comWaze'] ?? 0),
            semWaze: (int) ($stats['semWaze'] ?? 0),
            pct: (float) ($stats['pct'] ?? 0.0),
        );
    }, 600);
    }

    public function getPostoAtividade(?array $allowedUfs): array
    {
        $key = 'posto_atividade_' . ($allowedUfs ? implode('_', $allowedUfs) : 'all');
        return $this->cache->getCached($key, fn() => $this->postoStats->getAtividadeDiaria($allowedUfs), 600);
    }

    public function getEscolaKpis(): array
    {
        return $this->cache->getCached('escola_kpis', fn() => $this->dashService->getEscolaKpis(), 3600);
    }

    public function getSolicitacaoKpis(): array
    {
        return $this->cache->getCached('solic_kpis', fn() => $this->dashService->getSolicitacaoKpis(), 300);
    }

    public function getSolicitacoesDiarias(): array
    {
        return $this->cache->getCached('solic_diarias', fn() => $this->dashService->getSolicitacoesDiarias(), 600);
    }

    public function getEstadosAtivos(): int
    {
        return $this->cache->getCached('estados_ativos', fn() => $this->dashService->getEstadosAtivos(), 3600);
    }

    public function getUsuarioKpis(): array
    {
        return $this->cache->getCached('usuario_kpis', fn() => $this->dashService->getUsuarioKpis(), 600);
    }
}