<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\User;
use App\Service\CoberturaRankingService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Cache\CacheInterface;

final class CoberturaRankingServiceTest extends TestCase
{
    public function testPercentualPonderadoEManuaisForaDoDenominador(): void
    {
        $rows = CoberturaRankingService::estados([
            ['uf'=>'MG','tipo'=>'radares','total'=>100,'elegiveis'=>100,'com_waze'=>10],
            ['uf'=>'MG','tipo'=>'saude','total'=>2,'elegiveis'=>2,'com_waze'=>2],
            ['uf'=>'MG','tipo'=>'manuais','total'=>20,'elegiveis'=>0,'com_waze'=>0],
            ['uf'=>'SP','tipo'=>'escolas','total'=>10,'elegiveis'=>10,'com_waze'=>10],
        ], 'percentual');
        self::assertSame('SP', $rows[0]['uf']);
        self::assertSame(122, $rows[1]['total']);
        self::assertSame(102, $rows[1]['elegiveis']);
        self::assertSame(90, $rows[1]['pendentes']);
        self::assertSame(11.76, $rows[1]['pct']);
    }

    public function testSomenteManuaisNaoTemPercentual(): void
    {
        $rows = CoberturaRankingService::estados([['uf'=>'AC','tipo'=>'manuais','total'=>2,'elegiveis'=>0,'com_waze'=>0]], 'percentual');
        self::assertNull($rows[0]['pct']);
    }

    public function testPermissoesDeCategorias(): void
    {
        $service = new CoberturaRankingService($this->createMock(Connection::class), $this->createMock(CacheInterface::class));
        $user = (new User())->setPermissions([User::PERMISSION_SAUDE]);
        self::assertSame(['saude'=>'Saúde'], $service->tipos($user));
    }
}
