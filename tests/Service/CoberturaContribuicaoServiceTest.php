<?php

declare(strict_types=1);
namespace App\Tests\Service;

use App\Service\CoberturaContribuicaoService as Journal;
use PHPUnit\Framework\TestCase;

final class CoberturaContribuicaoServiceTest extends TestCase
{
    public function testRepeticaoNaoGeraEvento(): void
    {
        self::assertNull(Journal::acao('linkWaze','https://waze.com/a','https://waze.com/a'));
        self::assertNull(Journal::acao('status','confirmado','confirmado'));
    }
    public function testReservaNaoGeraContribuicao(): void
    {
        self::assertNull(Journal::acao('status',null,'reservado'));
        self::assertNull(Journal::acao('updatedAt','ontem','hoje'));
    }
    public function testClassificacaoDeVinculos(): void
    {
        self::assertSame('cadastro_vinculo',Journal::acao('linkWaze',null,'https://waze.com/a'));
        self::assertSame('manutencao_vinculo',Journal::acao('waze_link','a','b'));
        self::assertSame('remocao_vinculo',Journal::acao('link_area_escolar','a',null));
        self::assertSame('confirmacao',Journal::acao('status','reservado','confirmado'));
    }
}
