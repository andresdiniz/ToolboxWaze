<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Testes funcionais para ApiController.
 * Cobre as rotas /api/busca, /api/radares e /api/postos.
 */
final class ApiControllerTest extends WebTestCase
{
    // ── /api/busca — rota pública ──────────────────────────────────────

    public function testBuscaRetornaArrayVazioParaQueryCurta(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/busca', ['q' => 'a']);
        $this->assertResponseIsSuccessful();
        $this->assertJson($client->getResponse()->getContent());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame([], $data);
    }

    public function testBuscaRetornaJsonParaQueryValida(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/busca', ['q' => 'São']);
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'application/json');
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
    }

    public function testBuscaRetornaStructuraCorreta(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/busca', ['q' => 'Paulo']);
        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        // Se retornar resultados, verifica as chaves esperadas
        if (!empty($data)) {
            $item = $data[0];
            $this->assertArrayHasKey('tipo', $item);
            $this->assertArrayHasKey('id', $item);
            $this->assertArrayHasKey('label', $item);
            $this->assertArrayHasKey('url', $item);
            $this->assertContains($item['tipo'], ['radar', 'posto']);
        } else {
            $this->assertSame([], $data);
        }
    }

    // ── /api/radares — requer autenticação ────────────────────────────

    public function testRadaresRedirectsWhenNotAuthenticated(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/radares');
        // Sem autenticação deve receber 401 ou redirect
        $this->assertThat(
            $client->getResponse()->getStatusCode(),
            $this->logicalOr(
                $this->equalTo(401),
                $this->equalTo(302)
            )
        );
    }

    public function testRadaresRetornaJsonAutenticado(): void
    {
        $client = static::createClient();
        $this->loginAs($client, 'test@example.com');
        $client->request('GET', '/api/radares');
        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('total', $data);
        $this->assertArrayHasKey('page', $data);
        $this->assertArrayHasKey('pages', $data);
    }

    public function testRadaresComFiltroUf(): void
    {
        $client = static::createClient();
        $this->loginAs($client, 'test@example.com');
        $client->request('GET', '/api/radares', ['uf' => 'SP']);
        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('data', $data);
        // Todos os resultados devem ser de SP
        foreach ($data['data'] as $row) {
            $this->assertSame('SP', $row['sigla_uf']);
        }
    }

    public function testRadaresComPaginacao(): void
    {
        $client = static::createClient();
        $this->loginAs($client, 'test@example.com');
        $client->request('GET', '/api/radares', ['page' => '1', 'limit' => '5']);
        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertLessThanOrEqual(5, count($data['data']));
        $this->assertSame(1, $data['page']);
    }

    public function testRadaresLimitMaximo(): void
    {
        $client = static::createClient();
        $this->loginAs($client, 'test@example.com');
        // Tentar ultrapassar o limite de 100
        $client->request('GET', '/api/radares', ['limit' => '9999']);
        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertLessThanOrEqual(100, count($data['data']));
    }

    // ── /api/postos — requer autenticação ─────────────────────────────

    public function testPostosRedirectsWhenNotAuthenticated(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/postos');
        $this->assertThat(
            $client->getResponse()->getStatusCode(),
            $this->logicalOr(
                $this->equalTo(401),
                $this->equalTo(302)
            )
        );
    }

    public function testPostosRetornaJsonAutenticado(): void
    {
        $client = static::createClient();
        $this->loginAs($client, 'test@example.com');
        $client->request('GET', '/api/postos');
        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('total', $data);
        $this->assertArrayHasKey('page', $data);
        $this->assertArrayHasKey('pages', $data);
    }

    public function testPostosComFiltroUf(): void
    {
        $client = static::createClient();
        $this->loginAs($client, 'test@example.com');
        $client->request('GET', '/api/postos', ['uf' => 'MG']);
        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        foreach ($data['data'] as $row) {
            $this->assertSame('MG', $row['uf']);
        }
    }

    public function testPostosComFiltroMunicipio(): void
    {
        $client = static::createClient();
        $this->loginAs($client, 'test@example.com');
        $client->request('GET', '/api/postos', ['municipio' => 'Campinas']);
        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertIsArray($data['data']);
    }

    // ── Helper ────────────────────────────────────────────────────────

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
