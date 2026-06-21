<?php

declare(strict_types=1);

namespace App\Command;

use App\Message\EnviarEmailRadarRecente;
use App\MessageHandler\EnviarEmailRadarRecenteHandler;
use App\Repository\UserRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Testa e dispara notificações de radares recentes para todos os usuários de uma UF.
 *
 * MODOS:
 *
 *   --listar
 *     Apenas lista os usuários que receberiam notificação para a UF. Não envia nada.
 *     Útil para auditar a query findApprovedComAcessoUf() antes de enviar em produção.
 *
 *       php bin/console app:test-radar-recente-email --listar --uf=SP
 *
 *   --direto  (padrão quando omitido)
 *     Invoca o Handler diretamente (síncrono). O e-mail sai na hora.
 *     Ideal para testar template + Mailer sem precisar do consumer rodando.
 *     Envia para TODOS os usuários aprovados com acesso à UF.
 *
 *       php bin/console app:test-radar-recente-email --direto --uf=SP --qtd=5
 *       php bin/console app:test-radar-recente-email --uf=RJ --qtd=3          # --direto é padrão
 *
 *   --fila
 *     Enfileira via Messenger (idêntico ao fluxo do ImportRadarCommand em produção).
 *     Os e-mails só saem quando o consumer processar a fila.
 *     Use este modo para validar o comportamento real de produção.
 *
 *       php bin/console app:test-radar-recente-email --fila --uf=MG --qtd=10
 *       php bin/console messenger:consume async -vv   # processa a fila
 *
 * OPÇÕES DE FILTRO:
 *
 *   --user-email=EMAIL   Restringe o envio a um único usuário (por e-mail)
 *   --user-id=ID         Restringe o envio a um único usuário (por ID)
 *
 *   Se nenhum filtro for informado, envia para TODOS os usuários aprovados da UF.
 */
#[AsCommand(
    name: 'app:test-radar-recente-email',
    description: 'Testa/dispara notificações de radares recentes para todos os usuários de uma UF',
)]
final class TestRadarRecenteEmailCommand extends Command
{
    public function __construct(
        private readonly EnviarEmailRadarRecenteHandler $handler,
        private readonly MessageBusInterface            $bus,
        private readonly UserRepository                 $userRepo,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'uf', null,
                InputOption::VALUE_REQUIRED,
                'Sigla da UF (ex: SP, MG, RJ). Obrigatório.',
            )
            ->addOption(
                'qtd', null,
                InputOption::VALUE_REQUIRED,
                'Quantidade de novos radares a simular na mensagem',
                '3'
            )
            ->addOption(
                'direto', null,
                InputOption::VALUE_NONE,
                'Envia diretamente via Handler (síncrono, sem fila) — padrão quando nenhum modo é informado'
            )
            ->addOption(
                'fila', null,
                InputOption::VALUE_NONE,
                'Enfileira via Messenger (igual ao ImportRadarCommand em produção)'
            )
            ->addOption(
                'listar', null,
                InputOption::VALUE_NONE,
                'Apenas lista os usuários que receberiam notificação para a UF, sem enviar'
            )
            ->addOption(
                'user-email', null,
                InputOption::VALUE_REQUIRED,
                'Restringe o envio a um único usuário (filtra por e-mail)'
            )
            ->addOption(
                'user-id', null,
                InputOption::VALUE_REQUIRED,
                'Restringe o envio a um único usuário (filtra por ID)'
            )
            ->setHelp(<<<'HELP'
Exemplos de uso:

  # Ver quem receberia o e-mail para SP (sem enviar)
  php bin/console app:test-radar-recente-email --listar --uf=SP

  # Enviar para TODOS os aprovados de SP (síncrono, e-mail sai na hora)
  php bin/console app:test-radar-recente-email --uf=SP --qtd=5

  # Enviar para TODOS os aprovados de RJ via fila (como em produção)
  php bin/console app:test-radar-recente-email --fila --uf=RJ --qtd=8

  # Enviar apenas para um usuário específico (por e-mail)
  php bin/console app:test-radar-recente-email --uf=MG --user-email=fulano@email.com

  # Enviar apenas para um usuário específico (por ID)
  php bin/console app:test-radar-recente-email --fila --uf=SP --user-id=42

  # Após --fila, processar a fila:
  php bin/console messenger:consume async -vv
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io         = new SymfonyStyle($input, $output);
        $uf         = strtoupper(trim((string) $input->getOption('uf')));
        $qtd        = max(1, (int) $input->getOption('qtd'));
        $modoDireto = (bool) $input->getOption('direto');
        $modoFila   = (bool) $input->getOption('fila');
        $modoListar = (bool) $input->getOption('listar');
        $userEmail  = $input->getOption('user-email');
        $userId     = $input->getOption('user-id') ? (int) $input->getOption('user-id') : null;

        // --direto é o padrão quando nenhum modo explícito é informado
        if (!$modoDireto && !$modoFila && !$modoListar) {
            $modoDireto = true;
        }

        if ($uf === '') {
            $io->error('Informe a UF com --uf=SP (obrigatório).');
            return Command::FAILURE;
        }

        $io->title(sprintf('ToolboxWaze — Notificação de Radares Recentes [%s]', $uf));

        // ════════════════════════════════════════════════
        // MODO LISTAR — apenas exibe a tabela de usuários
        // ════════════════════════════════════════════════
        if ($modoListar) {
            $usuarios = $this->userRepo->findApprovedComAcessoUf($uf);

            $io->section(sprintf('Usuários aprovados com acesso à UF %s (%d encontrado(s))', $uf, count($usuarios)));

            if ($usuarios === []) {
                $io->warning("Nenhum usuário encontrado com acesso à UF {$uf}.");
                return Command::SUCCESS;
            }

            $rows = array_map(fn($u) => [
                $u->getId(),
                $u->getName(),
                $u->getEmail(),
                implode(', ', array_filter($u->getRoles(), fn($r) => $r !== 'ROLE_USER')),
                $u->canAccessUf($uf) ? '✔' : '✘',
            ], $usuarios);

            $io->table(['ID', 'Nome', 'E-mail', 'Roles', 'canAccessUf'], $rows);
            $io->text(sprintf('<info>%d usuário(s) receberiam notificação para %s.</info>', count($usuarios), $uf));

            return Command::SUCCESS;
        }

        // ════════════════════════════════════════════════
        // Resolve lista de destinatários
        // ════════════════════════════════════════════════
        if ($userEmail !== null) {
            $user = $this->userRepo->findOneBy(['email' => $userEmail]);
            if (!$user) {
                $io->error("Nenhum usuário encontrado com e-mail: {$userEmail}");
                return Command::FAILURE;
            }
            $usuarios = [$user];
        } elseif ($userId !== null) {
            $user = $this->userRepo->find($userId);
            if (!$user) {
                $io->error("Nenhum usuário encontrado com ID: {$userId}");
                return Command::FAILURE;
            }
            $usuarios = [$user];
        } else {
            // Sem filtro → TODOS os aprovados com acesso à UF
            $usuarios = $this->userRepo->findApprovedComAcessoUf($uf);
        }

        if ($usuarios === []) {
            $io->warning("Nenhum usuário aprovado com acesso à UF {$uf}. Nada a enviar.");
            return Command::SUCCESS;
        }

        $modo = $modoFila ? 'FILA (Messenger)' : 'DIRETO (síncrono)';

        $io->definitionList(
            ['Modo'      => $modo],
            ['UF'        => $uf],
            ['Qtd radares' => $qtd . ' (simulado)'],
            ['Destinatários' => count($usuarios) . ' usuário(s)'],
        );

        // Exibe prévia dos destinatários
        $io->section('Destinatários');
        foreach ($usuarios as $u) {
            $acesso = $u->canAccessUf($uf) ? '<info>✔ acesso</info>' : '<comment>✘ sem acesso</comment>';
            $io->text(sprintf(
                '  #%d  %-30s %-35s %s',
                $u->getId(),
                $u->getName(),
                $u->getEmail(),
                $acesso
            ));
        }

        // Confirmação antes de enviar (evita disparo acidental em produção)
        if (!$io->confirm(sprintf(
            'Confirma o envio de %d e-mail(s) via %s?',
            count($usuarios),
            $modoFila ? 'fila' : 'handler direto'
        ), false)) {
            $io->text('Cancelado.');
            return Command::SUCCESS;
        }

        // ════════════════════════════════════════════════
        // DESPACHO — fila ou direto
        // ════════════════════════════════════════════════
        $io->section('Enviando...');
        $ok    = 0;
        $falha = 0;

        foreach ($usuarios as $u) {
            $message = new EnviarEmailRadarRecente(
                siglaUf:         $uf,
                userId:          $u->getId(),
                quantidadeNovos: $qtd,
            );

            try {
                if ($modoFila) {
                    $this->bus->dispatch($message);
                } else {
                    ($this->handler)($message);
                }

                $io->text(sprintf('  <info>✔</info> %s <%s>', $u->getName(), $u->getEmail()));
                $ok++;
            } catch (\Throwable $e) {
                $io->text(sprintf('  <comment>✘</comment> %s <%s> — %s', $u->getName(), $u->getEmail(), $e->getMessage()));
                $falha++;
            }
        }

        // ════════════════════════════════════════════════
        // Resultado
        // ════════════════════════════════════════════════
        $io->newLine();

        if ($modoFila) {
            $io->success(sprintf(
                '%d mensagem(ns) enfileirada(s). Processe com: php bin/console messenger:consume async -vv',
                $ok
            ));
        } else {
            $io->success(sprintf('%d e-mail(s) enviado(s) com sucesso.', $ok));
        }

        if ($falha > 0) {
            $io->warning(sprintf('%d e-mail(s) com falha (veja os logs do emailQueueLogger).', $falha));
        }

        return $falha === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
