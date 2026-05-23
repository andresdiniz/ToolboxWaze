<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\RadarWazeLink;
use App\Entity\User;
use App\Repository\RadarFaixaRepository;
use App\Repository\RadarWazeLinkRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Importa links Waze (Permalink) para radares existentes no BD
 * a partir de uma planilha Google Sheets publicada em CSV.
 *
 * Estrutura real do CSV (verificada inspecionando o CSV bruto):
 *
 *   col 0  (A)  STATUS
 *   col 1  (B)  Nº DA LINHA
 *   col 2  (C)  Nº DE SÉRIE      ← chave de busca
 *   col 3  (D)  OBSERVAÇÃO
 *   col 4-6     (vazias)
 *   col 7  (H)  PERMALINK        ← URL Waze
 *   col 8  (I)  LATITUDE
 *   col 9  (J)  LONGITUDE
 *   col 10 (K)  Nº DO ID
 *   col 11 (L)  CIDADE
 *
 * Dados começam na linha 7 (linhas 1-6 = logo/cabeçalho).
 */
#[AsCommand(
    name: 'app:import-radar-waze-link-planilha',
    description: 'Importa Permalinks Waze para radares via planilha Google Sheets (CSV público)',
)]
class ImportRadarWazeLinkPlanilhaCommand extends Command
{
    // Colunas (0-based) — verificadas no CSV bruto
    private const COL_STATUS    = 0;
    private const COL_SERIE     = 2;
    private const COL_OBS       = 3;
    private const COL_PERMALINK = 7;

    private const PRIMEIRA_LINHA_DADOS = 7;

    /** E-mail do usuário usado como "inserted_by" nas importações automáticas */
    private const IMPORTADOR_EMAIL = 'andresoaresdiniz201218@gmail.com';

    private const DEFAULT_URL = 'https://docs.google.com/spreadsheets/d/e/2PACX-1vStcjyVJXsqv6YgCNHobs46Y2Au002IjlKl3n0JCWQqEUyJM0s2TaCrw8N_D7Hbcu52rtaEIcxQb23Y/pub?gid=0&single=true&output=csv';

    public function __construct(
        private readonly EntityManagerInterface  $em,
        private readonly RadarFaixaRepository    $faixaRepo,
        private readonly RadarWazeLinkRepository $wazeLinkRepo,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('url',           null, InputOption::VALUE_REQUIRED, 'URL do CSV da planilha (substitui o padrão)')
            ->addOption('dry-run',       null, InputOption::VALUE_NONE,     'Simula sem gravar no banco')
            ->addOption('atualizar',     null, InputOption::VALUE_NONE,     'Sobrescreve links Waze já existentes')
            ->addOption('apenas-ativos', null, InputOption::VALUE_NONE,     'Processa apenas linhas com STATUS=ATIVO');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io           = new SymfonyStyle($input, $output);
        $dryRun       = (bool) $input->getOption('dry-run');
        $update       = (bool) $input->getOption('atualizar');
        $apenasAtivos = (bool) $input->getOption('apenas-ativos');
        $url          = $input->getOption('url') ?? self::DEFAULT_URL;

        $io->title('Importar Links Waze via Planilha');

        if ($dryRun) {
            $io->warning('Modo DRY-RUN — nenhuma alteração será gravada.');
        }
        if ($apenasAtivos) {
            $io->note('Filtrando: apenas linhas com STATUS = ATIVO.');
        }

        // ------------------------------------------------------------------
        // 1. Resolve o usuário importador (inserted_by)
        // ------------------------------------------------------------------
        $userImportador = $this->em->getRepository(User::class)
            ->findOneBy(['email' => self::IMPORTADOR_EMAIL]);

        if ($userImportador === null) {
            $io->error(sprintf(
                'Usuário importador não encontrado no banco: %s\nCrie o usuário ou ajuste a constante IMPORTADOR_EMAIL.',
                self::IMPORTADOR_EMAIL
            ));
            return Command::FAILURE;
        }

        $io->text(sprintf('Usuário importador: %s (id=%d)', $userImportador->getEmail(), $userImportador->getId()));

        // ------------------------------------------------------------------
        // 2. Download
        // ------------------------------------------------------------------
        $io->section('Baixando CSV...');
        $io->text('URL: ' . $url);

        $csv = $this->downloadCsv($url, $erro);

        if ($csv === null) {
            $io->error(sprintf('Falha ao baixar o CSV: %s', $erro ?? 'erro desconhecido'));
            return Command::FAILURE;
        }

        // ------------------------------------------------------------------
        // 3. Parse
        // ------------------------------------------------------------------
        $todasLinhas = $this->parseCsv($csv);
        $io->text(sprintf('Total de linhas no CSV (incluindo cabeçalho): %d', count($todasLinhas)));

        $linhasDados = array_slice($todasLinhas, self::PRIMEIRA_LINHA_DADOS - 1);
        $io->text(sprintf('Linhas de dados (a partir da linha %d): %d', self::PRIMEIRA_LINHA_DADOS, count($linhasDados)));

        // ------------------------------------------------------------------
        // 4. Processar
        // ------------------------------------------------------------------
        $io->section('Processando...');

        $stats = [
            'vinculados'      => 0,
            'atualizados'     => 0,
            'ja_tem_link'     => 0,
            'serie_nao_found' => 0,
            'link_invalido'   => 0,
            'pulados_status'  => 0,
            'sem_serie'       => 0,
        ];

        $naoEncontrados = [];

        foreach ($linhasDados as $idx => $cols) {
            $linhaNum    = self::PRIMEIRA_LINHA_DADOS + $idx;
            $status      = $this->col($cols, self::COL_STATUS);
            $numeroSerie = $this->col($cols, self::COL_SERIE);
            $permalink   = $this->col($cols, self::COL_PERMALINK);
            $observacao  = $this->col($cols, self::COL_OBS);

            if ($apenasAtivos && mb_strtoupper($status) !== 'ATIVO') {
                $stats['pulados_status']++;
                continue;
            }

            if ($numeroSerie === '') {
                $stats['sem_serie']++;
                continue;
            }

            if ($permalink === '' || !str_starts_with($permalink, 'http')) {
                $io->text(sprintf(
                    '  <comment>[SEM LINK]</comment>     Linha %d | Série %s | status=%s',
                    $linhaNum, $numeroSerie, $status
                ));
                $stats['link_invalido']++;
                continue;
            }

            $hazardId = RadarWazeLink::extractPermanentHazardId($permalink);
            if ($hazardId === null) {
                $io->text(sprintf(
                    '  <comment>[LINK INVÁLIDO]</comment> Linha %d | Série %s | sem permanentHazards',
                    $linhaNum, $numeroSerie
                ));
                $stats['link_invalido']++;
                continue;
            }

            $faixa = $this->faixaRepo->findOneBy(['numeroSerie' => $numeroSerie]);

            if ($faixa === null) {
                $io->text(sprintf(
                    '  <comment>[NÃO ENCONTRADO]</comment> Linha %d | Série %s',
                    $linhaNum, $numeroSerie
                ));
                $stats['serie_nao_found']++;
                $naoEncontrados[] = sprintf('Linha %d: %s (status=%s)', $linhaNum, $numeroSerie, $status);
                continue;
            }

            $radar    = $faixa->getRadarMedidor();
            $existing = $this->wazeLinkRepo->findOneBy(['radarMedidor' => $radar]);

            if ($existing !== null && !$update) {
                $io->text(sprintf(
                    '  <info>[JÁ TEM LINK]</info>   Linha %d | Série %s → Radar #%d (use --atualizar)',
                    $linhaNum, $numeroSerie, $radar->getId()
                ));
                $stats['ja_tem_link']++;
                continue;
            }

            if (!$dryRun) {
                if ($existing !== null) {
                    $existing->setWazeLink($permalink);
                    $existing->setUpdatedAt(new \DateTimeImmutable());
                    $existing->setUpdatedBy($userImportador);
                    if ($observacao !== '') {
                        $existing->setObservacao($observacao);
                    }
                    $this->em->persist($existing);
                    $stats['atualizados']++;
                    $io->text(sprintf(
                        '  <comment>[ATUALIZADO]</comment>  Linha %d | Série %s → Radar #%d | hazard=%d',
                        $linhaNum, $numeroSerie, $radar->getId(), $hazardId
                    ));
                } else {
                    $link = (new RadarWazeLink())
                        ->setRadarMedidor($radar)
                        ->setWazeLink($permalink)
                        ->setInsertedAt(new \DateTimeImmutable())
                        ->setInsertedBy($userImportador);

                    if ($observacao !== '') {
                        $link->setObservacao($observacao);
                    }

                    $this->em->persist($link);
                    $stats['vinculados']++;
                    $io->text(sprintf(
                        '  <info>[VINCULADO]</info>   Linha %d | Série %s → Radar #%d | hazard=%d | status=%s',
                        $linhaNum, $numeroSerie, $radar->getId(), $hazardId, $status
                    ));
                }
            } else {
                $acao = $existing ? 'ATUALIZARIA' : 'VINCULARIA';
                $io->text(sprintf(
                    '  <info>[DRY-RUN %s]</info> Linha %d | Série %s → Radar #%d | hazard=%d | status=%s',
                    $acao, $linhaNum, $numeroSerie, $radar->getId(), $hazardId, $status
                ));
                $existing ? $stats['atualizados']++ : $stats['vinculados']++;
            }
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        // ------------------------------------------------------------------
        // 5. Relatório
        // ------------------------------------------------------------------
        $io->section('Resultado');
        $io->definitionList(
            ['Vinculados (novos)'            => $stats['vinculados']],
            ['Atualizados'                   => $stats['atualizados']],
            ['Já tinham link (pulados)'      => $stats['ja_tem_link']],
            ['Série não encontrada no BD'    => $stats['serie_nao_found']],
            ['Sem permalink / link inválido' => $stats['link_invalido']],
            ['Pulados por status'            => $stats['pulados_status']],
            ['Sem número de série (vazios)'  => $stats['sem_serie']],
        );

        if (!empty($naoEncontrados)) {
            $io->section('Nº de Série não encontrados no banco:');
            $io->listing($naoEncontrados);
        }

        $io->success($dryRun
            ? 'Simulação concluída. Rode sem --dry-run para gravar.'
            : 'Importação concluída.'
        );

        return Command::SUCCESS;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function col(array $cols, int $idx): string
    {
        return isset($cols[$idx]) ? trim($cols[$idx]) : '';
    }

    private function downloadCsv(string $url, ?string &$erro = null): ?string
    {
        if (!function_exists('curl_init')) {
            $ctx = stream_context_create([
                'http' => [
                    'method'          => 'GET',
                    'follow_location' => 1,
                    'timeout'         => 30,
                    'header'          => "User-Agent: Mozilla/5.0 (compatible; ToolboxWaze/1.0)\r\nAccept: text/csv,*/*",
                ],
            ]);
            $content = @file_get_contents($url, false, $ctx);
            if ($content === false || strlen($content) === 0) {
                $erro = 'file_get_contents falhou e cURL não está disponível';
                return null;
            }
            return $content;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; ToolboxWaze/1.0)',
            CURLOPT_HTTPHEADER     => ['Accept: text/csv,text/plain,*/*'],
        ]);

        $content  = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErro = curl_error($ch);
        curl_close($ch);

        if ($content === false || $curlErro !== '') {
            $erro = 'cURL error: ' . $curlErro;
            return null;
        }
        if ($httpCode !== 200) {
            $erro = sprintf('HTTP %d', $httpCode);
            return null;
        }
        if (strlen((string) $content) === 0) {
            $erro = 'Resposta vazia';
            return null;
        }

        return (string) $content;
    }

    private function parseCsv(string $csv): array
    {
        $linhas = [];
        $csv    = str_replace(["\r\n", "\r"], "\n", $csv);

        foreach (explode("\n", $csv) as $linha) {
            $linha = trim($linha);
            if ($linha === '') {
                continue;
            }
            $linhas[] = array_map('trim', str_getcsv($linha, ',', '"'));
        }

        return $linhas;
    }
}
