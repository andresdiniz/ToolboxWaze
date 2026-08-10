<?php

namespace App\Controller\Auth;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class WazeCheckController extends AbstractController
{
    #[Route('/auth/waze-check', name: 'app_auth_waze_check', methods: ['POST'])]
    public function __invoke(Request $request, HttpClientInterface $httpClient): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        $nickname = $data['nickname'] ?? '';
        $nickname = preg_replace('/[^A-Za-z0-9_.-]/', '', $nickname ?? '');
        $nickname = mb_substr($nickname, 0, 50);

        if ($nickname === '' || mb_strlen($nickname) < 3) {
            return $this->json([
                'valid' => false,
                'message' => 'Nickname inválido.'
            ], 422);
        }

        try {
            $response = $httpClient->request('GET', 'https://www.waze.com/discuss/user_actions.json', [
                'query' => [
                    'offset' => 0,
                    'username' => $nickname,
                ],
                'headers' => [
                    'Accept' => 'application/json',
                    'User-Agent' => 'ToolboxWaze/1.0'
                ],
                'timeout' => 10,
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode === 404) {
                return $this->json([
                    'valid' => false,
                    'message' => 'Nickname não encontrado.'
                ]);
            }

            if ($statusCode !== 200) {
                return $this->json([
                    'valid' => false,
                    'message' => 'Falha ao consultar o Waze.'
                ], 502);
            }

            $payload = $response->toArray(false);

            $valid = is_array($payload) && array_key_exists('user_actions', $payload);

            return $this->json([
                'valid' => $valid,
                'message' => $valid ? 'Nickname válido.' : 'Nickname não encontrado.'
            ]);
        } catch (\Throwable $e) {
            return $this->json([
                'valid' => false,
                'message' => 'Erro ao consultar serviço externo.'
            ], 502);
        }
    }
}