<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\PostoStatsService;
use App\Service\RadarStatsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/cobertura', name: 'cobertura_')]
final class CoberturaController extends AbstractController
{
    public function __construct(
        private readonly RadarStatsService $radarStats,
        private readonly PostoStatsService $postoStats,
    ) {}

    #[Route('/radares', name: 'radar')]
    public function radar(): Response
    {
        /** @var User|null $user */
        $user       = $this->getUser();
        $allowedUfs = $user?->getUfsForQuery();

        $porUf      = $this->radarStats->getCoberturaWazePorUf($allowedUfs);
        $semWaze    = $this->radarStats->getSemWazePrioritarios($allowedUfs, 50);
        $kpis       = $this->radarStats->getKpis($allowedUfs);

        // classifica UFs por cobertura
        $criticas   = array_filter($porUf, fn($r) => (float)$r['pct'] < 25);
        $parciais   = array_filter($porUf, fn($r) => (float)$r['pct'] >= 25 && (float)$r['pct'] < 75);
        $boas       = array_filter($porUf, fn($r) => (float)$r['pct'] >= 75);

        return $this->render('cobertura/radar.html.twig', [
            'porUf'    => $porUf,
            'semWaze'  => $semWaze,
            'kpis'     => $kpis,
            'criticas' => array_values($criticas),
            'parciais' => array_values($parciais),
            'boas'     => array_values($boas),
        ]);
    }

    #[Route('/postos', name: 'posto')]
    public function posto(): Response
    {
        /** @var User|null $user */
        $user       = $this->getUser();
        $allowedUfs = $user?->getUfsForQuery();

        $porUf      = $this->postoStats->getCoberturaPorUf($allowedUfs);
        $kpis       = $this->postoStats->getKpis($allowedUfs);

        $criticas   = array_filter($porUf, fn($r) => (float)$r['pct'] < 25);
        $parciais   = array_filter($porUf, fn($r) => (float)$r['pct'] >= 25 && (float)$r['pct'] < 75);
        $boas       = array_filter($porUf, fn($r) => (float)$r['pct'] >= 75);

        return $this->render('cobertura/posto.html.twig', [
            'porUf'    => $porUf,
            'kpis'     => $kpis,
            'criticas' => array_values($criticas),
            'parciais' => array_values($parciais),
            'boas'     => array_values($boas),
        ]);
    }
}
