<?php

namespace App\Service;

use App\Entity\{Notificacao, Solicitacao, SolicitacaoComentario, SolicitacaoHistorico, User};
use App\Message\EnviarEmailSolicitacao;
use App\Repository\{NotificacaoRepository, SolicitacaoRepository, TipoSolicitacaoConfigRepository};
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

class SolicitacaoService
{
    public function __construct(
        private readonly EntityManagerInterface           $em,
        private readonly SolicitacaoRepository           $solicitacaoRepo,
        private readonly TipoSolicitacaoConfigRepository $configRepo,
        private readonly NotificacaoRepository           $notifRepo,
        private readonly MessageBusInterface             $bus,
        private readonly LoggerInterface                 $logger,
    ) {}

    // ---------------------------------------------------------------
    // CRIAÇÃO
    // ---------------------------------------------------------------

    public function criar(Solicitacao $solicitacao): void
    {
        $config = $this->configRepo->findByTipo($solicitacao->getTipo());
        if ($config) {
            foreach ($config->getResponsaveisDefault() as $responsavel) {
                $solicitacao->addResponsavel($responsavel);
            }
        }

        $this->registrarHistorico($solicitacao, null, Solicitacao::STATUS_PENDENTE, null, 'Solicitação criada.');

        $this->em->persist($solicitacao);
        $this->em->flush();

        // Notificação in-app para cada responsável
        foreach ($solicitacao->getResponsaveis() as $responsavel) {
            $this->criarNotificacao(
                $responsavel,
                $solicitacao,
                Notificacao::TIPO_NOVA_SOLICITACAO,
                sprintf('Nova solicitação: %s (#%d)', $solicitacao->getTipoLabel(), $solicitacao->getId())
            );
        }
        $this->em->flush();

        // E-mail de confirmação para o solicitante
        $this->dispatchEmail('confirmacao', $solicitacao->getId());

        // E-mail de aviso de nova pendência para cada responsável
        foreach ($solicitacao->getResponsaveis() as $r) {
            $this->dispatchEmail('responsavel', $solicitacao->getId(), $r->getId());
        }
    }

    // ---------------------------------------------------------------
    // MUDANÇA DE STATUS
    // ---------------------------------------------------------------

    public function mudarStatus(
        Solicitacao $solicitacao,
        string $novoStatus,
        User $autor,
        ?string $nota = null
    ): void {
        $statusAnterior = $solicitacao->getStatus();

        if ($statusAnterior === $novoStatus) {
            return;
        }

        if (!array_key_exists($novoStatus, Solicitacao::STATUS_LABELS)) {
            throw new \InvalidArgumentException("Status inválido: $novoStatus");
        }

        // Campos de resolução para status finais
        if (in_array($novoStatus, Solicitacao::STATUS_FINAIS, true)) {
            $solicitacao
                ->setResolvidaPor($autor)
                ->setResolvidaEm(new \DateTimeImmutable())
                ->setNotaResolucao($nota);
        }

        $solicitacao->setStatus($novoStatus);
        $this->registrarHistorico($solicitacao, $statusAnterior, $novoStatus, $autor, $nota);
        $this->em->flush();

        // Notificar outros responsáveis (in-app)
        foreach ($solicitacao->getResponsaveis() as $r) {
            if ($r->getId() !== $autor->getId()) {
                $this->criarNotificacao(
                    $r,
                    $solicitacao,
                    Notificacao::TIPO_STATUS_ALTERADO,
                    sprintf(
                        'Status alterado para "%s" em #%d',
                        $solicitacao->getStatusLabel(),
                        $solicitacao->getId()
                    )
                );
            }
        }
        $this->em->flush();

        // E-mail para o solicitante em qualquer mudança de status
        if (in_array($novoStatus, Solicitacao::STATUS_FINAIS, true)) {
            // Status final: e-mail de resolução com nota e desfecho
            $this->dispatchEmail('resolucao', $solicitacao->getId());
        } else {
            // Status intermediário: e-mail informativo de andamento
            $this->dispatchEmail('status_alterado', $solicitacao->getId());
        }
    }

    // ---------------------------------------------------------------
    // COMENTÁRIOS
    // ---------------------------------------------------------------

    public function adicionarComentario(
        Solicitacao $solicitacao,
        string $mensagem,
        ?User $autor = null,
        ?string $autorNome = null,
        bool $interno = false
    ): SolicitacaoComentario {
        $comentario = new SolicitacaoComentario();
        $comentario->setSolicitacao($solicitacao);
        $comentario->setMensagem(trim($mensagem));
        $comentario->setInterno($interno);

        if ($autor) {
            $comentario->setAutor($autor);
        } else {
            $comentario->setAutorNomeExterno($autorNome ?? $solicitacao->getSolicitanteNome());
        }

        $this->em->persist($comentario);
        $this->em->flush();

        // Notificação in-app: notifica responsáveis sobre qualquer comentário
        // (interno ou não — eles precisam ver os dois)
        foreach ($solicitacao->getResponsaveis() as $r) {
            if ($autor && $r->getId() === $autor->getId()) {
                continue; // não notifica quem comentou
            }
            $this->criarNotificacao(
                $r,
                $solicitacao,
                Notificacao::TIPO_NOVO_COMENTARIO,
                sprintf(
                    'Novo comentário em #%d: %s',
                    $solicitacao->getId(),
                    mb_substr($mensagem, 0, 60)
                )
            );
        }
        $this->em->flush();

        // E-mail apenas para comentários públicos (interno = false)
        // Responsáveis recebem e-mail; solicitante recebe se o comentário
        // foi feito por um responsável (autor !== null e tem papel de gestor)
        if (!$interno) {
            $this->dispatchEmail('comentario', $solicitacao->getId(), $autor?->getId());
        }

        return $comentario;
    }

    // ---------------------------------------------------------------
    // NOTIFICAÇÕES / PENDÊNCIAS
    // ---------------------------------------------------------------

    public function getPendenciasDoUsuario(User $user): array
    {
        return $this->solicitacaoRepo->findPendentesDoResponsavel($user);
    }

    public function countPendencias(User $user): int
    {
        return $this->solicitacaoRepo->countPendentesDoResponsavel($user);
    }

    public function countNotificacoesNaoLidas(User $user): int
    {
        return $this->notifRepo->countNaoLidas($user);
    }

    // ---------------------------------------------------------------
    // HELPERS PRIVADOS
    // ---------------------------------------------------------------

    private function registrarHistorico(
        Solicitacao $solicitacao,
        ?string $statusAnterior,
        string $statusNovo,
        ?User $autor,
        ?string $nota
    ): void {
        $h = new SolicitacaoHistorico();
        $h->setStatusAnterior($statusAnterior);
        $h->setStatusNovo($statusNovo);
        $h->setAutor($autor);
        $h->setNota($nota);
        $solicitacao->addHistorico($h);
        $this->em->persist($h);
    }

    private function criarNotificacao(User $user, Solicitacao $sol, string $tipo, string $mensagem): void
    {
        $n = new Notificacao();
        $n->setUsuario($user);
        $n->setSolicitacao($sol);
        $n->setTipo($tipo);
        $n->setMensagem($mensagem);
        $this->em->persist($n);
    }

    private function dispatchEmail(string $tipo, int $solicitacaoId, ?int $destinatarioId = null): void
    {
        try {
            $this->bus->dispatch(new EnviarEmailSolicitacao($tipo, $solicitacaoId, $destinatarioId));
        } catch (\Throwable $e) {
            $this->logger->warning('SolicitacaoService: falha ao despachar e-mail', [
                'tipo'  => $tipo,
                'id'    => $solicitacaoId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
