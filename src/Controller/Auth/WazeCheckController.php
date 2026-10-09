<?php

namespace App\Controller\Auth;

use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class WazeCheckController extends AbstractController
{
    #[Route('/auth/waze-check', name: 'app_auth_waze_check', methods: ['POST'])]
    public function __invoke(
        Request $request,
        HttpClientInterface $httpClient,
        LoggerInterface $logger
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->json(['valid' => false, 'message' => 'JSON inválido.'], 400);
        }

        $nickname = trim((string) ($data['nickname'] ?? ''));
        $nickname = preg_replace('/[^A-Za-z0-9_.-]/', '', $nickname) ?? '';
        $nickname = mb_substr($nickname, 0, 50);

        if ($nickname === '' || mb_strlen($nickname) < 3) {
            return $this->json(['valid' => false, 'message' => 'Nickname inválido.'], 422);
        }

        try {
            $profileUrl = "https://www.waze.com/discuss/u/{$nickname}";
            $html = $httpClient->request('GET', $profileUrl, [
                'headers' => [
                    'Accept' => 'text/html,application/xhtml+xml',
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36',
                ],
                'timeout' => 15,
            ])->getContent(false);

            if (
                stripos($html, 'cf-turnstile') !== false ||
                stripos($html, 'Checking your browser') !== false
            ) {
                return $this->json(['valid' => false, 'message' => 'Waze bloqueou.'], 502);
            }

            $avatarData = $this->extractAvatarData($html, $nickname);

            if (!$avatarData) {
                return $this->json(['valid' => true, 'message' => 'Nickname não encontrado.']);
            }

            return $this->json([
                'valid'   => true,
                'message' => 'Nickname válido.',
                'username' => $nickname,
                'avatar'   => $avatarData,
            ]);

        } catch (\Throwable $e) {
            $logger->error('Erro Waze', ['nickname' => $nickname, 'error' => $e->getMessage()]);
            return $this->json(['valid' => false, 'message' => 'Erro ao consultar Waze.'], 502);
        }
    }

    private function extractAvatarData(string $html, string $nickname): ?array
    {
        // 1. UUID da CDN Waze (sms-profile-image.waze.com)
        if (preg_match('#https://sms-profile-image\.waze\.com/([0-9a-f-]{36})#i', $html, $m)) {
            return [
                'type' => 'cdn',
                'uuid' => $m[1],
                'url'  => 'https://sms-profile-image.waze.com/' . $m[1],
            ];
        }

        // 2. Avatar do Discourse
        if (preg_match('#/discuss/user_avatar/www\.waze\.com/' . preg_quote($nickname, '#') . '/\d+/(\d+_2\.png)#i', $html, $m)) {
            $avatarId = $m[1];
            $template = "/discuss/user_avatar/www.waze.com/{$nickname}/{size}/{$avatarId}";
            return [
                'type'     => 'discourse',
                'url'      => 'https://www.waze.com' . str_replace('{size}', '48', $template),
                'template' => $template,
            ];
        }

        return null;
    }
}