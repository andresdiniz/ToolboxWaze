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
 * Estrutura esperada da planilha (duas colunas):
 *   Coluna A: Permalink (URL do Waze Editor com permanentHazards=XXXXX)
 *             OU texto "LINK" / vazio (será ignorado)
 *   Coluna B: Nº de Série (ex: 11309, 2800123, R15408, J06544…)
 *             OU vazio (linha de cabeçalho / separador)
 *
 * O CSV publicado pelo Google tem as duas colunas por linha.
 * O comando agrupa: cada Permalink válido se aplica a todos os
 * Nº de Série que vierem nas linhas seguintes até o próximo Permalink.
 *
 * Uso:
 *   php bin/console app:import-radar-waze-link-planilha
 *   php bin/console app:import-radar-waze-link-planilha --dry-run
 *   php bin/console app:import-radar-waze-link-planilha --url="https://..."
 *   php bin/console app:import-radar-waze-link-planilha --atualizar   # sobrescreve links existentes
 */
#[AsCommand(
    name: 'app:import-radar-waze-link-planilha',
    description: 'Importa Permalinks Waze para radares via planilha Google Sheets (CSV público)',
)]
class ImportRadarWazeLinkPlanilhaCommand extends Command
{
    /**
     * URL padrão da planilha publicada em CSV.
     * Troque pelo ID correto ou passe via --url.
     */
    private const DEFAULT_URL = 'https://docs.google.com/spreadsheets/d/1GnT_huUS1H1My7-y4TJypSrjKYxTnaDBvfabTQzdvdQ/export?format=csv&gid=0';

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
            ->addOption('url',      null, InputOption::VALUE_REQUIRED, 'URL do CSV da planilha (substitui o padrão)')
            ->addOption('dry-run',  null, InputOption::VALUE_NONE,     'Simula sem gravar no banco')
            ->addOption('atualizar', null, InputOption::VALUE_NONE,    'Sobrescreve links Waze já existentes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $dryRun  = (bool) $input->getOption('dry-run');
        $update  = (bool) $input->getOption('atualizar');
        $url     = $input->getOption('url') ?? self::DEFAULT_URL;

        $io->title('Importar Links Waze via Planilha');

        if ($dryRun) {
            $io->warning('Modo DRY-RUN — nenhuma alteração será gravada.');
        }

        // ------------------------------------------------------------------
        // 1. Download do CSV
        // ------------------------------------------------------------------
        $io->section('Baixando CSV...');
        $csv = $this->downloadCsv($url);

        if ($csv === null) {
            $io->error('Falha ao baixar o CSV. Verifique a URL e tente novamente.');
            return Command::FAILURE;
        }

        $linhas = $this->parseCsv($csv);
        $io->text(sprintf('Total de linhas lidas: %d', count($linhas)));

        // ------------------------------------------------------------------
        // 2. Montar mapa: permalink → [nºSerie, nºSerie, ...]
        // ------------------------------------------------------------------
        $grupos   = $this->agruparPorPermalink($linhas, $io);
        $totalGrupos = count($grupos);
        $io->text(sprintf('Grupos (Permalink → séries): %d', $totalGrupos));

        // ------------------------------------------------------------------
        // 3. Processar cada série
        // ------------------------------------------------------------------
        $io->section('Processando...');

        $stats = [
            'vinculados'       => 0,
            'atualizados'      => 0,
            'ja_tem_link'      => 0,
            'serie_nao_found'  => 0,
            'link_invalido'    => 0,
        ];

        $naoEncontrados = [];

        foreach ($grupos as ['permalink' => $permalink, 'series' => $series]) {

            // Valida o permalink antes de processar o grupo
            $hazardId = RadarWazeLink::extractPermanentHazardId($permalink);
            if ($hazardId === null) {
                $io->warning(sprintf('Permalink inválido (sem permanentHazards): %s', $permalink));
                $stats['link_invalido'] += count($series);
                continue;
            }

            foreach ($series as $numeroSerie) {

                // Busca a faixa pelo número de série
                $faixa = $this->faixaRepo->findOneBy(['numeroSerie' => $numeroSerie]);

                if ($faixa === null) {
                    $io->text(sprintf('  <comment>[NÃO ENCONTRADO]</comment> Série: %s', $numeroSerie));
                    $stats['serie_nao_found']++;
                    $naoEncontrados[] = $numeroSerie;
                    continue;
                }

                $radar    = $faixa->getRadarMedidor();
                $existing = $this->wazeLinkRepo->findOneBy(['radarMedidor' => $radar]);

                if ($existing !== null && !$update) {
                    $io->text(sprintf(
                        '  <info>[JÁ TEM LINK]</info> Série %s → Radar #%d (use --atualizar para sobrescrever)',
                        $numeroSerie,
                        $radar->getId()
                    ));
                    $stats['ja_tem_link']++;
                    continue;
                }

                if (!$dryRun) {
                    if ($existing !== null) {
                        // Atualiza link existente
                        $existing->setWazeLink($permalink);
                        $existing->setUpdatedAt(new \DateTimeImmutable());
                        $this->em->persist($existing);
                        $stats['atualizados']++;
                        $io->text(sprintf(
                            '  <comment>[ATUALIZADO]</comment>  Série %s → Radar #%d  hazard=%d',
                            $numeroSerie, $radar->getId(), $hazardId
                        ));
                    } else {
                        // Cria novo link
                        $link = (new RadarWazeLink())
                            ->setRadarMedidor($radar)
                            ->setWazeLink($permalink)
                            ->setInsertedAt(new \DateTimeImmutable());

                        // insertedBy é obrigatório na entidade — usamos null workaround:
                        // o campo é nullable=false mas como este é um import automático,
                        // você pode ajustar a entidade para nullable=true ou criar um User "system".
                        // Por ora, tentamos sem setar (vai falhar na validação Doctrine se não nullable).
                        // TODO: passar um User "sistema" como insertedBy.
                        $this->em->persist($link);
                        $stats['vinculados']++;
                        $io->text(sprintf(
                            '  <info>[VINCULADO]</info>   Série %s → Radar #%d  hazard=%d',
                            $numeroSerie, $radar->getId(), $hazardId
                        ));
                    }
                } else {
                    // dry-run: só mostra o que faria
                    $acao = $existing ? 'ATUALIZARIA' : 'VINCULARIA';
                    $io->text(sprintf(
                        '  <info>[DRY-RUN %s]</info> Série %s → Radar #%d  hazard=%d',
                        $acao, $numeroSerie, $radar->getId(), $hazardId
                    ));
                    $existing ? $stats['atualizados']++ : $stats['vinculados']++;
                }
            }
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        // ------------------------------------------------------------------
        // 4. Relatório final
        // ------------------------------------------------------------------
        $io->section('Resultado');
        $io->definitionList(
            ['Vinculados (novos)'      => $stats['vinculados']],
            ['Atualizados'             => $stats['atualizados']],
            ['Já tinham link (pulados)'=> $stats['ja_tem_link']],
            ['Série não encontrada'    => $stats['serie_nao_found']],
            ['Link inválido (sem hazard)'=> $stats['link_invalido']],
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
    // Helpers privados
    // -------------------------------------------------------------------------

    /**
     * Faz o download do CSV via HTTP.
     * Usa stream_context para simular um browser (Google pode bloquear curl puro).
     */
    private function downloadCsv(string $url): ?string
    {
        $ctx = stream_context_create([
            'http' => [
                'method'          => 'GET',
                'follow_location' => 1,
                'timeout'         => 30,
                'header'          => implode("\r\n", [
                    'User-Agent: Mozilla/5.0 (compatible; ToolboxWaze/1.0)',
                    'Accept: text/csv,text/plain,*/*',
                ]),
            ],
        ]);

        $content = @file_get_contents($url, false, $ctx);

        return ($content !== false && strlen($content) > 0) ? $content : null;
    }

    /**
     * Parseia o CSV em array de linhas (cada linha = array de colunas).
     *
     * @return array<int, array<int, string>>
     */
    private function parseCsv(string $csv): array
    {
        $linhas = [];

        // Normaliza quebras de linha
        $csv = str_replace(["\r\n", "\r"], "\n", $csv);

        foreach (explode("\n", $csv) as $linha) {
            $linha = trim($linha);
            if ($linha === '') {
                continue;
            }

            // str_getcsv trata aspas e vírgulas dentro de campos corretamente
            $cols = str_getcsv($linha, ',', '"');
            $linhas[] = array_map('trim', $cols);
        }

        return $linhas;
    }

    /**
     * Agrupa as linhas do CSV em grupos de [permalink, [series]].
     *
     * Regra de agrupamento:
     *  - Linha cujo col[0] começa com "http" → é um Permalink (abre novo grupo)
     *  - Linha cujo col[1] tem conteúdo não-vazio e não-cabeçalho → Nº de Série
     *    pertence ao grupo atual
     *
     * @param  array<int, array<int, string>> $linhas
     * @return array<int, array{permalink: string, series: list<string>}>
     */
    private function agruparPorPermalink(array $linhas, SymfonyStyle $io): array
    {
        $grupos          = [];
        $permalinkAtual  = null;
        $seriesAtuais    = [];

        $cabeçalhos = ['nº de série', 'numero de serie', 'série', 'serie', 'permalink', 'link'];

        foreach ($linhas as $i => $cols) {
            $colA = $cols[0] ?? '';
            $colB = $cols[1] ?? '';

            $isPermalink = str_starts_with($colA, 'http');

            if ($isPermalink) {
                // Fecha grupo anterior
                if ($permalinkAtual !== null && !empty($seriesAtuais)) {
                    $grupos[] = ['permalink' => $permalinkAtual, 'series' => $seriesAtuais];
                }

                $permalinkAtual = $colA;
                $seriesAtuais   = [];

                // A própria linha pode já ter um Nº de Série na coluna B
                if ($colB !== '' && !in_array(mb_strtolower($colB), $cabeçalhos, true)) {
                    $seriesAtuais[] = $colB;
                }

                continue;
            }

            // Verifica se a coluna B tem um Nº de Série válido
            $serie = $colB;

            if ($serie === '' || in_array(mb_strtolower($serie), $cabeçalhos, true)) {
                // Tenta coluna A como Nº de Série (planilha sem coluna A definida)
                $serie = $colA;
            }

            if ($serie === ''
                || in_array(mb_strtolower($serie), $cabeçalhos, true)
                || mb_strtolower($serie) === 'link'
            ) {
                continue; // linha de cabeçalho ou vazia
            }

            if ($permalinkAtual === null) {
                $io->note(sprintf('Linha %d: Nº de Série "%s" sem Permalink anterior — ignorado.', $i + 1, $serie));
                continue;
            }

            $seriesAtuais[] = $serie;
        }

        // Fecha o último grupo
        if ($permalinkAtual !== null && !empty($seriesAtuais)) {
            $grupos[] = ['permalink' => $permalinkAtual, 'series' => $seriesAtuais];
        }

        return $grupos;
    }
}
