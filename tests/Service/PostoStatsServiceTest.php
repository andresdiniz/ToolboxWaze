<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\PostoStatsService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PostoStatsServiceTest extends KernelTestCase
{
    private PostoStatsService $service;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->service = static::getContainer()->get(PostoStatsService::class);
    }

    public function testGetKpisReturnsExpectedKeys(): void
    {
        $kpis = $this->service->getKpis(null);

        $this->assertArrayHasKey('total',    $kpis);
        $this->assertArrayHasKey('comWaze',  $kpis);
        $this->assertArrayHasKey('semWaze',  $kpis);
        $this->assertArrayHasKey('pct',      $kpis);
        $this->assertArrayHasKey('mesAtual', $kpis);
        $this->assertArrayHasKey('porUf',    $kpis);
    }

    public function testSemWazeConsistency(): void
    {
        $kpis = $this->service->getKpis(null);
        $this->assertSame($kpis['total'], $kpis['comWaze'] + $kpis['semWaze']);
    }

    public function testPctBetween0And100(): void
    {
        $kpis = $this->service->getKpis(null);
        $this->assertGreaterThanOrEqual(0.0,   (float) $kpis['pct']);
        $this->assertLessThanOrEqual(100.0, (float) $kpis['pct']);
    }

    public function testGetCoberturaPorUfReturnsArray(): void
    {
        $rows = $this->service->getCoberturaPorUf(null);
        $this->assertIsArray($rows);
    }

    public function testCoberturaPorUfHasExpectedKeys(): void
    {
        $rows = $this->service->getCoberturaPorUf(null);
        if (empty($rows)) {
            $this->markTestSkipped('Nenhum dado de UF no banco de testes.');
        }
        $row = $rows[0];
        $this->assertArrayHasKey('uf',       $row);
        $this->assertArrayHasKey('total',    $row);
        $this->assertArrayHasKey('com_waze', $row);
        $this->assertArrayHasKey('sem_waze', $row);
        $this->assertArrayHasKey('pct',      $row);
    }

    public function testGetAtividadeDiariaReturnsArray(): void
    {
        $rows = $this->service->getAtividadeDiaria();
        $this->assertIsArray($rows);
    }

    public function testExportCsvHasHeaderRow(): void
    {
        $csv    = $this->service->exportCsv(null, []);
        $lines  = explode("\n", ltrim($csv, "\xEF\xBB\xBF"));
        $header = $lines[0] ?? '';
        $this->assertStringContainsString('cnpj',         $header);
        $this->assertStringContainsString('razao_social', $header);
        $this->assertStringContainsString('waze_link',    $header);
    }

    public function testExportCsvSemWazeFilterWorks(): void
    {
        $csv = $this->service->exportCsv(null, ['sem_waze' => true]);
        $this->assertIsString($csv);
        $this->assertStringContainsString(';', $csv);
    }

    public function testFindDuplicateHazardIdReturnNullForNonExistent(): void
    {
        $result = $this->service->findDuplicateHazardId(999999999, 0);
        $this->assertNull($result);
    }
}
