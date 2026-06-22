<?php

namespace App\MessageHandler;

use App\Message\NotificarRadaresRecentes;
use App\Repository\UserRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Email;
use Twig\Environment;

#[AsMessageHandler]
final class NotificarRadaresRecentesHandler
{
    public function __construct(
        private readonly UserRepository  $userRepository,
        private readonly MailerInterface $mailer,
        private readonly Environment     $twig,
        private readonly LoggerInterface $logger,
        private readonly string          $emailFrom,
    ) {}

    public function __invoke(NotificarRadaresRecentes $message): void
    {
        $user = $this->userRepository->find($message->userId);

        // Guarda-chuva: usuário pode ter sido removido/desativado entre o dispatch e o consumo
        if ($user === null || !$user->isApproved()) {
            $this->logger->info(
                'NotificarRadaresRecentes: usuário {id} não encontrado ou não aprovado, pulando.',
                ['id' => $message->userId]
            );
            return;
        }

        // Valida que o usuário ainda tem acesso à UF (acesso pode ter mudado no intervalo)
        if (!$user->canAccessUf($message->siglaUf)) {
            $this->logger->info(
                'NotificarRadaresRecentes: usuário {id} não tem mais acesso à UF {uf}, pulando.',
                ['id' => $message->userId, 'uf' => $message->siglaUf]
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
            // Re-lança para o Messenger aplicar a política de retry
            throw $e;
        }
    }
}
