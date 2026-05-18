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
        private readonly MailerInterface       $mailer,
        private readonly Environment           $twig,
        private readonly SolicitacaoRepository $solicitacaoRepo,
        private readonly UserRepository        $userRepo,
        private readonly LoggerInterface       $logger,
        #[Autowire('%env(MAILER_FROM)%')]    private readonly string $mailerFrom,
        #[Autowire('%env(APP_BASE_URL)%')]   private readonly string $appBaseUrl,
    ) {}

    public function __invoke(EnviarEmailSolicitacao $message): void
    {
        $sol = $this->solicitacaoRepo->find($message->solicitacaoId);
        if (!$sol) {
            $this->logger->warning('EnviarEmailHandler: solicitação não encontrada', [
                'id' => $message->solicitacaoId,
            ]);
            return;
        }

        try {
            match ($message->tipo) {
                'confirmacao'    => $this->enviarConfirmacao($sol),
                'responsavel'    => $this->enviarResponsavel($sol, $message->destinatarioId),
                'resolucao'      => $this->enviarResolucao($sol),
                'status_alterado'=> $this->enviarStatusAlterado($sol),
                'comentario'     => $this->enviarComentario($sol, $message->destinatarioId),
                default          => $this->logger->warning('EnviarEmailHandler: tipo desconhecido', [
                                        'tipo' => $message->tipo,
                                    ]),
            };
        } catch (\Throwable $e) {
            $this->logger->error('EnviarEmailHandler: falha no envio', [
                'tipo'  => $message->tipo,
                'id'    => $message->solicitacaoId,
                'error' => $e->getMessage(),
            ]);
            // Re-lança para retry automático do Messenger
            throw $e;
        }
    }

    // ------------------------------------------------------------------
    // Confirmação ao solicitante (criação)
    // ------------------------------------------------------------------
    private function enviarConfirmacao(Solicitacao $s): void
    {
        $html = $this->twig->render('emails/solicitacao_confirmacao.html.twig', [
            'solicitacao' => $s,
            'url'         => $this->appBaseUrl . '/solicitacoes/' . $s->getId(),
        ]);
        $this->mailer->send(
            (new Email())
                ->from($this->mailerFrom)
                ->to($s->getSolicitanteEmail())
                ->subject('[ToolboxWaze] Solicitação recebida: ' . $s->getTipoLabel())
                ->html($html)
        );
    }

    // ------------------------------------------------------------------
    // Aviso de nova pendência ao responsável
    // ------------------------------------------------------------------
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
            (new Email())
                ->from($this->mailerFrom)
                ->to($responsavel->getEmail())
                ->subject('[ToolboxWaze] Nova pendência: ' . $s->getTipoLabel() . ' (#' . $s->getId() . ')')
                ->html($html)
        );
    }

    // ------------------------------------------------------------------
    // Resolução final (resolvida / negada / cancelada) ao solicitante
    // ------------------------------------------------------------------
    private function enviarResolucao(Solicitacao $s): void
    {
        $html = $this->twig->render('emails/solicitacao_resolvida.html.twig', [
            'solicitacao' => $s,
            'url'         => $this->appBaseUrl . '/solicitacoes/' . $s->getId(),
        ]);
        $this->mailer->send(
            (new Email())
                ->from($this->mailerFrom)
                ->to($s->getSolicitanteEmail())
                ->subject('[ToolboxWaze] Sua solicitação foi tratada: ' . $s->getTipoLabel())
                ->html($html)
        );
    }

    // ------------------------------------------------------------------
    // Status intermediário alterado — informa o solicitante do andamento
    // ------------------------------------------------------------------
    private function enviarStatusAlterado(Solicitacao $s): void
    {
        $html = $this->twig->render('emails/solicitacao_status_alterado.html.twig', [
            'solicitacao' => $s,
            'url'         => $this->appBaseUrl . '/solicitacoes/' . $s->getId(),
        ]);
        $this->mailer->send(
            (new Email())
                ->from($this->mailerFrom)
                ->to($s->getSolicitanteEmail())
                ->subject('[ToolboxWaze] Atualização na sua solicitação #' . $s->getId())
                ->html($html)
        );
    }

    // ------------------------------------------------------------------
    // Novo comentário público
    // Lógica:
    //   - Se autorId == null  → comentário veio do solicitante anônimo
    //     → envia e-mail para todos os responsáveis
    //   - Se autorId != null  → comentário veio de um responsável
    //     → envia e-mail para o solicitante (e para outros responsáveis
    //        que não sejam o autor)
    // ------------------------------------------------------------------
    private function enviarComentario(Solicitacao $s, ?int $autorId): void
    {
        $url = $this->appBaseUrl . '/solicitacoes/' . $s->getId();

        if ($autorId === null) {
            // Solicitante comentou → avisa responsáveis
            foreach ($s->getResponsaveis() as $r) {
                $html = $this->twig->render('emails/solicitacao_comentario.html.twig', [
                    'solicitacao' => $s,
                    'responsavel' => $r,
                    'url'         => $url,
                ]);
                $this->mailer->send(
                    (new Email())
                        ->from($this->mailerFrom)
                        ->to($r->getEmail())
                        ->subject('[ToolboxWaze] Novo comentário em #' . $s->getId())
                        ->html($html)
                );
            }
        } else {
            // Responsável comentou → avisa solicitante
            $html = $this->twig->render('emails/solicitacao_comentario_solicitante.html.twig', [
                'solicitacao' => $s,
                'url'         => $url,
            ]);
            $this->mailer->send(
                (new Email())
                    ->from($this->mailerFrom)
                    ->to($s->getSolicitanteEmail())
                    ->subject('[ToolboxWaze] Resposta na sua solicitação #' . $s->getId())
                    ->html($html)
            );

            // Avisa outros responsáveis também (exceto o autor)
            foreach ($s->getResponsaveis() as $r) {
                if ($r->getId() === $autorId) continue;
                $html2 = $this->twig->render('emails/solicitacao_comentario.html.twig', [
                    'solicitacao' => $s,
                    'responsavel' => $r,
                    'url'         => $url,
                ]);
                $this->mailer->send(
                    (new Email())
                        ->from($this->mailerFrom)
                        ->to($r->getEmail())
                        ->subject('[ToolboxWaze] Novo comentário em #' . $s->getId())
                        ->html($html2)
                );
            }
        }
    }
}
