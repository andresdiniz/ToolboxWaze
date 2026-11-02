<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\NotificarRadaresRecentes;
use App\Repository\UserRepository;
use App\Service\EmailSender;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Twig\Environment;

#[AsMessageHandler]
final class NotificarRadaresRecentesHandler
{
    /**
     * TTL de deduplicação: dentro desta janela o mesmo destinatário+UF
     * NÃO recebe um segundo e-mail.
     */
    private const DEDUP_TTL = 3600; // 1 hora

    public function __construct(
        private readonly UserRepository  $userRepository,
        private readonly EmailSender     $emailSender,
        private readonly Environment     $twig,
        private readonly LoggerInterface $logger,
        private readonly CacheInterface  $cache,
    ) {}

    public function __invoke(NotificarRadaresRecentes $message): void
    {
        $user = $this->userRepository->find($message->userId);

        if ($user === null || !$user->isApproved()) {
            $this->logger->info(
                'NotificarRadaresRecentes: usuário {id} não encontrado ou não aprovado, pulando.',
                ['id' => $message->userId]
            );
            return;
        }

        if (!$user->canAccessUf($message->siglaUf)) {
            $this->logger->info(
                'NotificarRadaresRecentes: usuário {id} não tem mais acesso à UF {uf}, pulando.',
                ['id' => $message->userId, 'uf' => $message->siglaUf]
            );
            return;
        }

        // Deduplicação: o callback só roda em cache MISS.
        $lockKey = 'notif_radar_lock_' . $message->userId . '_' . $message->siglaUf;
        $isNew   = false;

        $this->cache->get($lockKey, function (ItemInterface $item) use (&$isNew): bool {
            $isNew = true;
            $item->expiresAfter(self::DEDUP_TTL);
            return true;
        });

        if (!$isNew) {
            $this->logger->info(
                'NotificarRadaresRecentes: e-mail para {email} (UF: {uf}) suprimido por deduplicação (TTL {ttl}s).',
                ['email' => $user->getEmail(), 'uf' => $message->siglaUf, 'ttl' => self::DEDUP_TTL]
            );
            return;
        }

        $html = $this->twig->render('email/radares_recentes.html.twig', [
            'usuario'         => $user,
            'siglaUf'         => $message->siglaUf,
            'nomeEstado'      => $message->nomeEstado,
            'quantidadeNovos' => $message->quantidadeNovos,
            'dataImport'      => $message->dataImport,
        ]);

        $subject = sprintf(
            '[ToolboxWaze] %d novo(s) radar(es) adicionado(s) em %s',
            $message->quantidadeNovos,
            $message->nomeEstado,
        );

        try {
            $this->emailSender->send($user->getEmail(), $user->getName(), $subject, $html);

            $this->logger->info(
                'E-mail de radares recentes enviado para {email} (UF: {uf}, qtd: {qtd})',
                [
                    'email' => $user->getEmail(),
                    'uf'    => $message->siglaUf,
                    'qtd'   => $message->quantidadeNovos,
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Falha ao enviar e-mail de radares recentes para {email}: {erro}',
                ['email' => $user->getEmail(), 'erro' => $e->getMessage()]
            );
            throw $e;
        }
    }
}
