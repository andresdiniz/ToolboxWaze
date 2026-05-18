<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class PostoControllerTest extends WebTestCase
{
    // ── Lista ──────────────────────────────────────────────────────────

    public function testIndexRedirectsWhenNotAuthenticated(): void
    {
        $client = static::createClient();
        $client->request('GET', '/postos');
        $this->assertResponseRedirects('/login');
    }

    public function testIndexReturnOkWhenAuthenticated(): void
    {
        $client = static::createClient();
        $this->loginAs($client, 'test@example.com');
        $client->request('GET', '/postos');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('table');
    }

    public function testIndexFilterSemWaze(): void
    {
        $client = static::createClient();
        $this->loginAs($client, 'test@example.com');
        $client->request('GET', '/postos', ['sem_waze' => '1']);
        $this->assertResponseIsSuccessful();
    }

    // ── wazeSave ───────────────────────────────────────────────────────

    public function testWazeSaveRejectsInvalidCsrf(): void
    {
        $client = static::createClient();
        $this->loginAs($client, 'test@example.com');
        $client->request('POST', '/postos/1/waze-salvar', [
            '_token'    => 'token_invalido',
            'waze_link' => 'https://waze.com/pt-BR/editor?venues=123456',
        ]);
        $this->assertResponseRedirects();
    }

    public function testWazeSaveRejectsLinkWithoutVenueParam(): void
    {
        $client  = static::createClient();
        $this->loginAs($client, 'test@example.com');
        $crawler = $client->request('GET', '/postos/1');
        $token   = $this->extractCsrfToken($crawler);

        if ($token === null) {
            $this->markTestSkipped('Posto ID=1 não existe no ambiente de teste.');
        }

        $client->request('POST', '/postos/1/waze-salvar', [
            '_token'    => $token,
            'waze_link' => 'https://waze.com/pt-BR/editor?env=row',
        ]);

        $this->assertResponseRedirects();
        $client->followRedirect();
        $this->assertSelectorTextContains('.invalid-feedback', 'venues=');
    }

    // ── Dashboard ─────────────────────────────────────────────────────

    public function testDashboardLoads(): void
    {
        $client = static::createClient();
        $this->loginAs($client, 'test@example.com');
        $client->request('GET', '/');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('canvas#chartAtividade');
    }

    // ── Auditoria ─────────────────────────────────────────────────────

    public function testAuditoriaLoads(): void
    {
        $client = static::createClient();
        $this->loginAs($client, 'test@example.com');
        $client->request('GET', '/auditoria');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('table');
    }

    public function testAuditoriaRedirectsUnauthenticated(): void
    {
        $client = static::createClient();
        $client->request('GET', '/auditoria');
        $this->assertResponseRedirects('/login');
    }

    // ── Exportação CSV ────────────────────────────────────────────────

    public function testExportCsvReturnsFile(): void
    {
        $client = static::createClient();
        $this->loginAs($client, 'test@example.com');
        $client->request('GET', '/exportar/postos.csv');
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'text/csv; charset=UTF-8');
    }

    public function testExportCsvRedirectsUnauthenticated(): void
    {
        $client = static::createClient();
        $client->request('GET', '/exportar/postos.csv');
        $this->assertResponseRedirects('/login');
    }

    // ── Helpers ───────────────────────────────────────────────────────

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

    private function extractCsrfToken(Crawler $crawler): ?string
    {
        try {
            return $crawler->filter('input[name=_token]')->first()->attr('value');
        } catch (\Throwable) {
            return null;
        }
    }
}
