<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\RadarWazeLink;
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
 * Estrutura real da planilha (cabeçalho na linha 6, dados a partir da linha 7):
 *
 *   col 0  (A)  STATUS           → "ATIVO" | "DELETAR RADAR" | ...
 *   col 1  (B)  Nº DA LINHA      → número sequencial (ignorado)
 *   col 2  (C)  Nº DE SÉRIE     → chave de busca em radar_faixa.numero_serie
 *   col 3  (D)  (vazio)
 *   col 4  (E)  OBSERVAÇÃO      → texto livre (opcional, guardado em observacao)
 *   col 5  (F)  PERMALINK        → URL do Waze Editor (permanentHazards=XXXXX)
 *   col 6  (G)  LATITUDE         → ignorada (já está no link)
 *   col 7  (H)  LONGITUDE        → ignorada
 *   col 8  (I)  (vazio)
 *   col 9  (J)  Nº DO ID         → permanentHazardId (redundante com o link)
 *   col 10 (K)  (vazio)
 *   col 11 (L)  CIDADE           → ignorada
 *
 * Uso:
 *   php bin/console app:import-radar-waze-link-planilha
 *   php bin/console app:import-radar-waze-link-planilha --dry-run
 *   php bin/console app:import-radar-waze-link-planilha --url="https://..."
 *   php bin/console app:import-radar-waze-link-planilha --atualizar
 *   php bin/console app:import-radar-waze-link-planilha --apenas-ativos
 */
#[AsCommand(
    name: 'app:import-radar-waze-link-planilha',
    description: 'Importa Permalinks Waze para radares via planilha Google Sheets (CSV público)',
)]
class ImportRadarWazeLinkPlanilhaCommand extends Command
{
    // Colunas (0-based)
    private const COL_STATUS    = 0;  // A → ATIVO | DELETAR RADAR
    private const COL_SERIE     = 2;  // C → Nº de Série
    private const COL_OBS       = 4;  // E → Observação
    private const COL_PERMALINK = 5;  // F → URL Waze

    // Linhas a pular (1-based, como na planilha)
    private const PRIMEIRA_LINHA_DADOS = 7;  // linhas 1-6 são cabeçalho/logo

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
            ->addOption('url',          null, InputOption::VALUE_REQUIRED, 'URL do CSV da planilha (substitui o padrão)')
            ->addOption('dry-run',      null, InputOption::VALUE_NONE,     'Simula sem gravar no banco')
            ->addOption('atualizar',    null, InputOption::VALUE_NONE,     'Sobrescreve links Waze já existentes')
            ->addOption('apenas-ativos',null, InputOption::VALUE_NONE,     'Processa apenas linhas com STATUS=ATIVO (ignora DELETAR RADAR etc.)'
        );
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
        // 1. Download
        // ------------------------------------------------------------------
        $io->section('Baixando CSV...');
        $io->text('URL: ' . $url);

        $csv = $this->downloadCsv($url, $erro);

        if ($csv === null) {
            $io->error(sprintf('Falha ao baixar o CSV: %s', $erro ?? 'erro desconhecido'));
            return Command::FAILURE;
        }

        // ------------------------------------------------------------------
        // 2. Parse e filtragem de linhas
        // ------------------------------------------------------------------
        $todasLinhas  = $this->parseCsv($csv);
        $totalLinhas  = count($todasLinhas);
        $io->text(sprintf('Total de linhas no CSV (incluindo cabeçalho): %d', $totalLinhas));

        // Pula as primeiras (PRIMEIRA_LINHA_DADOS - 1) linhas de cabeçalho
        $linhasDados = array_slice($todasLinhas, self::PRIMEIRA_LINHA_DADOS - 1);
        $io->text(sprintf('Linhas de dados (a partir da linha %d): %d', self::PRIMEIRA_LINHA_DADOS, count($linhasDados)));

        // ------------------------------------------------------------------
        // 3. Processar
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
            $linhaNum   = self::PRIMEIRA_LINHA_DADOS + $idx;
            $status     = $this->col($cols, self::COL_STATUS);
            $numeroSerie = $this->col($cols, self::COL_SERIE);
            $permalink  = $this->col($cols, self::COL_PERMALINK);
            $observacao = $this->col($cols, self::COL_OBS);

            // Pula linhas de status não-ativo se a flag estiver ativa
            if ($apenasAtivos && mb_strtoupper($status) !== 'ATIVO') {
                $stats['pulados_status']++;
                continue;
            }

            // Pula linhas sem número de série
            if ($numeroSerie === '') {
                $stats['sem_serie']++;
                continue;
            }

            // Pula linhas sem permalink
            if ($permalink === '' || !str_starts_with($permalink, 'http')) {
                $io->text(sprintf(
                    '  <comment>[SEM LINK]</comment>     Linha %d | Série %s | status=%s',
                    $linhaNum, $numeroSerie, $status
                ));
                $stats['link_invalido']++;
                continue;
            }

            // Valida o permalink (deve ter permanentHazards)
            $hazardId = RadarWazeLink::extractPermanentHazardId($permalink);
            if ($hazardId === null) {
                $io->text(sprintf(
                    '  <comment>[LINK INVÁLIDO]</comment> Linha %d | Série %s | sem permanentHazards no link',
                    $linhaNum, $numeroSerie
                ));
                $stats['link_invalido']++;
                continue;
            }

            // Busca pelo número de série na tabela radar_faixa
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
                        ->setInsertedAt(new \DateTimeImmutable());

                    if ($observacao !== '') {
                        $link->setObservacao($observacao);
                    }

                    // insertedBy é nullable=false na entidade.
                    // TODO: setar um User "sistema" com setInsertedBy($userSistema).
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
        // 4. Relatório
        // ------------------------------------------------------------------
        $io->section('Resultado');
        $io->definitionList(
            ['Vinculados (novos)'             => $stats['vinculados']],
            ['Atualizados'                    => $stats['atualizados']],
            ['Já tinham link (pulados)'       => $stats['ja_tem_link']],
            ['Série não encontrada no BD'    => $stats['serie_nao_found']],
            ['Sem permalink / link inválido'  => $stats['link_invalido']],
            ['Pulados por status'             => $stats['pulados_status']],
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

    /** Retorna a coluna $idx da linha, ou '' se não existir. */
    private function col(array $cols, int $idx): string
    {
        return isset($cols[$idx]) ? trim($cols[$idx]) : '';
    }

    /**
     * Download via cURL (com fallback para file_get_contents).
     */
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

    /**
     * Parseia CSV → array de linhas (array de colunas).
     *
     * @return array<int, array<int, string>>
     */
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
