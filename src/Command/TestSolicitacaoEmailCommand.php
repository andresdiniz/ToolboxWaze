<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Solicitacao;
use App\Message\EnviarEmailSolicitacao;
use App\Repository\SolicitacaoRepository;
use App\Repository\UserRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Testa cada tipo de e-mail de solicitação disparando via Messenger.
 *
 * USO:
 *   # Lista todos os tipos disponíveis
 *   php bin/console app:test-solicitacao-email
 *
 *   # Testa e-mail de confirmação para a solicitação ID 1
 *   php bin/console app:test-solicitacao-email confirmacao 1
 *
 *   # Testa e-mail para responsável (precisa do ID do usuário responsável)
 *   php bin/console app:test-solicitacao-email responsavel 1 --responsavel=2
 *
 *   # Testa e-mail de comentário como se fosse do solicitante (sem autorId)
 *   php bin/console app:test-solicitacao-email comentario 1
 *
 *   # Testa e-mail de comentário como se fosse de um responsável (com autorId)
 *   php bin/console app:test-solicitacao-email comentario 1 --autor=2
 *
 *   # Testa todos os tipos de uma vez
 *   php bin/console app:test-solicitacao-email all 1
 *
 * OBSERVAÇÃO: o worker do Messenger precisa estar rodando para processar:
 *   php bin/console messenger:consume async -vv
 */
#[AsCommand(
    name: 'app:test-solicitacao-email',
    description: 'Testa os e-mails de solicitação via Messenger (sem bloquear o front)',
)]
final class TestSolicitacaoEmailCommand extends Command
{
    private const TIPOS_SIMPLES = [
        'confirmacao',
        'resolucao',
        'status_alterado',
    ];

    private const TODOS_TIPOS = [
        'confirmacao',
        'responsavel',
        'resolucao',
        'status_alterado',
        'comentario',
    ];

    public function __construct(
        private readonly MessageBusInterface    $bus,
        private readonly SolicitacaoRepository $solicitacaoRepo,
        private readonly UserRepository        $userRepo,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'tipo',
                InputArgument::OPTIONAL,
                'Tipo de e-mail: ' . implode(' | ', self::TODOS_TIPOS) . ' | all',
            )
            ->addArgument(
                'solicitacao_id',
                InputArgument::OPTIONAL,
                'ID da solicitação no banco de dados',
            )
            ->addOption(
                'responsavel',
                null,
                InputOption::VALUE_REQUIRED,
                'ID do usuário responsável (necessário para tipo=responsavel)',
            )
            ->addOption(
                'autor',
                null,
                InputOption::VALUE_REQUIRED,
                'ID do usuário autor do comentário (para tipo=comentario com autor logado)',
            )
            ->setHelp(<<<'HELP'
Testa cada tipo de e-mail de solicitação despachando via Messenger.
O worker precisa estar rodando para os e-mails serem processados:

  php bin/console messenger:consume async -vv

Exemplos:

  # Ver tipos disponíveis e solicitações recentes
  php bin/console app:test-solicitacao-email

  # Confirmação (criação da solicitação)
  php bin/console app:test-solicitacao-email confirmacao 1

  # Resolução final (resolvida/negada/cancelada)
  php bin/console app:test-solicitacao-email resolucao 1

  # Status intermediário (em_analise, em_andamento, aguardando)
  php bin/console app:test-solicitacao-email status_alterado 1

  # Aviso ao responsável (nova pendência)
  php bin/console app:test-solicitacao-email responsavel 1 --responsavel=2

  # Comentário do solicitante → avisa responsáveis
  php bin/console app:test-solicitacao-email comentario 1

  # Comentário do responsável → avisa solicitante e demais responsáveis
  php bin/console app:test-solicitacao-email comentario 1 --autor=2

  # Dispara todos os tipos de uma só vez
  php bin/console app:test-solicitacao-email all 1
HELP)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io   = new SymfonyStyle($input, $output);
        $tipo = $input->getArgument('tipo');
        $sid  = $input->getArgument('solicitacao_id');

        if (!$tipo) {
            return $this->showHelp($io);
        }

        if (!$sid) {
            $io->error('Informe o ID da solicitação como segundo argumento.');
            return $this->showRecentSolicitacoes($io);
        }

        $solicitacao = $this->solicitacaoRepo->find((int) $sid);
        if (!$solicitacao) {
            $io->error("Solicitação #$sid não encontrada no banco.");
            return $this->showRecentSolicitacoes($io);
        }

        $this->printSolicitacaoInfo($io, $solicitacao);

        if ($tipo === 'all') {
            return $this->dispatchAll($io, $solicitacao, $input);
        }

        return $this->dispatchTipo($io, $tipo, $solicitacao, $input);
    }

    // ---------------------------------------------------------------
    // Dispatch de um tipo específico
    // ---------------------------------------------------------------

    private function dispatchTipo(
        SymfonyStyle $io,
        string $tipo,
        Solicitacao $solicitacao,
        InputInterface $input
    ): int {
        if (!in_array($tipo, self::TODOS_TIPOS, true)) {
            $io->error("Tipo desconhecido: '$tipo'. Tipos válidos: " . implode(', ', self::TODOS_TIPOS));
            return Command::FAILURE;
        }

        $responsavelId = $input->getOption('responsavel') !== null ? (int) $input->getOption('responsavel') : null;
        $autorId       = $input->getOption('autor')       !== null ? (int) $input->getOption('autor')       : null;

        if ($tipo === 'responsavel') {
            if (!$responsavelId) {
                $io->error('Para tipo=responsavel informe --responsavel=<user_id>');
                $this->listResponsaveis($io, $solicitacao);
                return Command::FAILURE;
            }
            $user = $this->userRepo->find($responsavelId);
            if (!$user) {
                $io->error("Usuário #$responsavelId não encontrado.");
                return Command::FAILURE;
            }
        }

        // Monta destinatarioId conforme tipo
        if ($tipo === 'responsavel') {
            $destinatarioId = $responsavelId;
        } elseif ($tipo === 'comentario') {
            $destinatarioId = $autorId; // null = solicitante externo, int = responsável logado
        } else {
            $destinatarioId = null;
        }

        $this->bus->dispatch(new EnviarEmailSolicitacao($tipo, $solicitacao->getId(), $destinatarioId));

        // Monta label do destinatário para exibição
        if ($tipo === 'confirmacao' || $tipo === 'resolucao' || $tipo === 'status_alterado') {
            $destLabel = $solicitacao->getSolicitanteEmail();
        } elseif ($tipo === 'responsavel') {
            $resp = $this->userRepo->find($responsavelId);
            $destLabel = $resp ? $resp->getEmail() : "user #$responsavelId";
        } elseif ($tipo === 'comentario' && $destinatarioId !== null) {
            $destLabel = sprintf('solicitante + responsáveis (exceto autor #%d)', $destinatarioId);
        } else {
            $destLabel = 'responsáveis (comentário do solicitante)';
        }

        $io->success(sprintf(
            "Mensagem '%s' despachada via Messenger para: %s\nExecute 'php bin/console messenger:consume async -vv' para processar.",
            $tipo,
            $destLabel
        ));

        return Command::SUCCESS;
    }

    // ---------------------------------------------------------------
    // Dispatch de todos os tipos de uma só vez
    // ---------------------------------------------------------------

    private function dispatchAll(
        SymfonyStyle $io,
        Solicitacao $solicitacao,
        InputInterface $input
    ): int {
        $io->section('Despachando todos os tipos');

        $responsavelId = $input->getOption('responsavel') !== null ? (int) $input->getOption('responsavel') : null;
        $autorId       = $input->getOption('autor')       !== null ? (int) $input->getOption('autor')       : null;

        $tipos = self::TIPOS_SIMPLES;

        if ($responsavelId) {
            $tipos[] = 'responsavel';
        } else {
            $io->note('Pulando tipo=responsavel (use --responsavel=<id> para incluir)');
        }

        $tipos[] = 'comentario'; // dispara sempre (sem autor = simula solicitante externo)

        $rows = [];
        foreach ($tipos as $t) {
            if ($t === 'responsavel') {
                $destId = $responsavelId;
            } elseif ($t === 'comentario') {
                $destId = $autorId;
            } else {
                $destId = null;
            }

            try {
                $this->bus->dispatch(new EnviarEmailSolicitacao($t, $solicitacao->getId(), $destId));
                $rows[] = [$t, '✅ despachado'];
            } catch (\Throwable $e) {
                $rows[] = [$t, '❌ ' . $e->getMessage()];
            }
        }

        $io->table(['Tipo', 'Resultado'], $rows);
        $io->note('Execute: php bin/console messenger:consume async -vv');

        return Command::SUCCESS;
    }

    // ---------------------------------------------------------------
    // Helpers de output
    // ---------------------------------------------------------------

    private function printSolicitacaoInfo(SymfonyStyle $io, Solicitacao $sol): void
    {
        $io->definitionList(
            ['ID'          => '#' . $sol->getId()],
            ['Tipo'        => $sol->getTipoLabel()],
            ['Status'      => $sol->getStatusLabel()],
            ['Solicitante' => $sol->getSolicitanteNome() . ' <' . $sol->getSolicitanteEmail() . '>'],
            ['Criada em'   => $sol->getCriadaEm()->format('d/m/Y H:i')],
        );
    }

    private function listResponsaveis(SymfonyStyle $io, Solicitacao $sol): void
    {
        $responsaveis = $sol->getResponsaveis();
        if ($responsaveis->isEmpty()) {
            $io->warning('Esta solicitação não tem responsáveis cadastrados.');
            return;
        }
        $io->text('Responsáveis desta solicitação:');
        $rows = [];
        foreach ($responsaveis as $r) {
            $rows[] = [$r->getId(), $r->getEmail()];
        }
        $io->table(['ID', 'E-mail'], $rows);
    }

    private function showRecentSolicitacoes(SymfonyStyle $io): int
    {
        $recent = $this->solicitacaoRepo->findBy([], ['criadaEm' => 'DESC'], 10);
        if (empty($recent)) {
            $io->warning('Nenhuma solicitação encontrada no banco de dados.');
            return Command::FAILURE;
        }
        $io->text('Solicitações recentes disponíveis para teste:');
        $rows = [];
        foreach ($recent as $s) {
            $rows[] = [
                $s->getId(),
                $s->getTipoLabel(),
                $s->getStatusLabel(),
                $s->getSolicitanteEmail(),
                $s->getCriadaEm()->format('d/m/Y H:i'),
            ];
        }
        $io->table(['ID', 'Tipo', 'Status', 'E-mail solicitante', 'Criada em'], $rows);
        return Command::FAILURE;
    }

    private function showHelp(SymfonyStyle $io): int
    {
        $io->title('app:test-solicitacao-email — Tipos disponíveis');
        $io->definitionList(
            ['confirmacao'     => 'E-mail para o solicitante ao criar a solicitação'],
            ['responsavel'     => 'Aviso ao responsável de nova pendência (use --responsavel=<id>)'],
            ['resolucao'       => 'E-mail final ao solicitante (resolvida/negada/cancelada)'],
            ['status_alterado' => 'Atualização de status intermediário ao solicitante'],
            ['comentario'      => 'Comentário público (sem --autor = solicitante; com --autor = responsável)'],
            ['all'             => 'Despacha todos os tipos de uma vez'],
        );
        $io->note('Informe o tipo e o ID: php bin/console app:test-solicitacao-email <tipo> <id>');
        return $this->showRecentSolicitacoes($io);
    }
}
