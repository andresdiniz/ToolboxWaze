<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Testes funcionais para ExportController.
 * #6 — Cobertura das rotas de exportação CSV.
 */
final class ExportControllerTest extends WebTestCase
{
    // ── Acesso anônimo ─────────────────────────────────────────────────

    public function testRadaresCsvRedirectsWhenNotAuthenticated(): void
    {
        $client = static::createClient();
        $client->request('GET', '/exportar/radares.csv');
        $this->assertResponseRedirects('/login');
    }

    public function testPostosCsvRedirectsWhenNotAuthenticated(): void
    {
        $client = static::createClient();
        $client->request('GET', '/exportar/postos.csv');
        $this->assertResponseRedirects('/login');
    }

    // ── Exportação de radares ──────────────────────────────────────────

    public function testRadaresCsvRetornaCsvAutenticado(): void
    {
        $client = static::createClient();
        $this->loginAs($client, 'test@example.com');
        $client->request('GET', '/exportar/radares.csv');
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'text/csv; charset=UTF-8');
    }

    public function testRadaresCsvComFiltroUf(): void
    {
        $client = static::createClient();
        $this->loginAs($client, 'test@example.com');
        $client->request('GET', '/exportar/radares.csv', ['uf' => 'SP']);
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'text/csv; charset=UTF-8');
    }

    public function testRadaresCsvComFiltroValidadeVencido(): void
    {
        $client = static::createClient();
        $this->loginAs($client, 'test@example.com');
        $client->request('GET', '/exportar/radares.csv', ['validade' => 'vencido']);
        $this->assertResponseIsSuccessful();
    }

    public function testRadaresCsvComFiltroSemWaze(): void
    {
        $client = static::createClient();
        $this->loginAs($client, 'test@example.com');
        $client->request('GET', '/exportar/radares.csv', ['sem_waze' => '1']);
        $this->assertResponseIsSuccessful();
    }

    public function testRadaresCsvConteudoTemCabecalho(): void
    {
        $client = static::createClient();
        $this->loginAs($client, 'test@example.com');
        $client->request('GET', '/exportar/radares.csv');
        $this->assertResponseIsSuccessful();

        $content = $client->getResponse()->getContent();
        // Remove BOM UTF-8
        $content = ltrim($content, "\xEF\xBB\xBF");
        $primeiraLinha = explode("\n", $content)[0] ?? '';
        $this->assertStringContainsString('ID', $primeiraLinha);
        $this->assertStringContainsString('UF', $primeiraLinha);
        $this->assertStringContainsString('Município', $primeiraLinha);
    }

    public function testRadaresCsvNomeArquivoNaDisposition(): void
    {
        $client = static::createClient();
        $this->loginAs($client, 'test@example.com');
        $client->request('GET', '/exportar/radares.csv');
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString(
            'radares_',
            (string) $client->getResponse()->headers->get('Content-Disposition')
        );
    }

    // ── Exportação de postos ───────────────────────────────────────────

    public function testPostosCsvRetornaCsvAutenticado(): void
    {
        $client = static::createClient();
        $this->loginAs($client, 'test@example.com');
        $client->request('GET', '/exportar/postos.csv');
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'text/csv; charset=UTF-8');
    }

    // ── Helper ─────────────────────────────────────────────────────────

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
}
