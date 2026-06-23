<?php

namespace App\MessageHandler;

use App\Message\NotificarRadaresRecentes;
use App\Repository\UserRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Twig\Environment;

#[AsMessageHandler]
final class NotificarRadaresRecentesHandler
{
    /**
     * TTL de deduplicação: dentro deste janela de tempo o mesmo
     * destinatário+UF NÃO recebe um segundo e-mail.
     */
    private const DEDUP_TTL = 3600; // 1 hora

    public function __construct(
        private readonly UserRepository  $userRepository,
        private readonly MailerInterface $mailer,
        private readonly Environment     $twig,
        private readonly LoggerInterface $logger,
        private readonly CacheInterface  $cache,
        private readonly string          $emailFrom,
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

        // ── Rate-limit / deduplicação via cache ─────────────────────────
        $dedupKey = sprintf(
            'notif_radares_recentes_%d_%s',
            $message->userId,
            $message->siglaUf
        );

        $alreadySent = false;
        $this->cache->get($dedupKey, function (ItemInterface $item) use (&$alreadySent): bool {
            $alreadySent = false;
            $item->expiresAfter(self::DEDUP_TTL);
            return true; // marca como "enviado"
        });

        // Se a key já existia no cache, $alreadySent permanece false e
        // o callback NÃO é chamado — portanto só enviamos quando o cache miss.
        // Porém a lógica acima sempre grava; usamos hit/miss explícito:
        // Reimplementação correta com hit detection:
        $sent = $this->cache->get($dedupKey . '_sent', function (ItemInterface $item) use ($message, $user): bool {
            // Esse bloco só executa uma vez (cache miss)
            $item->expiresAfter(self::DEDUP_TTL);
            return false; // será sobrescrito abaixo após envio bem-sucedido
        });

        // Chave de controle real
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

        // ── Envia ────────────────────────────────────────────────────────
        $html = $this->twig->render('email/radares_recentes.html.twig', [
            'usuario'         => $user,
            'siglaUf'         => $message->siglaUf,
            'nomeEstado'      => $message->nomeEstado,
            'quantidadeNovos' => $message->quantidadeNovos,
            'dataImport'      => $message->dataImport,
        ]);

        $email = (new Email())
            ->from($this->emailFrom)
            ->to($user->getEmail())
            ->subject(sprintf(
                '[RadarBR] %d novo(s) radar(es) adicionado(s) em %s',
                $message->quantidadeNovos,
                $message->nomeEstado,
            ))
            ->html($html);

        try {
            $this->mailer->send($email);
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
