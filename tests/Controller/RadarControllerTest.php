<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Testes funcionais para RadarController.
 * #6 — Cobertura das rotas críticas de listagem, edição e link Waze.
 */
final class RadarControllerTest extends WebTestCase
{
    // ── Acesso anônimo ─────────────────────────────────────────────────

    public function testIndexRedirectsWhenNotAuthenticated(): void
    {
        $client = static::createClient();
        $client->request('GET', '/radares');
        $this->assertResponseRedirects('/login');
    }

    public function testShowRedirectsWhenNotAuthenticated(): void
    {
        $client = static::createClient();
        $client->request('GET', '/radares/1');
        $this->assertResponseRedirects('/login');
    }

    public function testEditRedirectsWhenNotAuthenticated(): void
    {
        $client = static::createClient();
        $client->request('GET', '/radares/1/editar');
        $this->assertResponseRedirects('/login');
    }

    // ── Listagem ───────────────────────────────────────────────────────

    public function testIndexLoadsWhenAuthenticated(): void
    {
        $client = static::createClient();
        $this->loginAs($client, 'test@example.com');
        $client->request('GET', '/radares');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('table');
    }

    public function testIndexWithFilterUf(): void
    {
        $client = static::createClient();
        $this->loginAs($client, 'test@example.com');
        $client->request('GET', '/radares', ['uf' => 'SP']);
        $this->assertResponseIsSuccessful();
    }

    public function testIndexWithFilterValidade(): void
    {
        $client = static::createClient();
        $this->loginAs($client, 'test@example.com');
        $client->request('GET', '/radares', ['validade' => 'vencido']);
        $this->assertResponseIsSuccessful();
    }

    public function testIndexPaginacao(): void
    {
        $client = static::createClient();
        $this->loginAs($client, 'test@example.com');
        $client->request('GET', '/radares', ['page' => '2']);
        $this->assertResponseIsSuccessful();
    }

    // ── Mesclados ──────────────────────────────────────────────────────

    public function testMescladosLoads(): void
    {
        $client = static::createClient();
        $this->loginAs($client, 'test@example.com');
        $client->request('GET', '/radares/mesclados');
        $this->assertResponseIsSuccessful();
    }

    // ── Detalhe ────────────────────────────────────────────────────────

    public function testShowReturns404ForInexistente(): void
    {
        $client = static::createClient();
        $this->loginAs($client, 'test@example.com');
        $client->request('GET', '/radares/999999');
        $this->assertResponseStatusCodeSame(404);
    }

    // ── Edição — GET ───────────────────────────────────────────────────

    public function testEditGetReturns404ForInexistente(): void
    {
        $client = static::createClient();
        $this->loginAs($client, 'test@example.com');
        $client->request('GET', '/radares/999999/editar');
        $this->assertResponseStatusCodeSame(404);
    }

    // ── Link Waze — validação ──────────────────────────────────────────

    public function testWazeSaveRejectsLinkSemPermanentHazards(): void
    {
        $client = static::createClient();
        $this->loginAs($client, 'test@example.com');

        $crawler = $client->request('GET', '/radares/1');
        if ($client->getResponse()->getStatusCode() === 404) {
            $this->markTestSkipped('Radar ID=1 não existe no ambiente de teste.');
        }

        $client->request('POST', '/radares/1/waze', [
            'waze_link' => 'https://www.waze.com/pt-BR/live-map/directions',
        ]);

        // Deve renderizar o show com erro de validação (HTTP 200, não redirect)
        $this->assertResponseIsSuccessful();
    }

    public function testWazeSaveRedirectsOnSuccess(): void
    {
        $client = static::createClient();
        $this->loginAs($client, 'test@example.com');

        $client->request('GET', '/radares/1');
        if ($client->getResponse()->getStatusCode() === 404) {
            $this->markTestSkipped('Radar ID=1 não existe no ambiente de teste.');
        }

        $client->request('POST', '/radares/1/waze', [
            'waze_link' => 'https://www.waze.com/pt-BR/live-map?permanentHazards=123456',
            'motivo_revisao' => 'Teste automatizado',
        ]);

        $this->assertResponseRedirects('/radares/1');
    }

    // ── Helpers ────────────────────────────────────────────────────────

    private function loginAs(KernelBrowser $client, string $email): void
    {
        $userRepo = static::getContainer()
            ->get('doctrine')
            ->getRepository(User::class);

        $user = $userRepo->findOneBy(['email' => $email]);

        if ($user === null) {
            $this->markTestSkipped("Usuário '$email' não encontrado no banco de testes.");
        }

        $client->loginUser($user);
    }

    private function extractCsrfToken(Crawler $crawler, string $name = '_token'): ?string
    {
        try {
            return $crawler->filter("input[name=$name]")->first()->attr('value');
        } catch (\Throwable) {
            return null;
        }
    }
}
