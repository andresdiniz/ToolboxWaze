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
use Symfony\Component\Mime\Address;
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
        #[Autowire('%env(MAILER_FROM)%')]   private readonly string $mailerFrom,
        #[Autowire('%env(APP_BASE_URL)%')]  private readonly string $appBaseUrl,
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
                'confirmacao'     => $this->enviarConfirmacao($sol),
                'responsavel'     => $this->enviarResponsavel($sol, $message->destinatarioId),
                'resolucao'       => $this->enviarResolucao($sol),
                'status_alterado' => $this->enviarStatusAlterado($sol),
                'comentario'      => $this->enviarComentario($sol, $message->destinatarioId),
                default           => $this->logger->warning('EnviarEmailHandler: tipo desconhecido', [
                                         'tipo' => $message->tipo,
                                     ]),
            };
        } catch (\Throwable $e) {
            $this->logger->error('EnviarEmailHandler: falha no envio', [
                'tipo'  => $message->tipo,
                'id'    => $message->solicitacaoId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            // Re-lança para retry automático do Messenger
            throw $e;
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Retorna um Address garantindo que MAILER_FROM possa ser:
     *   - "noreply@site.com"
     *   - "ToolboxWaze <noreply@site.com>"
     */
    private function fromAddress(): Address
    {
        // Tenta parse "Nome <email@exemplo.com>"
        if (preg_match('/^(.+)\s<([^>]+)>$/', trim($this->mailerFrom), $m)) {
            return new Address(trim($m[2]), trim($m[1]));
        }
        return new Address(trim($this->mailerFrom), 'ToolboxWaze');
    }

    private function buildUrl(Solicitacao $s): string
    {
        return rtrim($this->appBaseUrl, '/') . '/solicitacoes/' . $s->getId();
    }

    private function send(string $to, string $subject, string $html): void
    {
        $this->mailer->send(
            (new Email())
                ->from($this->fromAddress())
                ->to($to)
                ->subject($subject)
                ->html($html)
        );
    }

    // ------------------------------------------------------------------
    // Confirmação ao solicitante (criação)
    // ------------------------------------------------------------------
    private function enviarConfirmacao(Solicitacao $s): void
    {
        $html = $this->twig->render('emails/solicitacao_confirmacao.html.twig', [
            'solicitacao' => $s,
            'url'         => $this->buildUrl($s),
        ]);
        $this->send(
            $s->getSolicitanteEmail(),
            '[ToolboxWaze] Solicitação recebida: ' . $s->getTipoLabel(),
            $html
        );
    }

    // ------------------------------------------------------------------
    // Aviso de nova pendência ao responsável
    // ------------------------------------------------------------------
    private function enviarResponsavel(Solicitacao $s, ?int $responsavelId): void
    {
        if (!$responsavelId) {
            $this->logger->warning('EnviarEmailHandler: enviarResponsavel chamado sem responsavelId', [
                'solicitacao_id' => $s->getId(),
            ]);
            return;
        }
        $responsavel = $this->userRepo->find($responsavelId);
        if (!$responsavel) {
            $this->logger->warning('EnviarEmailHandler: responsável não encontrado', [
                'responsavel_id' => $responsavelId,
            ]);
            return;
        }

        $html = $this->twig->render('emails/solicitacao_responsavel.html.twig', [
            'solicitacao' => $s,
            'responsavel' => $responsavel,
            'url'         => $this->buildUrl($s),
        ]);
        $this->send(
            $responsavel->getEmail(),
            sprintf('[ToolboxWaze] Nova pendência: %s (#%d)', $s->getTipoLabel(), $s->getId()),
            $html
        );
    }

    // ------------------------------------------------------------------
    // Resolução final (resolvida / negada / cancelada) ao solicitante
    // ------------------------------------------------------------------
    private function enviarResolucao(Solicitacao $s): void
    {
        $html = $this->twig->render('emails/solicitacao_resolvida.html.twig', [
            'solicitacao' => $s,
            'url'         => $this->buildUrl($s),
        ]);
        $this->send(
            $s->getSolicitanteEmail(),
            '[ToolboxWaze] Sua solicitação foi tratada: ' . $s->getTipoLabel(),
            $html
        );
    }

    // ------------------------------------------------------------------
    // Status intermediário alterado — informa o solicitante do andamento
    // ------------------------------------------------------------------
    private function enviarStatusAlterado(Solicitacao $s): void
    {
        $html = $this->twig->render('emails/solicitacao_status_alterado.html.twig', [
            'solicitacao' => $s,
            'url'         => $this->buildUrl($s),
        ]);
        $this->send(
            $s->getSolicitanteEmail(),
            '[ToolboxWaze] Atualização na sua solicitação #' . $s->getId(),
            $html
        );
    }

    // ------------------------------------------------------------------
    // Novo comentário público
    //
    // Regras:
    //   autorId == null  → comentário do solicitante externo (sem login)
    //                      → avisa todos os responsáveis
    //   autorId != null  → comentário de um responsável/admin logado
    //                      → avisa o solicitante
    //                      → avisa demais responsáveis (exceto o autor)
    //
    // IMPORTANTE: comentários internos (interno=true) NUNCA chegam aqui —
    // o SolicitacaoService só faz dispatch quando interno=false.  Esta
    // verificação é apenas uma camada de defesa extra.
    // ------------------------------------------------------------------
    private function enviarComentario(Solicitacao $s, ?int $autorId): void
    {
        $url = $this->buildUrl($s);

        if ($autorId === null) {
            // Solicitante (anônimo / sem login) comentou → avisa responsáveis
            $responsaveis = $s->getResponsaveis();

            if ($responsaveis->isEmpty()) {
                $this->logger->info(
                    'EnviarEmailHandler: solicitação sem responsáveis cadastrados — comentário sem destinatário',
                    ['solicitacao_id' => $s->getId()]
                );
                return;
            }

            foreach ($responsaveis as $r) {
                $html = $this->twig->render('emails/solicitacao_comentario.html.twig', [
                    'solicitacao' => $s,
                    'responsavel' => $r,
                    'url'         => $url,
                ]);
                $this->send(
                    $r->getEmail(),
                    '[ToolboxWaze] Novo comentário em #' . $s->getId(),
                    $html
                );
            }
        } else {
            // Responsável/admin comentou → avisa o solicitante
            $html = $this->twig->render('emails/solicitacao_comentario_solicitante.html.twig', [
                'solicitacao' => $s,
                'url'         => $url,
            ]);
            $this->send(
                $s->getSolicitanteEmail(),
                '[ToolboxWaze] Resposta na sua solicitação #' . $s->getId(),
                $html
            );

            // Avisa outros responsáveis (exceto o autor do comentário)
            foreach ($s->getResponsaveis() as $r) {
                if ($r->getId() === $autorId) {
                    continue;
                }
                $html2 = $this->twig->render('emails/solicitacao_comentario.html.twig', [
                    'solicitacao' => $s,
                    'responsavel' => $r,
                    'url'         => $url,
                ]);
                $this->send(
                    $r->getEmail(),
                    '[ToolboxWaze] Novo comentário em #' . $s->getId(),
                    $html2
                );
            }
        }
    }
}
