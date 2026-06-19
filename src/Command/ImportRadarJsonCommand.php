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
 * Importa radares a partir de JSON (arquivo local ou URL) em vez de planilhas CSV.
 *
 * A única planilha ainda consultada é a "Referencia.UF", usada apenas para
 * resolver SiglaUf a partir do nome do município quando o campo não está
 * presente no JSON.
 *
 * MODOS DE USO:
 *
 *   # Arquivo JSON local (medidores/expandido):
 *   bin/console app:import-radar-json --file=/caminho/para/radares.json --uf=MG
 *
 *   # URL que retorna JSON:
 *   bin/console app:import-radar-json --url=https://exemplo.com/radares.json
 *
 *   # JSON + links Waze embutidos (campo "wazeLinks" na raiz do JSON):
 *   bin/console app:import-radar-json --file=radares.json --user-id=1
 *
 *   # Somente importar links Waze de um arquivo JSON separado:
 *   bin/console app:import-radar-json --links-file=waze_links.json --user-id=1
 *
 *   # Dry-run (simula sem gravar):
 *   bin/console app:import-radar-json --file=radares.json --dry-run
 *
 * ESTRUTURA JSON ESPERADA (medidores):
 *
 *   [
 *     {
 *       "SiglaUf": "MG",
 *       "Estado": "Minas Gerais",
 *       "Municipio": "CONTAGEM",
 *       "LocalVerificacao": "BR-040 KM 510",
 *       "DataUltimaVerificacao": "15/03/2024",
 *       "DataValidade": "14/03/2025",
 *       "UltimoResultado": "Aprovado",
 *       "TipoMedidor": "Fixo",
 *       "Faixas": [
 *         {
 *           "NumeroFaixa": "1",
 *           "NumeroInmetro": "12345678",
 *           "NumeroSerie": "BRI 999",
 *           "Sentido": "BH-RIO",
 *           "VelocidadeNominal": "80"
 *         }
 *       ],
 *       "Historico": [
 *         {
 *           "NumeroCertificado": "15000001",
 *           "NumeroEnsaio": "10",
 *           "Ano": "2023",
 *           "DataLaudo": "15/03/2023",
 *           "DataValidade": "14/03/2024",
 *           "TipoServico": "Periodica",
 *           "Resultado": "Aprovado"
 *         }
 *       ],
 *       "Proprietario": { "Nome": "EMPRESA SA" }
 *     }
 *   ]
 *
 * ESTRUTURA JSON ESPERADA (wazeLinks – pode ser raiz ou campo "wazeLinks"):
 *
 *   [
 *     {
 *       "link":  "https://waze.com/pt-BR/editor?...&permanentHazards=123456",
 *       "serie": "BRI 999",
 *       "cidade": "CONTAGEM",
 *       "acao":  "UPDATE"
 *     }
 *   ]
 *
 *   OBS: os campos são case-insensitive e aceitos em variantes:
 *     link  → LINK, linkWaze, waze_link
 *     serie → SÉRIE, nDeserie, numero_serie
 *     cidade, usuario, verificado, alterado, acao, novo (URL alternativa)
 *
 * PLANILHA Referencia.UF (mantida):
 *
 *   Consultada para resolver sigla_uf quando o JSON não possui "SiglaUf".
 *   URL configurável via --referencia-uf-url (padrão: variável de ambiente
 *   REFERENCIA_UF_CSV_URL, ou a URL hardcoded abaixo).
 *
 *   Colunas esperadas:
 *     0 = Sigla UF  (ex: MG)
 *     1 = Município (ex: CONTAGEM)
 *
 * DEDUPLICAÇÃO (mesma lógica do ImportRadarMultiAbaCommand):
 *
 *   1. Número de série nas faixas (radar_faixa)
 *   2. identity_hash sha256(UF|localNormalizado|tipoNormalizado)
 *   3. UF + UPPER(municipio) + UPPER(logradouro) — fallback de texto
 */
#[AsCommand(
    name: 'app:import-radar-json',
    description: 'Importa radares de JSON (arquivo/URL), mantém apenas lookup da planilha Referencia.UF',
)]
final class ImportRadarJsonCommand extends Command
{
    private const CURL_TIMEOUT = 120;
    private const BATCH_SIZE   = 200;

    /**
     * URL padrão da planilha Referencia.UF (única planilha mantida).
     * Substitua pelo ID/GID real ou passe --referencia-uf-url.
     */
    private const DEFAULT_REFERENCIA_UF_URL =
        'https://docs.google.com/spreadsheets/d/PLANILHA_ID/pub?gid=REFERENCIA_GID&single=true&output=csv';

    /** Cache em memória do lookup cidade → sigla_uf */
    private array $referenciaUf = [];

    public function __construct(private readonly Connection $db)
    {
        parent::__construct();
    }

    // =========================================================================
    // configure
    // =========================================================================

    protected function configure(): void
    {
        $this
            ->addOption('file',        'f', InputOption::VALUE_OPTIONAL, 'Caminho para arquivo JSON de radares (medidores + faixas + histórico)')
            ->addOption('url',         null, InputOption::VALUE_OPTIONAL, 'URL que retorna JSON de radares')
            ->addOption('links-file',  null, InputOption::VALUE_OPTIONAL, 'Caminho para arquivo JSON de links Waze separado')
            ->addOption('links-url',   null, InputOption::VALUE_OPTIONAL, 'URL que retorna JSON de links Waze')
            ->addOption('uf',          'u', InputOption::VALUE_OPTIONAL, 'Sigla UF padrão quando não presente no JSON (ex: MG)', '')
            ->addOption('user-id',     null, InputOption::VALUE_OPTIONAL, 'ID do usuário responsável pelos links Waze (FK inserted_by)')
            ->addOption('dry-run',     null, InputOption::VALUE_NONE, 'Simula sem gravar no banco')
            ->addOption(
                'referencia-uf-url',
                null,
                InputOption::VALUE_OPTIONAL,
                'URL CSV da planilha Referencia.UF (fallback para resolver sigla_uf por município)',
                (string) getenv('REFERENCIA_UF_CSV_URL') ?: self::DEFAULT_REFERENCIA_UF_URL
            )
        ;
    }

    // =========================================================================
    // execute
    // =========================================================================

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $uf     = strtoupper(trim((string) $input->getOption('uf')));
        $dryRun = (bool) $input->getOption('dry-run');

        $userId = $input->getOption('user-id');
        $userId = ($userId !== null && $userId !== '') ? (int) $userId : null;

        if ($dryRun) {
            $io->warning('MODO DRY-RUN — nenhuma alteração será gravada.');
        }

        // ---------------------------------------------------------------------
        // 1. Carrega Referencia.UF (lookup município → sigla_uf)
        // ---------------------------------------------------------------------
        $referenciaUrl = (string) $input->getOption('referencia-uf-url');
        $io->section('Referencia.UF');
        $this->carregarReferenciaUf($referenciaUrl, $io);

        // ---------------------------------------------------------------------
        // 2. Carrega JSON de radares
        // ---------------------------------------------------------------------
        $jsonSource = $this->resolveJsonSource($input, 'file', 'url');

        $totais = ['inseridos' => 0, 'atualizados' => 0, 'sem_mudanca' => 0, 'links' => 0, 'erros' => 0];

        if ($jsonSource !== null) {
            $io->section('Importando radares do JSON');
            $data = $this->loadJson($jsonSource, $io);

            if ($data === null) {
                return Command::FAILURE;
            }

            // Suporte a duas estruturas:
            //   A) { "radares": [...], "wazeLinks": [...] }
            //   B) array direto de radares [...]
            $radares   = $this->extractList($data, ['radares', 'medidores', 'items', 'data']);
            $wazeLinks = $this->extractList($data, ['wazeLinks', 'waze_links', 'links']);

            if ($radares !== null) {
                $io->writeln(sprintf('  <info>%d radares encontrados no JSON</info>', count($radares)));
                $this->somaStats($totais, $this->processarRadares($radares, $uf, $dryRun, $io));
            } else {
                // Tenta tratar o array inteiro como lista de radares
                if (is_array($data) && isset($data[0]) && is_array($data[0])) {
                    $io->writeln(sprintf('  <info>%d radares no array raiz</info>', count($data)));
                    $this->somaStats($totais, $this->processarRadares($data, $uf, $dryRun, $io));
                } else {
                    $io->warning('Estrutura JSON não reconhecida. Esperado array de radares ou objeto com chave "radares".');
                }
            }

            // Links Waze embutidos no mesmo JSON
            if ($wazeLinks !== null && count($wazeLinks) > 0) {
                if ($userId === null && !$dryRun) {
                    $io->error('Links Waze encontrados no JSON requerem --user-id=<ID>.');
                    return Command::FAILURE;
                }
                $io->writeln(sprintf('  <info>%d links Waze encontrados no JSON</info>', count($wazeLinks)));
                $r = $this->processarLinks($wazeLinks, $dryRun, $userId, $io);
                $totais['links'] += $r['links'];
                $totais['erros'] += $r['erros'];
            }
        }

        // ---------------------------------------------------------------------
        // 3. Carrega JSON de links Waze separado (opcional)
        // ---------------------------------------------------------------------
        $linksSource = $this->resolveJsonSource($input, 'links-file', 'links-url');

        if ($linksSource !== null) {
            $io->section('Importando links Waze do JSON separado');

            if ($userId === null && !$dryRun) {
                $io->error('--user-id=<ID> é obrigatório ao importar links Waze.');
                return Command::FAILURE;
            }

            // Valida usuário
            if ($userId !== null && !$dryRun) {
                $userExists = $this->db->fetchOne('SELECT id FROM user WHERE id = ? LIMIT 1', [$userId]);
                if (!$userExists) {
                    $io->error(sprintf('Usuário com ID %d não encontrado.', $userId));
                    return Command::FAILURE;
                }
            }

            $dataLinks = $this->loadJson($linksSource, $io);
            if ($dataLinks !== null) {
                $lista = $this->extractList($dataLinks, ['links', 'wazeLinks', 'waze_links', 'items'])
                    ?? (is_array($dataLinks) && isset($dataLinks[0]) ? $dataLinks : null);

                if ($lista !== null) {
                    $r = $this->processarLinks($lista, $dryRun, $userId, $io);
                    $totais['links'] += $r['links'];
                    $totais['erros'] += $r['erros'];
                }
            }
        }

        if ($jsonSource === null && $linksSource === null) {
            $io->error('Informe pelo menos uma fonte: --file, --url, --links-file ou --links-url.');
            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Concluído — inseridos: %d | atualizados: %d | sem mudança: %d | links: %d | erros: %d',
            $totais['inseridos'], $totais['atualizados'], $totais['sem_mudanca'],
            $totais['links'], $totais['erros']
        ));

        return Command::SUCCESS;
    }

    // =========================================================================
    // Planilha Referencia.UF  (única planilha mantida)
    // =========================================================================

    /**
     * Baixa e indexa a planilha Referencia.UF em memória.
     * Estrutura esperada: col 0 = SiglaUF, col 1 = Município (UPPERCASE).
     * Também tenta col 0 = Município, col 1 = SiglaUF (planilha invertida).
     */
    private function carregarReferenciaUf(string $url, SymfonyStyle $io): void
    {
        if ($url === self::DEFAULT_REFERENCIA_UF_URL || $url === '') {
            $io->note('URL da Referencia.UF não configurada — lookup de UF por município desativado.');
            return;
        }

        $io->writeln("  URL: {$url}");

        $tmpPath = tempnam(sys_get_temp_dir(), 'ref_uf_');
        if (!$this->downloadParaArquivo($url, $tmpPath, $io)) {
            @unlink($tmpPath);
            return;
        }

        $fh = fopen($tmpPath, 'rb');
        if ($fh === false) { @unlink($tmpPath); return; }

        $header  = null;
        $count   = 0;

        while (($cols = fgetcsv($fh, 0, ',')) !== false) {
            if ($cols === [null]) continue;

            // Detecta automaticamente se a primeira coluna é UF ou Município
            if ($header === null) {
                $first = mb_strtoupper(trim($cols[0] ?? ''));
                // Se 2 chars e alfanumérico → provavelmente sigla
                $header = (strlen($first) === 2 && ctype_alpha($first)) ? 'uf_first' : 'municipio_first';
                continue; // pula cabeçalho
            }

            if ($header === 'uf_first') {
                $sigla    = mb_strtoupper(trim($cols[0] ?? ''));
                $municipio = mb_strtoupper(trim($cols[1] ?? ''));
            } else {
                $municipio = mb_strtoupper(trim($cols[0] ?? ''));
                $sigla     = mb_strtoupper(trim($cols[1] ?? ''));
            }

            if ($sigla !== '' && $municipio !== '') {
                $this->referenciaUf[$municipio] = $sigla;
                $count++;
            }
        }

        fclose($fh);
        @unlink($tmpPath);

        $io->writeln(sprintf('  <info>%d municípios indexados na Referencia.UF</info>', $count));
    }

    /**
     * Resolve sigla_uf usando:
     *  1. Campo SiglaUf do próprio item JSON
     *  2. Opção --uf global
     *  3. Lookup na Referencia.UF pelo nome do município
     */
    private function resolveUf(string $siglaNoJson, string $ufParam, string $municipio): ?string
    {
        if ($siglaNoJson !== '') return $siglaNoJson;
        if ($ufParam !== '')     return $ufParam;

        $chave = mb_strtoupper(trim($municipio));
        return $this->referenciaUf[$chave] ?? null;
    }

    // =========================================================================
    // Processamento de radares (medidores + faixas + histórico)
    // =========================================================================

    private function processarRadares(array $itens, string $ufParam, bool $dryRun, SymfonyStyle $io): array
    {
        $stats      = ['inseridos' => 0, 'atualizados' => 0, 'sem_mudanca' => 0, 'erros' => 0];
        $importedAt = new \DateTimeImmutable();
        $grupos     = [];

        foreach ($itens as $item) {
            if (!is_array($item)) continue;

            $siglaJson = $this->strItem($item, ['SiglaUf', 'sigla_uf', 'uf', 'UF']);
            $municipio = $this->strItem($item, ['Municipio', 'municipio', 'Cidade', 'cidade']);
            $local     = $this->strItem($item, ['LocalVerificacao', 'local_verificacao', 'Local', 'local', 'Logradouro', 'logradouro']);
            $dataVerif = $this->parseDateItem($item, ['DataUltimaVerificacao', 'data_ultima_verificacao', 'DataVerificacao']);
            $dataVal   = $this->parseDateItem($item, ['DataValidade', 'data_validade']);
            $tipo      = $this->strItem($item, ['TipoMedidor', 'tipo_medidor', 'Tipo', 'tipo']);
            $propNome  = trim($this->deepGet($item, ['Proprietario', 'Nome']) ?? $item['ProprietarioNome'] ?? $item['proprietario_nome'] ?? '');
            $rawData   = json_encode($item, JSON_UNESCAPED_UNICODE);

            if ($local === '' || $municipio === '') continue;

            $siglaUf = $this->resolveUf($siglaJson, $ufParam, $municipio);
            $faixas  = $this->extrairFaixas($item);
            $chave   = $this->identityHash($siglaUf ?? '', $local, $tipo);

            // Agrupa itens com mesmo chave (mesma localização física)
            if (!isset($grupos[$chave])) {
                $grupos[$chave] = [
                    'sigla_uf'                 => $siglaUf,
                    'uf'                       => $this->strItem($item, ['Estado', 'estado']) ?: ($siglaUf ?? ''),
                    'municipio'                => $municipio,
                    'logradouro'               => $local,
                    'tipo_medidor'             => $tipo     ?: null,
                    'data_ultima_verificacao'  => $dataVerif,
                    'data_verificacao_efetiva' => $dataVerif,
                    'data_validade'            => $dataVal,
                    'nome_empresa'             => $propNome ?: null,
                    'identity_hash'            => $chave,
                    'raw_data'                 => $rawData,
                    '_faixas'                  => $faixas,
                    '_historico'               => $this->extrairHistorico($item),
                ];
            } else {
                // Mantém a verificação mais recente
                if ($dataVerif && $dataVerif > ($grupos[$chave]['data_ultima_verificacao'] ?? '')) {
                    $grupos[$chave]['data_ultima_verificacao']  = $dataVerif;
                    $grupos[$chave]['data_verificacao_efetiva'] = $dataVerif;
                    $grupos[$chave]['data_validade']            = $dataVal;
                }
                // Mescla faixas sem duplicar por série
                foreach ($faixas as $f) {
                    $serieF = $f['numero_serie'] ?? null;
                    if ($serieF === null) {
                        $grupos[$chave]['_faixas'][] = $f;
                        continue;
                    }
                    $jaExiste = false;
                    foreach ($grupos[$chave]['_faixas'] as $fx) {
                        if (($fx['numero_serie'] ?? null) === $serieF) { $jaExiste = true; break; }
                    }
                    if (!$jaExiste) $grupos[$chave]['_faixas'][] = $f;
                }
            }
        }

        foreach (array_chunk(array_values($grupos), self::BATCH_SIZE) as $batch) {
            foreach ($batch as $g) {
                $faixas    = $g['_faixas'];
                $historico = $g['_historico'];
                unset($g['_faixas'], $g['_historico']);

                $g['row_hash'] = hash('sha256', $g['logradouro'] . '|' . json_encode($faixas));

                $result = $this->upsertRadar($g, $faixas, $dryRun, $importedAt);
                $stats[$result]++;

                // Salva histórico se houve inserção/atualização
                if ($result !== 'sem_mudanca' && !$dryRun && count($historico) > 0) {
                    $radarId = (int) $this->db->fetchOne(
                        'SELECT id FROM radar_medidor WHERE identity_hash = ? LIMIT 1', [$g['identity_hash']]
                    );
                    if ($radarId > 0) {
                        $this->salvarHistorico($radarId, $historico, $importedAt);
                    }
                }

                if ($result !== 'sem_mudanca') {
                    $io->writeln(sprintf('  [%s] %s %s — %s (%d faixas)',
                        strtoupper($result[0]),
                        $g['sigla_uf'] ?? '??',
                        $g['municipio'],
                        $g['logradouro'],
                        count($faixas)
                    ));
                }
            }
        }

        return $stats;
    }

    // =========================================================================
    // Processamento de links Waze
    // Campos aceitos (case-insensitive):
    //   LINK | Nº DE SÉRIE | NOVO | EXPIRADO | CIDADE | USUÁRIO | VERIFICADO | ALTERADO | AÇÃO
    // =========================================================================

    private function processarLinks(array $itens, bool $dryRun, ?int $userId, SymfonyStyle $io): array
    {
        $stats = ['links' => 0, 'erros' => 0];
        $agora = new \DateTimeImmutable();

        foreach ($itens as $item) {
            if (!is_array($item)) continue;

            // Normaliza chaves para lowercase sem pontuação
            $item = $this->normalizeItemKeys($item);

            $link   = trim($item['link'] ?? $item['linkwaze'] ?? $item['waze_link'] ?? $item['url'] ?? '');
            $serie  = trim($item['ndeserie'] ?? $item['serie'] ?? $item['numeroserie'] ?? $item['numero_serie'] ?? '');
            $cidade = mb_strtoupper(trim($item['cidade'] ?? ''));
            $novo   = trim($item['novo'] ?? '');
            $acao   = mb_strtoupper(trim($item['acao'] ?? ''));

            // "NOVO" pode conter URL alternativa/atualizada
            if ($novo !== '' && filter_var($novo, FILTER_VALIDATE_URL)) {
                $link = $novo;
            }

            if ($link === '') continue;

            if (!filter_var($link, FILTER_VALIDATE_URL)) {
                $io->writeln("  [!] URL inválida: {$link}");
                $stats['erros']++;
                continue;
            }

            preg_match('/permanentHazards=(\d+)/i', $link, $m);
            $hazardId = isset($m[1]) ? (int) $m[1] : null;

            $radarId = null;
            if ($serie !== '') {
                $radarId = $this->lookupBySerie($serie);
            }

            if ($radarId === null && $cidade !== '') {
                $io->writeln("  [!] Radar não encontrado — serie={$serie} cidade={$cidade}");
                $stats['erros']++;
                continue;
            } elseif ($radarId === null) {
                $io->writeln("  [!] Radar não encontrado — serie={$serie}");
                $stats['erros']++;
                continue;
            }

            if (!$dryRun) {
                $this->saveWazeLink($radarId, $link, $hazardId, $agora, $userId);
            }

            $io->writeln("  [L] radar_id={$radarId} serie={$serie} hazard={$hazardId} acao={$acao}");
            $stats['links']++;
        }

        return $stats;
    }

    // =========================================================================
    // Upsert: radar_medidor + radar_faixa
    // Ordem de lookup (mais preciso → mais genérico):
    //   1. número de série em radar_faixa
    //   2. identity_hash sha256(UF|local_normalizado|tipo)
    //   3. UF + UPPER(municipio) + UPPER(logradouro)
    // =========================================================================

    private function upsertRadar(array $data, array $faixas, bool $dryRun, \DateTimeImmutable $importedAt): string
    {
        $agora = $importedAt->format('Y-m-d H:i:s');

        // 1. Busca por número de série nas faixas
        $existing = null;
        $series   = array_unique(array_filter(array_column($faixas, 'numero_serie')));
        foreach ($series as $serie) {
            $row = $this->db->fetchAssociative(
                'SELECT rm.id, rm.data_ultima_verificacao, rm.row_hash
                 FROM radar_medidor rm
                 INNER JOIN radar_faixa rf ON rf.radar_medidor_id = rm.id
                 WHERE rf.numero_serie = ? LIMIT 1',
                [$serie]
            );
            if ($row) { $existing = $row; break; }
        }

        // 2. Fallback: identity_hash
        if (!$existing) {
            $existing = $this->db->fetchAssociative(
                'SELECT id, data_ultima_verificacao, row_hash
                 FROM radar_medidor WHERE identity_hash = ? LIMIT 1',
                [$data['identity_hash']]
            );
        }

        // 3. Fallback: UF + município + logradouro (previne duplicatas de texto)
        if (!$existing) {
            $existing = $this->db->fetchAssociative(
                'SELECT id, data_ultima_verificacao, row_hash
                 FROM radar_medidor
                 WHERE sigla_uf = ?
                   AND UPPER(TRIM(municipio))  = ?
                   AND UPPER(TRIM(logradouro)) = ?
                 LIMIT 1',
                [
                    strtoupper(trim($data['sigla_uf'] ?? '')),
                    strtoupper(trim($data['municipio'])),
                    strtoupper(trim($data['logradouro'])),
                ]
            );
        }

        if (!$existing) {
            if (!$dryRun) {
                $insert                = $data;
                $insert['imported_at'] = $agora;
                $insert['updated_at']  = $agora;
                $this->db->insert('radar_medidor', $insert);
                $radarId = (int) $this->db->lastInsertId();
                $this->upsertFaixas($radarId, $faixas);
            }
            return 'inseridos';
        }

        if ($existing['row_hash'] === $data['row_hash']) {
            return 'sem_mudanca';
        }

        $update = [
            'row_hash'   => $data['row_hash'],
            'updated_at' => $agora,
        ];

        $dataExistIso = $this->toIso($existing['data_ultima_verificacao']);
        if ($data['data_ultima_verificacao'] && $data['data_ultima_verificacao'] > ($dataExistIso ?? '')) {
            $update['data_ultima_verificacao']  = $data['data_ultima_verificacao'];
            $update['data_verificacao_efetiva'] = $data['data_ultima_verificacao'];
            $update['data_validade']            = $data['data_validade'];
        }

        // Preenche apenas campos atualmente vazios
        $rowAtual = $this->db->fetchAssociative('SELECT * FROM radar_medidor WHERE id = ?', [$existing['id']]);
        foreach (['sigla_uf','uf','municipio','tipo_medidor','logradouro','nome_empresa','raw_data'] as $col) {
            if (!empty($data[$col]) && empty($rowAtual[$col])) {
                $update[$col] = $data[$col];
            }
        }

        if (!$dryRun) {
            $this->db->update('radar_medidor', $update, ['id' => $existing['id']]);
            $this->upsertFaixas((int) $existing['id'], $faixas);
        }

        return 'atualizados';
    }

    // =========================================================================
    // Upsert faixas
    // =========================================================================

    private function upsertFaixas(int $radarId, array $faixas): void
    {
        foreach ($faixas as $f) {
            $serie   = $f['numero_serie']   ?? null;
            $inmetro = $f['numero_inmetro'] ?? null;

            if ($serie !== null) {
                $exists = $this->db->fetchOne(
                    'SELECT id FROM radar_faixa WHERE radar_medidor_id = ? AND numero_serie = ? LIMIT 1',
                    [$radarId, $serie]
                );
            } elseif ($inmetro !== null) {
                $exists = $this->db->fetchOne(
                    'SELECT id FROM radar_faixa WHERE radar_medidor_id = ? AND numero_inmetro = ? LIMIT 1',
                    [$radarId, $inmetro]
                );
            } else {
                $exists = $this->db->fetchOne(
                    'SELECT id FROM radar_faixa WHERE radar_medidor_id = ? AND numero_faixa = ? LIMIT 1',
                    [$radarId, $f['numero_faixa'] ?? null]
                );
            }

            if ($exists) {
                $this->db->update('radar_faixa', array_filter([
                    'sentido'            => $f['sentido']            ?? null,
                    'velocidade_nominal' => $f['velocidade_nominal'] ?? null,
                ], fn($v) => $v !== null), ['id' => (int) $exists]);
                continue;
            }

            $this->db->insert('radar_faixa', [
                'radar_medidor_id'   => $radarId,
                'numero_faixa'       => $f['numero_faixa']       ?? null,
                'numero_inmetro'     => $f['numero_inmetro']      ?? null,
                'numero_serie'       => $f['numero_serie']        ?? null,
                'sentido'            => $f['sentido']             ?? null,
                'velocidade_nominal' => $f['velocidade_nominal']  ?? null,
            ]);
        }
    }

    // =========================================================================
    // Extrai faixas do item JSON
    // Suporta: array Faixas[], ou campos planos Faixas.0.NumeroSerie, etc.
    // =========================================================================

    private function extrairFaixas(array $item): array
    {
        // Formato estruturado: { "Faixas": [{...}, ...] }
        $lista = $item['Faixas'] ?? $item['faixas'] ?? null;
        if (is_array($lista)) {
            $faixas = [];
            foreach ($lista as $f) {
                if (!is_array($f)) continue;
                $faixas[] = [
                    'numero_faixa'       => trim($f['NumeroFaixa']      ?? $f['numero_faixa']      ?? '') ?: null,
                    'numero_inmetro'     => trim($f['NumeroInmetro']    ?? $f['numero_inmetro']    ?? '') ?: null,
                    'numero_serie'       => trim($f['NumeroSerie']      ?? $f['numero_serie']      ?? '') ?: null,
                    'sentido'            => mb_strtoupper(trim($f['Sentido'] ?? $f['sentido'] ?? '')) ?: null,
                    'velocidade_nominal' => trim($f['VelocidadeNominal'] ?? $f['velocidade_nominal'] ?? '') ?: null,
                ];
            }
            return $faixas;
        }

        // Formato plano expandido: Faixas0NumeroSerie, Faixas1NumeroSerie, ...
        $faixas = [];
        for ($i = 0; $i <= 10; $i++) {
            $pfx      = 'faixas' . $i;
            $numFaixa = trim($item[$pfx . 'NumeroFaixa']       ?? $item[$pfx . 'numerofaixa']       ?? '');
            $inmetro  = trim($item[$pfx . 'NumeroInmetro']     ?? $item[$pfx . 'numeroinmetro']     ?? '');
            $serie    = trim($item[$pfx . 'NumeroSerie']       ?? $item[$pfx . 'numeroserie']       ?? '');
            $sentido  = trim($item[$pfx . 'Sentido']           ?? $item[$pfx . 'sentido']           ?? '');
            $veloc    = trim($item[$pfx . 'VelocidadeNominal'] ?? $item[$pfx . 'velocidadenominal'] ?? '');

            if ($numFaixa === '' && $inmetro === '' && $serie === '') break;

            $faixas[] = [
                'numero_faixa'       => $numFaixa ?: null,
                'numero_inmetro'     => $inmetro  ?: null,
                'numero_serie'       => $serie    ?: null,
                'sentido'            => $sentido  ? mb_strtoupper($sentido) : null,
                'velocidade_nominal' => $veloc    ?: null,
            ];
        }
        return $faixas;
    }

    // =========================================================================
    // Extrai histórico do item JSON
    // =========================================================================

    private function extrairHistorico(array $item): array
    {
        // Formato estruturado: { "Historico": [{...}, ...] }
        $lista = $item['Historico'] ?? $item['historico'] ?? null;
        if (is_array($lista)) {
            $historico = [];
            foreach ($lista as $h) {
                if (!is_array($h)) continue;
                $cert  = trim($h['NumeroCertificado'] ?? $h['numero_certificado'] ?? '');
                $laudo = $this->parseDate(trim($h['DataLaudo'] ?? $h['data_laudo'] ?? ''));
                if ($cert === '' && $laudo === null) continue;
                $historico[] = [
                    'numero_certificado' => $cert ?: null,
                    'numero_ensaio'      => trim($h['NumeroEnsaio']  ?? $h['numero_ensaio']  ?? '') ?: null,
                    'ano'                => trim($h['Ano']           ?? $h['ano']           ?? '') ?: null,
                    'data_laudo'         => $laudo,
                    'data_validade'      => $this->parseDate(trim($h['DataValidade'] ?? $h['data_validade'] ?? '')),
                    'tipo_servico'       => trim($h['TipoServico']   ?? $h['tipo_servico']  ?? '') ?: null,
                    'resultado'          => trim($h['Resultado']     ?? $h['resultado']     ?? '') ?: null,
                ];
            }
            return $historico;
        }

        // Formato plano: Historico0NumeroCertificado, ...
        $historico = [];
        for ($i = 0; $i <= 10; $i++) {
            $pfx  = 'historico' . $i;
            $cert = trim($item[$pfx . 'NumeroCertificado'] ?? $item[$pfx . 'numerocertificado'] ?? '');
            $laudo = $this->parseDate(trim($item[$pfx . 'DataLaudo'] ?? $item[$pfx . 'datalaudo'] ?? ''));
            if ($cert === '' && $laudo === null) break;
            $historico[] = [
                'numero_certificado' => $cert ?: null,
                'numero_ensaio'      => trim($item[$pfx . 'NumeroEnsaio']  ?? $item[$pfx . 'numeroensaio']  ?? '') ?: null,
                'ano'                => trim($item[$pfx . 'Ano']           ?? $item[$pfx . 'ano']           ?? '') ?: null,
                'data_laudo'         => $laudo,
                'data_validade'      => $this->parseDate(trim($item[$pfx . 'DataValidade'] ?? $item[$pfx . 'datavalidade'] ?? '')),
                'tipo_servico'       => trim($item[$pfx . 'TipoServico']  ?? $item[$pfx . 'tiposervico']   ?? '') ?: null,
                'resultado'          => trim($item[$pfx . 'Resultado']    ?? $item[$pfx . 'resultado']     ?? '') ?: null,
            ];
        }
        return $historico;
    }

    // =========================================================================
    // Salva histórico (ignora duplicatas por certificado ou data_laudo)
    // =========================================================================

    private function salvarHistorico(int $radarId, array $historico, \DateTimeImmutable $importedAt): void
    {
        $agora = $importedAt->format('Y-m-d H:i:s');
        foreach ($historico as $h) {
            $exists = $this->db->fetchOne(
                'SELECT id FROM radar_historico
                  WHERE radar_medidor_id = ?
                    AND (numero_certificado = ? OR data_laudo = ?)
                  LIMIT 1',
                [$radarId, $h['numero_certificado'], $h['data_laudo']]
            );
            if ($exists) continue;
            $this->db->insert('radar_historico', array_merge($h, [
                'radar_medidor_id' => $radarId,
                'imported_at'      => $agora,
            ]));
        }
    }

    // =========================================================================
    // Lookup por série
    // =========================================================================

    private function lookupBySerie(string $serie): ?int
    {
        $row = $this->db->fetchAssociative(
            'SELECT rm.id FROM radar_medidor rm
             INNER JOIN radar_faixa rf ON rf.radar_medidor_id = rm.id
             WHERE rf.numero_serie = ? LIMIT 1',
            [$serie]
        );
        if (!$row) {
            $row = $this->db->fetchAssociative(
                'SELECT id FROM radar_medidor WHERE numero_serie = ? LIMIT 1', [$serie]
            );
        }
        return $row ? (int) $row['id'] : null;
    }

    // =========================================================================
    // Salva link Waze (com log de alteração)
    // =========================================================================

    private function saveWazeLink(int $radarId, string $link, ?int $hazardId, \DateTimeImmutable $agora, ?int $userId): void
    {
        $agoraStr = $agora->format('Y-m-d H:i:s');

        $existing = $this->db->fetchAssociative(
            'SELECT id, waze_link FROM radar_waze_link WHERE radar_medidor_id = ? LIMIT 1',
            [$radarId]
        );

        if ($existing) {
            if ($existing['waze_link'] === $link) return;
            $this->db->insert('radar_waze_link_log', [
                'radar_waze_link_id' => $existing['id'],
                'campo_alterado'     => 'waze_link',
                'valor_anterior'     => $existing['waze_link'],
                'valor_novo'         => $link,
                'changed_by'         => $userId,
                'changed_at'         => $agoraStr,
            ]);
            $this->db->update('radar_waze_link', [
                'waze_link'           => $link,
                'permanent_hazard_id' => $hazardId,
                'updated_by'          => $userId,
                'updated_at'          => $agoraStr,
            ], ['radar_medidor_id' => $radarId]);
        } else {
            $this->db->insert('radar_waze_link', [
                'radar_medidor_id'    => $radarId,
                'waze_link'           => $link,
                'permanent_hazard_id' => $hazardId,
                'inserted_by'         => $userId,
                'inserted_at'         => $agoraStr,
            ]);
        }

        $this->db->update('radar_medidor', [
            'link_waze'  => $link,
            'updated_at' => $agoraStr,
        ], ['id' => $radarId]);
    }

    // =========================================================================
    // Carga de JSON (arquivo local ou URL)
    // =========================================================================

    private function resolveJsonSource(InputInterface $input, string $fileOpt, string $urlOpt): ?string
    {
        $file = $input->getOption($fileOpt);
        if ($file !== null && $file !== '') return 'file://' . realpath($file) ?? $file;

        $url = $input->getOption($urlOpt);
        if ($url !== null && $url !== '') return $url;

        return null;
    }

    private function loadJson(string $source, SymfonyStyle $io): ?array
    {
        if (str_starts_with($source, 'file://')) {
            $path    = substr($source, 7);
            $content = @file_get_contents($path);
            if ($content === false) {
                $io->error("Arquivo não encontrado: {$path}");
                return null;
            }
        } else {
            $tmpPath = tempnam(sys_get_temp_dir(), 'radar_json_');
            if (!$this->downloadParaArquivo($source, $tmpPath, $io)) {
                @unlink($tmpPath);
                return null;
            }
            $content = file_get_contents($tmpPath);
            @unlink($tmpPath);
        }

        $data = json_decode((string) $content, true, 512, JSON_BIGINT_AS_STRING);
        if ($data === null) {
            $io->error('JSON inválido: ' . json_last_error_msg());
            return null;
        }

        return $data;
    }

    // =========================================================================
    // Download cURL para arquivo temporário
    // =========================================================================

    private function downloadParaArquivo(string $url, string $tmpPath, SymfonyStyle $io): bool
    {
        $fp = fopen($tmpPath, 'wb');
        if ($fp === false) {
            $io->error("Falha ao criar arquivo temporário: {$tmpPath}");
            return false;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => self::CURL_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_USERAGENT      => 'ToolboxWaze/1.0',
            CURLOPT_FAILONERROR    => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => ['Accept: application/json,text/csv,*/*'],
        ]);

        $ok      = curl_exec($ch);
        $errCode = curl_errno($ch);
        $errMsg  = curl_error($ch);
        $http    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);

        if (!$ok || $errCode !== 0) {
            $io->error("cURL erro {$errCode} (HTTP {$http}): {$errMsg}");
            return false;
        }

        return true;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Extrai uma lista de um array associativo por lista de possíveis chaves.
     * Retorna null se nenhuma chave for encontrada ou se o valor não for array.
     */
    private function extractList(array $data, array $keys): ?array
    {
        foreach ($keys as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                return $data[$key];
            }
        }
        return null;
    }

    /** Lê campo de item JSON por lista de nomes alternativos (primeiro encontrado). */
    private function strItem(array $item, array $keys): string
    {
        foreach ($keys as $k) {
            if (isset($item[$k]) && is_string($item[$k]) && $item[$k] !== '') {
                return mb_strtoupper(trim($item[$k]));
            }
        }
        return '';
    }

    /** Acessa campo aninhado: deepGet($item, ['Proprietario', 'Nome']). */
    private function deepGet(array $item, array $path): ?string
    {
        $cur = $item;
        foreach ($path as $key) {
            if (!is_array($cur) || !array_key_exists($key, $cur)) return null;
            $cur = $cur[$key];
        }
        return is_string($cur) ? $cur : null;
    }

    /** Lê e converte data de item JSON por lista de nomes. */
    private function parseDateItem(array $item, array $keys): ?string
    {
        foreach ($keys as $k) {
            if (!empty($item[$k])) {
                $parsed = $this->parseDate((string) $item[$k]);
                if ($parsed !== null) return $parsed;
            }
        }
        return null;
    }

    /** Normaliza chaves de um item para lowercase sem pontuação (igual ao parseCsv do MultiAba). */
    private function normalizeItemKeys(array $item): array
    {
        $out = [];
        foreach ($item as $k => $v) {
            $out[$this->normalizeKey((string) $k)] = $v;
        }
        return $out;
    }

    private function normalizeKey(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = strtr($s, [
            'á'=>'a','à'=>'a','ã'=>'a','â'=>'a',
            'é'=>'e','ê'=>'e',
            'í'=>'i','ï'=>'i',
            'ó'=>'o','õ'=>'o','ô'=>'o',
            'ú'=>'u','ü'=>'u','ù'=>'u',
            'ç'=>'c','ñ'=>'n',
            'º'=>'', 'ª'=>'', '°'=>'',
        ]);
        return preg_replace('/[^a-z0-9]/', '', $s) ?? $s;
    }

    private function parseDate(string $s): ?string
    {
        $s = trim($s);
        if ($s === '') return null;

        // Formato compacto: 15032024
        if (preg_match('/^(\d{2})(\d{2})(\d{4})$/', $s, $m)) {
            return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
        }
        // Formato dd/mm/yyyy
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $s, $m)) {
            return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
        }
        // ISO yyyy-mm-dd
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $s)) {
            return substr($s, 0, 10);
        }
        return null;
    }

    private function toIso(?string $s): ?string
    {
        if ($s === null || $s === '') return null;
        return $this->parseDate($s) ?? substr($s, 0, 10);
    }

    private function normalizeForHash(string $s): string
    {
        $s = mb_strtoupper(trim($s));
        $s = strtr($s, [
            'Á'=>'A','À'=>'A','Ã'=>'A','Â'=>'A',
            'É'=>'E','Ê'=>'E','È'=>'E',
            'Í'=>'I','Ï'=>'I','Ì'=>'I',
            'Ó'=>'O','Õ'=>'O','Ô'=>'O','Ò'=>'O',
            'Ú'=>'U','Ü'=>'U','Ù'=>'U',
            'Ç'=>'C','Ñ'=>'N',
        ]);
        $s = preg_replace('/[^\w\s]/u', ' ', $s) ?? $s;
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;
        return trim($s);
    }

    private function identityHash(string $uf, string $local, string $tipo): string
    {
        return hash('sha256',
            mb_strtoupper(trim($uf)) . '|' .
            $this->normalizeForHash($local) . '|' .
            $this->normalizeForHash($tipo)
        );
    }

    private function somaStats(array &$totais, array $r): void
    {
        foreach (['inseridos','atualizados','sem_mudanca','erros'] as $k) {
            $totais[$k] += $r[$k] ?? 0;
        }
    }
}
