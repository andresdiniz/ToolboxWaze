<?php

namespace App\MessageHandler;

use App\Entity\Solicitacao;
use App\Message\EnviarEmailSolicitacao;
use App\Repository\SolicitacaoRepository;
use App\Repository\UserRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Email;
use Twig\Environment;

#[AsMessageHandler]
class EnviarEmailSolicitacaoHandler
{
    public function __construct(
        private readonly MailerInterface      $mailer,
        private readonly Environment          $twig,
        private readonly SolicitacaoRepository $solicitacaoRepo,
        private readonly UserRepository       $userRepo,
        private readonly LoggerInterface      $logger,
        #[Autowire('%env(MAILER_FROM)%')] private readonly string $mailerFrom,
        #[Autowire('%env(APP_BASE_URL)%')] private readonly string $appBaseUrl,
    ) {}

    public function __invoke(EnviarEmailSolicitacao $message): void
    {
        $sol = $this->solicitacaoRepo->find($message->solicitacaoId);
        if (!$sol) {
            $this->logger->warning('EnviarEmailHandler: solicitação não encontrada', ['id' => $message->solicitacaoId]);
            return;
        }

        try {
            match ($message->tipo) {
                'confirmacao' => $this->enviarConfirmacao($sol),
                'responsavel' => $this->enviarResponsavel($sol, $message->destinatarioId),
                'resolucao'   => $this->enviarResolucao($sol),
                'comentario'  => $this->enviarComentario($sol),
                default       => $this->logger->warning('EnviarEmailHandler: tipo desconhecido', ['tipo' => $message->tipo]),
            };
        } catch (\Throwable $e) {
            $this->logger->error('EnviarEmailHandler: falha no envio', [
                'tipo'  => $message->tipo,
                'id'    => $message->solicitacaoId,
                'error' => $e->getMessage(),
            ]);
            // Re-lança para que o Messenger faça retry automático
            throw $e;
        }
    }

    private function enviarConfirmacao(Solicitacao $s): void
    {
        $html = $this->twig->render('emails/solicitacao_confirmacao.html.twig', ['solicitacao' => $s]);
        $this->mailer->send(
            (new Email())->from($this->mailerFrom)->to($s->getSolicitanteEmail())
                ->subject('[ToolboxWaze] Solicitação recebida: ' . $s->getTipoLabel())->html($html)
        );
    }

    private function enviarResponsavel(Solicitacao $s, ?int $responsavelId): void
    {
        if (!$responsavelId) return;
        $responsavel = $this->userRepo->find($responsavelId);
        if (!$responsavel) return;

        $html = $this->twig->render('emails/solicitacao_responsavel.html.twig', [
            'solicitacao' => $s,
            'responsavel' => $responsavel,
            'url'         => $this->appBaseUrl . '/solicitacoes/' . $s->getId(),
        ]);
        $this->mailer->send(
            (new Email())->from($this->mailerFrom)->to($responsavel->getEmail())
                ->subject('[ToolboxWaze] Nova pendência: ' . $s->getTipoLabel() . ' (#' . $s->getId() . ')')->html($html)
        );
    }

    private function enviarResolucao(Solicitacao $s): void
    {
        $html = $this->twig->render('emails/solicitacao_resolvida.html.twig', ['solicitacao' => $s]);
        $this->mailer->send(
            (new Email())->from($this->mailerFrom)->to($s->getSolicitanteEmail())
                ->subject('[ToolboxWaze] Sua solicitação foi tratada: ' . $s->getTipoLabel())->html($html)
        );
    }

    private function enviarComentario(Solicitacao $s): void
    {
        foreach ($s->getResponsaveis() as $r) {
            $html = $this->twig->render('emails/solicitacao_comentario.html.twig', [
                'solicitacao' => $s,
                'responsavel' => $r,
                'url'         => $this->appBaseUrl . '/solicitacoes/' . $s->getId(),
            ]);
            $this->mailer->send(
                (new Email())->from($this->mailerFrom)->to($r->getEmail())
                    ->subject('[ToolboxWaze] Novo comentário em #' . $s->getId())->html($html)
            );
        }
    }
}
