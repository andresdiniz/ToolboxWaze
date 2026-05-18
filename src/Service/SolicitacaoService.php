<?php

namespace App\Service;

use App\Entity\Solicitacao;
use App\Entity\User;
use App\Repository\SolicitacaoRepository;
use App\Repository\TipoSolicitacaoConfigRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Twig\Environment;

class SolicitacaoService
{
    public function __construct(
        private readonly EntityManagerInterface          $em,
        private readonly SolicitacaoRepository          $solicitacaoRepo,
        private readonly TipoSolicitacaoConfigRepository $configRepo,
        private readonly MailerInterface                $mailer,
        private readonly Environment                    $twig,
        private readonly LoggerInterface                $logger,
        private readonly string                         $mailerFrom,
        private readonly string                         $appBaseUrl,
    ) {}

    public function criar(Solicitacao $solicitacao): void
    {
        $config = $this->configRepo->findByTipo($solicitacao->getTipo());
        if ($config) {
            foreach ($config->getResponsaveisDefault() as $responsavel) {
                $solicitacao->addResponsavel($responsavel);
            }
        }

        $this->em->persist($solicitacao);
        $this->em->flush();

        // E-mails são best-effort: falha de envio não reverte o registro
        try {
            $this->enviarEmailConfirmacao($solicitacao);
        } catch (\Throwable $e) {
            $this->logger->error('SolicitacaoService: falha ao enviar e-mail de confirmação', [
                'solicitacao_id' => $solicitacao->getId(),
                'error'          => $e->getMessage(),
            ]);
        }

        foreach ($solicitacao->getResponsaveis() as $responsavel) {
            try {
                $this->enviarEmailResponsavel($solicitacao, $responsavel);
            } catch (\Throwable $e) {
                $this->logger->error('SolicitacaoService: falha ao enviar e-mail de responsável', [
                    'solicitacao_id'  => $solicitacao->getId(),
                    'responsavel_id'  => $responsavel->getId(),
                    'error'           => $e->getMessage(),
                ]);
            }
        }
    }

    public function resolver(Solicitacao $solicitacao, User $resolvidaPor, ?string $nota = null): void
    {
        if (!$solicitacao->isPendente()) {
            throw new \LogicException('Esta solicitação já foi tratada.');
        }
        $solicitacao
            ->setStatus(Solicitacao::STATUS_RESOLVIDA)
            ->setResolvidaPor($resolvidaPor)
            ->setResolvidaEm(new \DateTimeImmutable())
            ->setNotaResolucao($nota);
        $this->em->flush();

        try {
            $this->enviarEmailResolucao($solicitacao);
        } catch (\Throwable $e) {
            $this->logger->error('SolicitacaoService: falha ao enviar e-mail de resolução', [
                'solicitacao_id' => $solicitacao->getId(),
                'error'          => $e->getMessage(),
            ]);
        }
    }

    public function getPendenciasDoUsuario(User $user): array
    {
        return $this->solicitacaoRepo->findPendentesDoResponsavel($user);
    }

    public function countPendencias(User $user): int
    {
        return $this->solicitacaoRepo->countPendentesDoResponsavel($user);
    }

    private function enviarEmailConfirmacao(Solicitacao $s): void
    {
        $html = $this->twig->render('emails/solicitacao_confirmacao.html.twig', ['solicitacao' => $s]);
        $this->mailer->send(
            (new Email())->from($this->mailerFrom)->to($s->getSolicitanteEmail())
                ->subject('[ToolboxWaze] Solicitação recebida: ' . $s->getTipoLabel())->html($html)
        );
    }

    private function enviarEmailResponsavel(Solicitacao $s, User $responsavel): void
    {
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

    private function enviarEmailResolucao(Solicitacao $s): void
    {
        $html = $this->twig->render('emails/solicitacao_resolvida.html.twig', ['solicitacao' => $s]);
        $this->mailer->send(
            (new Email())->from($this->mailerFrom)->to($s->getSolicitanteEmail())
                ->subject('[ToolboxWaze] Sua solicitação foi tratada: ' . $s->getTipoLabel())->html($html)
        );
    }
}
