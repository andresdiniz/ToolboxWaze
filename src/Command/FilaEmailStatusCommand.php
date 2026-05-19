<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Inspeciona o status da fila do Messenger no banco de dados.
 *
 * Mostra mensagens pendentes, com falha e as últimas processadas.
 * Funciona com o transport Doctrine (tabela messenger_messages).
 *
 * USO:
 *   # Visão geral (pendentes + falhas)
 *   php bin/console app:fila-email-status
 *
 *   # Ver últimas N mensagens processadas (requer coluna delivered_at)
 *   php bin/console app:fila-email-status --historico=20
 *
 *   # Filtrar somente mensagens de e-mail
 *   php bin/console app:fila-email-status --filtro=Email
 *
 *   # Ver falhas detalhadas
 *   php bin/console app:fila-email-status --falhas
 *
 * NOTA: Com transport sync:// não há tabela messenger_messages.
 *       Neste caso o comando avisa e mostra o log em var/log/email_queue.log.
 */
#[AsCommand(
    name: 'app:fila-email-status',
    description: 'Inspeciona a fila do Messenger e o log de e-mails (email_queue.log)',
)]
final class FilaEmailStatusCommand extends Command
{
    private const TABLE_QUEUE  = 'messenger_messages';
    private const TABLE_FAILED = 'messenger_messages'; // transport 'failed' usa queue_name='failed'
    private const LOG_FILE     = 'var/log/email_queue.log';

    public function __construct(
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('historico', null, InputOption::VALUE_REQUIRED, 'Exibe as últimas N linhas do log email_queue', 50)
            ->addOption('filtro',    null, InputOption::VALUE_REQUIRED, 'Filtra linhas do log por texto (ex: Email, radar, conta)')
            ->addOption('falhas',    null, InputOption::VALUE_NONE,     'Mostra detalhes das mensagens com falha na fila Doctrine')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io         = new SymfonyStyle($input, $output);
        $historico  = (int) $input->getOption('historico');
        $filtro     = $input->getOption('filtro');
        $showFalhas = (bool) $input->getOption('falhas');

        $io->title('ToolboxWaze — Status de Fila e E-mails');

        // ── 1. Fila Doctrine ─────────────────────────────────────────
        $this->showFilaStatus($io, $showFalhas);

        // ── 2. Log email_queue ───────────────────────────────────────
        $this->showEmailLog($io, $historico, $filtro);

        return Command::SUCCESS;
    }

    // ---------------------------------------------------------------
    // Seção 1 — Fila Doctrine
    // ---------------------------------------------------------------

    private function showFilaStatus(SymfonyStyle $io, bool $showFalhas): void
    {
        $io->section('Fila Messenger (Doctrine)');

        if (!$this->tableExists()) {
            $io->warning([
                'A tabela "' . self::TABLE_QUEUE . '" não existe.',
                'O transport atual pode ser sync:// (execução imediata, sem fila).',
                'Para usar fila persistente, configure MESSENGER_TRANSPORT_DSN=doctrine://default no .env.',
            ]);
            return;
        }

        // Pendentes por queue_name
        $pendentes = $this->connection->fetchAllAssociative(
            'SELECT queue_name, COUNT(*) AS total
             FROM ' . self::TABLE_QUEUE . '
             WHERE delivered_at IS NULL
             GROUP BY queue_name
             ORDER BY total DESC'
        );

        if (empty($pendentes)) {
            $io->success('Nenhuma mensagem pendente na fila.');
        } else {
            $io->table(['Fila', 'Pendentes'], array_map(
                fn($r) => [$r['queue_name'], $r['total']],
                $pendentes
            ));
        }

        // Falhas
        $falhas = $this->connection->fetchAllAssociative(
            'SELECT id, queue_name, created_at, available_at, headers
             FROM ' . self::TABLE_QUEUE . "
             WHERE queue_name = 'failed'
             ORDER BY created_at DESC
             LIMIT 20"
        );

        if (!empty($falhas)) {
            $io->warning(count($falhas) . ' mensagem(ns) com falha encontrada(s):');

            if ($showFalhas) {
                foreach ($falhas as $f) {
                    $io->definitionList(
                        ['ID'          => $f['id']],
                        ['Fila'        => $f['queue_name']],
                        ['Criada em'   => $f['created_at']],
                        ['Disponível'  => $f['available_at']],
                        ['Headers'     => substr((string) $f['headers'], 0, 300) . '...'],
                    );
                }
            } else {
                $io->text('Use --falhas para ver os detalhes de cada mensagem.');
                $io->text('Para reprocessar: php bin/console messenger:failed:retry');
            }
        }
    }

    // ---------------------------------------------------------------
    // Seção 2 — Log email_queue.log
    // ---------------------------------------------------------------

    private function showEmailLog(SymfonyStyle $io, int $linhas, ?string $filtro): void
    {
        $io->section('Log de E-mails (email_queue.log)');

        $logPath = rtrim(getcwd(), '/') . '/' . self::LOG_FILE;

        if (!file_exists($logPath)) {
            $io->warning([
                'Arquivo ' . self::LOG_FILE . ' não encontrado.',
                'O log é criado automaticamente quando o primeiro e-mail for processado.',
                'Caminho esperado: ' . $logPath,
            ]);
            return;
        }

        $handle = fopen($logPath, 'r');
        if (!$handle) {
            $io->error('Não foi possível abrir ' . $logPath);
            return;
        }

        // Lê todas as linhas e pega as últimas N
        $todasLinhas = [];
        while (($linha = fgets($handle)) !== false) {
            $linha = rtrim($linha);
            if ($filtro === null || stripos($linha, $filtro) !== false) {
                $todasLinhas[] = $linha;
            }
        }
        fclose($handle);

        $total   = count($todasLinhas);
        $exibir  = array_slice($todasLinhas, -$linhas);

        if ($total === 0) {
            $io->success('Log vazio' . ($filtro ? " (filtro: '$filtro')" : '') . '.');
            return;
        }

        $io->text(sprintf(
            'Exibindo últimas %d de %d linha(s)%s:',
            count($exibir),
            $total,
            $filtro ? " (filtro: '$filtro')" : ''
        ));
        $io->newLine();

        foreach ($exibir as $linha) {
            // Coloriza erros/warnings no terminal
            if (str_contains($linha, '.ERROR') || str_contains($linha, '"level":"error"')) {
                $io->text('<fg=red>' . $linha . '</>');
            } elseif (str_contains($linha, '.WARNING') || str_contains($linha, '"level":"warning"')) {
                $io->text('<fg=yellow>' . $linha . '</>');
            } elseif (str_contains($linha, '.INFO') || str_contains($linha, '"level":"info"')) {
                $io->text('<fg=green>' . $linha . '</>');
            } else {
                $io->text($linha);
            }
        }

        $io->newLine();
        $io->text(sprintf('📄 Arquivo: %s (%.1f KB)', $logPath, filesize($logPath) / 1024));
    }

    // ---------------------------------------------------------------

    private function tableExists(): bool
    {
        try {
            $this->connection->executeQuery('SELECT 1 FROM ' . self::TABLE_QUEUE . ' LIMIT 1');
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
