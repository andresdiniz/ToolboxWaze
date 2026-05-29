<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Trait\AccessControlTrait;
use App\Entity\User;
use App\Service\PostoStatsService;
use App\Service\RadarStatsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/cobertura', name: 'cobertura_')]
#[IsGranted('ROLE_USER')]
final class CoberturaController extends AbstractController
{
    use AccessControlTrait;

    public function __construct(
        private readonly RadarStatsService $radarStats,
        private readonly PostoStatsService $postoStats,
    ) {}

    #[Route('/radares', name: 'radar')]
    public function radar(): Response
    {
        $this->requirePermission(User::PERMISSION_RADARES);

        /** @var User|null $user */
        $user       = $this->getUser();
        $allowedUfs = $user?->getUfsForQuery();

        $porUf    = $this->radarStats->getCoberturaWazePorUf($allowedUfs);
        $semWaze  = $this->radarStats->getSemWazePrioritarios($allowedUfs, 50);
        $kpis     = $this->radarStats->getKpis($allowedUfs);
        $porUfAll = $this->radarStats->getPorUf($allowedUfs);

        $criticas = array_values(array_filter($porUf, fn($r) => (float)$r['pct'] <  25));
        $parciais = array_values(array_filter($porUf, fn($r) => (float)$r['pct'] >= 25 && (float)$r['pct'] < 75));
        $boas     = array_values(array_filter($porUf, fn($r) => (float)$r['pct'] >= 75));

        $chartLabels  = array_column($porUf, 'uf');
        $chartComWaze = array_map('intval', array_column($porUf, 'com_waze'));
        $chartSemWaze = array_map('intval', array_column($porUf, 'sem_waze'));
        $chartPct     = array_map('floatval', array_column($porUf, 'pct'));

        return $this->render('cobertura/radar.html.twig', [
            'porUf'        => $porUf,
            'porUfAll'     => $porUfAll,
            'semWaze'      => $semWaze,
            'kpis'         => $kpis,
            'criticas'     => $criticas,
            'parciais'     => $parciais,
            'boas'         => $boas,
            'chartLabels'  => $chartLabels,
            'chartComWaze' => $chartComWaze,
            'chartSemWaze' => $chartSemWaze,
            'chartPct'     => $chartPct,
            'allowedUfs'   => $allowedUfs,
        ]);
    }

    #[Route('/postos', name: 'posto')]
    public function posto(): Response
    {
        $this->requirePermission(User::PERMISSION_POSTOS);

        /** @var User|null $user */
        $user       = $this->getUser();
        $allowedUfs = $user?->getUfsForQuery();

        $porUf = $this->postoStats->getCoberturaPorUf($allowedUfs);
        $kpis  = $this->postoStats->getKpis($allowedUfs);

        $criticas = array_values(array_filter($porUf, fn($r) => (float)$r['pct'] <  25));
        $parciais = array_values(array_filter($porUf, fn($r) => (float)$r['pct'] >= 25 && (float)$r['pct'] < 75));
        $boas     = array_values(array_filter($porUf, fn($r) => (float)$r['pct'] >= 75));

        $chartLabels  = array_column($porUf, 'uf');
        $chartComWaze = array_map('intval', array_column($porUf, 'com_waze'));
        $chartSemWaze = array_map('intval', array_column($porUf, 'sem_waze'));
        $chartPct     = array_map('floatval', array_column($porUf, 'pct'));

        return $this->render('cobertura/posto.html.twig', [
            'porUf'        => $porUf,
            'kpis'         => $kpis,
            'criticas'     => $criticas,
            'parciais'     => $parciais,
            'boas'         => $boas,
            'chartLabels'  => $chartLabels,
            'chartComWaze' => $chartComWaze,
            'chartSemWaze' => $chartSemWaze,
            'chartPct'     => $chartPct,
            'allowedUfs'   => $allowedUfs,
        ]);
    }
}
