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
 *   php bin/console app:import-radar-waze-link-planilha --atualizar
 */
#[AsCommand(
    name: 'app:import-radar-waze-link-planilha',
    description: 'Importa Permalinks Waze para radares via planilha Google Sheets (CSV público)',
)]
class ImportRadarWazeLinkPlanilhaCommand extends Command
{
    /**
     * URL pública do CSV (aba "Permalink" — gid=0).
     * Gerada via: Arquivo → Compartilhar → Publicar na Web → CSV.
     */
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
            ->addOption('url',       null, InputOption::VALUE_REQUIRED, 'URL do CSV da planilha (substitui o padrão)')
            ->addOption('dry-run',   null, InputOption::VALUE_NONE,     'Simula sem gravar no banco')
            ->addOption('atualizar', null, InputOption::VALUE_NONE,     'Sobrescreve links Waze já existentes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $update = (bool) $input->getOption('atualizar');
        $url    = $input->getOption('url') ?? self::DEFAULT_URL;

        $io->title('Importar Links Waze via Planilha');

        if ($dryRun) {
            $io->warning('Modo DRY-RUN — nenhuma alteração será gravada.');
        }

        // ------------------------------------------------------------------
        // 1. Download do CSV via cURL (mais confiável em hospedagem compartilhada)
        // ------------------------------------------------------------------
        $io->section('Baixando CSV...');
        $io->text('URL: ' . $url);

        $csv = $this->downloadCsv($url, $erro);

        if ($csv === null) {
            $io->error(sprintf('Falha ao baixar o CSV: %s', $erro ?? 'erro desconhecido'));
            return Command::FAILURE;
        }

        $linhas = $this->parseCsv($csv);
        $io->text(sprintf('Total de linhas lidas: %d', count($linhas)));

        // ------------------------------------------------------------------
        // 2. Montar mapa: permalink → [nºSerie, nºSerie, ...]
        // ------------------------------------------------------------------
        $grupos      = $this->agruparPorPermalink($linhas, $io);
        $totalGrupos = count($grupos);
        $io->text(sprintf('Grupos (Permalink → séries): %d', $totalGrupos));

        if ($totalGrupos === 0) {
            $io->warning('Nenhum grupo encontrado. Verifique o formato da planilha.');
            return Command::SUCCESS;
        }

        // ------------------------------------------------------------------
        // 3. Processar cada série
        // ------------------------------------------------------------------
        $io->section('Processando...');

        $stats = [
            'vinculados'      => 0,
            'atualizados'     => 0,
            'ja_tem_link'     => 0,
            'serie_nao_found' => 0,
            'link_invalido'   => 0,
        ];

        $naoEncontrados = [];

        foreach ($grupos as ['permalink' => $permalink, 'series' => $series]) {

            $hazardId = RadarWazeLink::extractPermanentHazardId($permalink);
            if ($hazardId === null) {
                $io->warning(sprintf('Permalink inválido (sem permanentHazards): %s', $permalink));
                $stats['link_invalido'] += count($series);
                continue;
            }

            foreach ($series as $numeroSerie) {

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
                        $existing->setWazeLink($permalink);
                        $existing->setUpdatedAt(new \DateTimeImmutable());
                        $this->em->persist($existing);
                        $stats['atualizados']++;
                        $io->text(sprintf(
                            '  <comment>[ATUALIZADO]</comment>  Série %s → Radar #%d  hazard=%d',
                            $numeroSerie, $radar->getId(), $hazardId
                        ));
                    } else {
                        $link = (new RadarWazeLink())
                            ->setRadarMedidor($radar)
                            ->setWazeLink($permalink)
                            ->setInsertedAt(new \DateTimeImmutable());

                        // insertedBy é nullable=false na entidade.
                        // TODO: criar User "sistema" e setar aqui com setInsertedBy($userSistema).
                        $this->em->persist($link);
                        $stats['vinculados']++;
                        $io->text(sprintf(
                            '  <info>[VINCULADO]</info>   Série %s → Radar #%d  hazard=%d',
                            $numeroSerie, $radar->getId(), $hazardId
                        ));
                    }
                } else {
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
            ['Vinculados (novos)'         => $stats['vinculados']],
            ['Atualizados'                => $stats['atualizados']],
            ['Já tinham link (pulados)'   => $stats['ja_tem_link']],
            ['Série não encontrada'       => $stats['serie_nao_found']],
            ['Link inválido (sem hazard)' => $stats['link_invalido']],
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
     * Faz o download do CSV via cURL com follow de redirecionamentos.
     * Mais confiável que file_get_contents em hospedagem compartilhada.
     *
     * @param string      $url
     * @param string|null $erro  Preenchido com mensagem de erro em caso de falha
     */
    private function downloadCsv(string $url, ?string &$erro = null): ?string
    {
        if (!function_exists('curl_init')) {
            // Fallback para file_get_contents se cURL não estiver disponível
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
            CURLOPT_FOLLOWLOCATION => true,  // segue redirecionamentos do Google
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; ToolboxWaze/1.0)',
            CURLOPT_HTTPHEADER     => [
                'Accept: text/csv,text/plain,*/*',
            ],
        ]);

        $content  = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErro = curl_error($ch);
        curl_close($ch);

        if ($content === false || $curlErro !== '') {
            $erro = sprintf('cURL error: %s', $curlErro);
            return null;
        }

        if ($httpCode !== 200) {
            $erro = sprintf('HTTP %d ao acessar a planilha', $httpCode);
            return null;
        }

        if (strlen((string) $content) === 0) {
            $erro = 'Resposta vazia';
            return null;
        }

        return (string) $content;
    }

    /**
     * Parseia o CSV em array de linhas (cada linha = array de colunas).
     *
     * @return array<int, array<int, string>>
     */
    private function parseCsv(string $csv): array
    {
        $linhas = [];

        $csv = str_replace(["\r\n", "\r"], "\n", $csv);

        foreach (explode("\n", $csv) as $linha) {
            $linha = trim($linha);
            if ($linha === '') {
                continue;
            }

            $cols     = str_getcsv($linha, ',', '"');
            $linhas[] = array_map('trim', $cols);
        }

        return $linhas;
    }

    /**
     * Agrupa as linhas do CSV em grupos de [permalink, [series]].
     *
     * Regra:
     *  - col[0] começa com "http" → Permalink (abre novo grupo)
     *  - col[1] não-vazio e não-cabeçalho → Nº de Série do grupo atual
     *
     * @param  array<int, array<int, string>> $linhas
     * @return array<int, array{permalink: string, series: list<string>}>
     */
    private function agruparPorPermalink(array $linhas, SymfonyStyle $io): array
    {
        $grupos         = [];
        $permalinkAtual = null;
        $seriesAtuais   = [];

        $cabecalhos = ['nº de série', 'numero de serie', 'série', 'serie', 'permalink', 'link', 'nº serie', 'n serie'];

        foreach ($linhas as $i => $cols) {
            $colA = $cols[0] ?? '';
            $colB = $cols[1] ?? '';

            $isPermalink = str_starts_with($colA, 'http');

            if ($isPermalink) {
                if ($permalinkAtual !== null && !empty($seriesAtuais)) {
                    $grupos[] = ['permalink' => $permalinkAtual, 'series' => $seriesAtuais];
                }

                $permalinkAtual = $colA;
                $seriesAtuais   = [];

                // Mesma linha pode já ter série na col B
                if ($colB !== '' && !in_array(mb_strtolower($colB), $cabecalhos, true)) {
                    $seriesAtuais[] = $colB;
                }

                continue;
            }

            // Tenta col B primeiro, depois col A
            $serie = ($colB !== '') ? $colB : $colA;

            if ($serie === ''
                || in_array(mb_strtolower($serie), $cabecalhos, true)
                || mb_strtolower($serie) === 'link'
            ) {
                continue;
            }

            if ($permalinkAtual === null) {
                $io->note(sprintf('Linha %d: Série "%s" sem Permalink anterior — ignorada.', $i + 1, $serie));
                continue;
            }

            $seriesAtuais[] = $serie;
        }

        // Fecha último grupo
        if ($permalinkAtual !== null && !empty($seriesAtuais)) {
            $grupos[] = ['permalink' => $permalinkAtual, 'series' => $seriesAtuais];
        }

        return $grupos;
    }
}
