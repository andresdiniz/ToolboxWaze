<?php

declare(strict_types=1);

namespace App\Command;

use App\Message\EnviarEmailRadarRecente;
use App\MessageHandler\EnviarEmailRadarRecenteHandler;
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
 * Testa o fluxo completo de notificação de radares recentes.
 *
 * Modos disponíveis:
 *
 *   --direto   Invoca o Handler diretamente (síncrono, sem fila).
 *              Ideal para testar o template do e-mail e a configuração do Mailer.
 *
 *   --fila     Enfileira via Messenger (padrão de produção).
 *              O e-mail só será enviado quando o consumer processar a mensagem.
 *
 * Uso:
 *   # Envia direto para um e-mail específico (sem precisar de usuário no banco)
 *   php bin/console app:test-radar-recente-email --direto --to=voce@email.com --uf=SP --qtd=5
 *
 *   # Testa com um usuário real do banco (busca pelo e-mail)
 *   php bin/console app:test-radar-recente-email --direto --user-email=voce@email.com --uf=RJ
 *
 *   # Enfileira para o Messenger consumer (teste de produção)
 *   php bin/console app:test-radar-recente-email --fila --user-email=voce@email.com --uf=SP
 *
 *   # Lista usuários que receberiam notificação para uma UF (sem enviar)
 *   php bin/console app:test-radar-recente-email --listar --uf=MG
 */
#[AsCommand(
    name: 'app:test-radar-recente-email',
    description: 'Testa o envio de e-mail de notificação de radares recentes (direto, fila ou listagem)',
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
                'direto', null,
                InputOption::VALUE_NONE,
                'Invoca o Handler diretamente (síncrono, sem fila) — padrão quando nenhum modo é informado'
            )
            ->addOption(
                'fila', null,
                InputOption::VALUE_NONE,
                'Enfileira via Messenger (igual ao fluxo de produção do ImportRadarCommand)'
            )
            ->addOption(
                'listar', null,
                InputOption::VALUE_NONE,
                'Apenas lista os usuários que receberiam notificação para a UF, sem enviar e-mail'
            )
            ->addOption(
                'uf', null,
                InputOption::VALUE_REQUIRED,
                'Sigla da UF a simular (ex: SP, MG, RJ)',
                'SP'
            )
            ->addOption(
                'qtd', null,
                InputOption::VALUE_REQUIRED,
                'Quantidade de novos radares a simular na mensagem',
                '3'
            )
            ->addOption(
                'user-email', null,
                InputOption::VALUE_REQUIRED,
                'Busca o usuário pelo e-mail no banco e usa o ID dele na mensagem'
            )
            ->addOption(
                'user-id', null,
                InputOption::VALUE_REQUIRED,
                'ID do usuário no banco (alternativo a --user-email)'
            )
            ->addOption(
                'to', null,
                InputOption::VALUE_REQUIRED,
                'E-mail destinatário ad-hoc (apenas com --direto; cria um User fake sem persistir)'
            )
            ->setHelp(<<<'HELP'
Testa o fluxo de notificação de radares recentes sem precisar rodar o ImportRadarCommand.

MODO --direto (padrão):
  Chama o EnviarEmailRadarRecenteHandler diretamente. O e-mail é enviado na hora.
  Use --to para um destinatário ad-hoc, ou --user-email/--user-id para um usuário real.

  php bin/console app:test-radar-recente-email --direto --to=voce@email.com --uf=SP --qtd=5
  php bin/console app:test-radar-recente-email --direto --user-email=fulano@email.com --uf=RJ

MODO --fila:
  Enfileira a mensagem no Messenger. O e-mail só sairá quando o consumer processar.
  Requer --user-email ou --user-id (precisa de um usuário real no banco).

  php bin/console app:test-radar-recente-email --fila --user-email=fulano@email.com --uf=SP
  php bin/console messenger:consume async -vv   # processa a fila

MODO --listar:
  Lista todos os usuários aprovados que têm acesso à UF informada.
  Útil para verificar se a query do UserRepository::findApprovedComAcessoUf() está correta.

  php bin/console app:test-radar-recente-email --listar --uf=MG
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io         = new SymfonyStyle($input, $output);
        $modoDireto = (bool) $input->getOption('direto');
        $modoFila   = (bool) $input->getOption('fila');
        $modoListar = (bool) $input->getOption('listar');
        $uf         = strtoupper(trim((string) $input->getOption('uf')));
        $qtd        = max(1, (int) $input->getOption('qtd'));
        $toAdhoc    = $input->getOption('to');
        $userEmail  = $input->getOption('user-email');
        $userId     = $input->getOption('user-id') ? (int) $input->getOption('user-id') : null;

        // Se nenhum modo explícito, usa --direto como padrão
        if (!$modoDireto && !$modoFila && !$modoListar) {
            $modoDireto = true;
        }

        $io->title('ToolboxWaze — Teste de Notificação: Radares Recentes');

        // ── MODO LISTAR ──────────────────────────────────────────────────────
        if ($modoListar) {
            return $this->executarListar($io, $uf);
        }

        // ── MODO DIRETO com --to ad-hoc ──────────────────────────────────────
        if ($modoDireto && $toAdhoc !== null) {
            return $this->executarDiretoAdhoc($io, $toAdhoc, $uf, $qtd);
        }

        // ── Resolve usuário real no banco ────────────────────────────────────
        $user = null;

        if ($userEmail !== null) {
            $user = $this->userRepo->findOneBy(['email' => $userEmail]);
            if (!$user) {
                $io->error("Nenhum usuário encontrado com e-mail: {$userEmail}");
                return Command::FAILURE;
            }
        } elseif ($userId !== null) {
            $user = $this->userRepo->find($userId);
            if (!$user) {
                $io->error("Nenhum usuário encontrado com ID: {$userId}");
                return Command::FAILURE;
            }
        } else {
            $io->error('Informe --to (ad-hoc), --user-email ou --user-id.');
            $io->text('Execute com --help para ver exemplos de uso.');
            return Command::FAILURE;
        }

        $io->definitionList(
            ['Modo'     => $modoFila ? 'Fila (Messenger)' : 'Direto (síncrono)'],
            ['UF'       => $uf],
            ['Qtd'      => $qtd . ' novos radares (simulado)'],
            ['Usuário'  => sprintf('%s <%s> #%d', $user->getName(), $user->getEmail(), $user->getId())],
            ['Acesso UF' => $user->canAccessUf($uf) ? '✔ tem acesso' : '✘ SEM acesso à UF'],
        );

        if (!$user->canAccessUf($uf)) {
            $io->warning("O usuário {$user->getEmail()} não tem acesso à UF {$uf}. O Handler irá cancelar o envio.");
            if (!$io->confirm('Continuar mesmo assim (para testar o cancelamento)?', false)) {
                return Command::SUCCESS;
            }
        }

        $message = new EnviarEmailRadarRecente(
            siglaUf:         $uf,
            userId:          $user->getId(),
            quantidadeNovos: $qtd,
        );

        // ── MODO FILA ────────────────────────────────────────────────────────
        if ($modoFila) {
            $this->bus->dispatch($message);
            $io->success(sprintf(
                'Mensagem enfileirada! Processe com: php bin/console messenger:consume async -vv'
            ));
            return Command::SUCCESS;
        }

        // ── MODO DIRETO com usuário real ─────────────────────────────────────
        try {
            $io->text('Invocando handler diretamente...');
            ($this->handler)($message);
            $io->success(sprintf(
                'E-mail enviado com sucesso para %s (%s)',
                $user->getEmail(),
                $user->getName()
            ));
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error(['Falha ao enviar:', $e->getMessage()]);
            return Command::FAILURE;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Modos auxiliares
    // ─────────────────────────────────────────────────────────────────────────

    private function executarListar(SymfonyStyle $io, string $uf): int
    {
        $io->section("Usuários aprovados com acesso à UF: {$uf}");

        $usuarios = $this->userRepo->findApprovedComAcessoUf($uf);

        if ($usuarios === []) {
            $io->warning("Nenhum usuário encontrado com acesso à UF {$uf}.");
            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($usuarios as $u) {
            $rows[] = [
                $u->getId(),
                $u->getName(),
                $u->getEmail(),
                $u->canAccessUf($uf) ? '✔' : '✘',
                implode(', ', $u->getRoles()),
            ];
        }

        $io->table(
            ['ID', 'Nome', 'E-mail', 'canAccessUf', 'Roles'],
            $rows
        );

        $io->text(sprintf('<info>%d usuário(s) receberiam notificação para %s.</info>', count($usuarios), $uf));

        return Command::SUCCESS;
    }

    private function executarDiretoAdhoc(SymfonyStyle $io, string $to, string $uf, int $qtd): int
    {
        $io->section('Modo: direto ad-hoc (destinatário avulso)');
        $io->definitionList(
            ['Para' => $to],
            ['UF'   => $uf],
            ['Qtd'  => $qtd . ' novos radares (simulado)'],
        );

        // Busca qualquer usuário ativo para usar como base (ou cria um fake)
        $user = $this->userRepo->findOneBy(['email' => $to])
            ?? $this->userRepo->findOneBy(['status' => 'approved']);

        if (!$user) {
            $io->error('Nenhum usuário aprovado no banco para usar como base. Use --user-email para indicar um usuário real.');
            return Command::FAILURE;
        }

        try {
            $message = new EnviarEmailRadarRecente(
                siglaUf:         $uf,
                userId:          $user->getId(),
                quantidadeNovos: $qtd,
            );

            $io->text(sprintf('Usando usuário base: %s <%s>', $user->getName(), $user->getEmail()));
            $io->text('Invocando handler diretamente...');
            ($this->handler)($message);

            $io->success("E-mail de teste enviado! (destinatário real: {$user->getEmail()})");
            $io->note("Para enviar para um endereço específico diferente, use --user-email com um usuário que tenha esse e-mail cadastrado.");

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error(['Falha ao enviar:', $e->getMessage()]);
            return Command::FAILURE;
        }
    }
}
