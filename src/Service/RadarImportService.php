<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\RadarFaixa;
use App\Entity\RadarMedidor;
use App\Repository\RadarMedidorRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Processa um batch de radares recebidos via API.
 *
 * Estratégia de upsert:
 *   - identity_hash = SHA-256 de (sigla_uf + municipio + logradouro + tipo_medidor + numero_serie)
 *   - row_hash      = SHA-256 de todos os campos relevantes (detecta mudança de conteúdo)
 *
 * Se identity_hash já existe e row_hash mudou → UPDATE.
 * Se identity_hash não existe              → INSERT.
 * Se row_hash idêntico                     → SKIP (sem alteração).
 *
 * Faixas: substituição completa (remove antigas, insere novas).
 */
final class RadarImportService
{
    /**
     * Campos escalares aceitos do payload JSON.
     * Campos ausentes ou nulos no payload são ignorados (mantém valor atual no update).
     */
    private const SCALAR_FIELDS = [
        'sigla_uf', 'municipio', 'logradouro', 'cep',
        'nome_empresa', 'cnpj_empresa',
        'tipo_medidor', 'marca_medidor', 'modelo_medidor', 'numero_serie',
        'capacidade', 'situacao',
        'data_verificacao', 'data_ultima_verificacao', 'data_validade', 'data_lacre',
        'lacre', 'numero_certificado', 'orgao_verificador',
        'latitude', 'longitude',
        'proprietario_nome',   // campo extra aceito se existir na entidade
        'local_verificacao',   // idem
        'ultimo_resultado',    // idem
    ];

    /** Campos que compõem o identity_hash (identificam o radar de forma única) */
    private const IDENTITY_FIELDS = [
        'sigla_uf', 'municipio', 'logradouro', 'tipo_medidor', 'numero_serie',
    ];

    public function __construct(
        private readonly EntityManagerInterface  $em,
        private readonly RadarMedidorRepository  $repo,
    ) {}

    /**
     * @param  array<int, array<string, mixed>> $items
     * @return array{created: int, updated: int, skipped: int, errors: list<array{index: int, msg: string}>}
     */
    public function processBatch(array $items): array
    {
        $result = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];

        foreach ($items as $index => $item) {
            try {
                $op = $this->processOne($item);
                $result[$op]++;
            } catch (\Throwable $e) {
                $result['errors'][] = [
                    'index' => $index,
                    'msg'   => $e->getMessage(),
                ];
            }
        }

        // Flush único no final do batch para máxima performance
        if ($result['created'] > 0 || $result['updated'] > 0) {
            $this->em->flush();
            $this->em->clear();
        }

        return $result;
    }

    // =========================================================================
    // Internos
    // =========================================================================

    /** @return 'created'|'updated'|'skipped' */
    private function processOne(array $item): string
    {
        $identityHash = $this->computeIdentityHash($item);
        $rowHash      = $this->computeRowHash($item);

        /** @var RadarMedidor|null $existing */
        $existing = $this->repo->findOneBy(['identityHash' => $identityHash]);

        if ($existing === null) {
            $radar = $this->buildEntity($item, $identityHash, $rowHash);
            $this->em->persist($radar);
            return 'created';
        }

        if ($existing->getRowHash() === $rowHash) {
            return 'skipped';
        }

        $this->updateEntity($existing, $item, $rowHash);
        return 'updated';
    }

    private function buildEntity(array $item, string $identityHash, string $rowHash): RadarMedidor
    {
        $radar = new RadarMedidor();
        $radar->setIdentityHash($identityHash);
        $radar->setRowHash($rowHash);
        $radar->setInsertedBy('api-import');
        $radar->setRawData($item);

        $this->applyScalars($radar, $item);
        $radar->setUf(strtoupper((string) ($item['sigla_uf'] ?? $item['uf'] ?? '')));
        $radar->setSiglaUf($radar->getUf());
        $radar->setDataVerificacaoEfetiva($this->resolveDataEfetiva($item));

        foreach ($this->extractFaixas($item) as $faixaData) {
            $faixa = $this->buildFaixa($faixaData, $radar);
            $this->em->persist($faixa);
        }

        return $radar;
    }

    private function updateEntity(RadarMedidor $radar, array $item, string $rowHash): void
    {
        $radar->setRowHash($rowHash);
        $radar->setUpdatedAt(new \DateTimeImmutable());
        $radar->setRawData($item);

        $this->applyScalars($radar, $item);
        $radar->setUf(strtoupper((string) ($item['sigla_uf'] ?? $item['uf'] ?? $radar->getUf())));
        $radar->setSiglaUf($radar->getUf());
        $radar->setDataVerificacaoEfetiva($this->resolveDataEfetiva($item));

        // Substituir faixas se vieram no payload
        if (isset($item['faixas']) && is_array($item['faixas'])) {
            foreach ($radar->getFaixas() as $old) {
                $this->em->remove($old);
            }
            foreach ($this->extractFaixas($item) as $faixaData) {
                $faixa = $this->buildFaixa($faixaData, $radar);
                $this->em->persist($faixa);
            }
        }
    }

    private function applyScalars(RadarMedidor $radar, array $item): void
    {
        $map = [
            'sigla_uf'               => 'setSiglaUf',
            'municipio'              => 'setMunicipio',
            'logradouro'             => 'setLogradouro',
            'cep'                    => 'setCep',
            'nome_empresa'           => 'setNomeEmpresa',
            'cnpj_empresa'           => 'setCnpjEmpresa',
            'tipo_medidor'           => 'setTipoMedidor',
            'marca_medidor'          => 'setMarcaMedidor',
            'modelo_medidor'         => 'setModeloMedidor',
            'numero_serie'           => 'setNumeroSerie',
            'capacidade'             => 'setCapacidade',
            'situacao'               => 'setSituacao',
            'data_verificacao'       => 'setDataVerificacao',
            'data_ultima_verificacao'=> 'setDataUltimaVerificacao',
            'data_validade'          => 'setDataValidade',
            'data_lacre'             => 'setDataLacre',
            'lacre'                  => 'setLacre',
            'numero_certificado'     => 'setNumeroCertificado',
            'orgao_verificador'      => 'setOrgaoVerificador',
            'latitude'               => 'setLatitude',
            'longitude'              => 'setLongitude',
        ];

        foreach ($map as $key => $setter) {
            if (array_key_exists($key, $item)) {
                $radar->$setter($item[$key] !== '' ? (string) $item[$key] : null);
            }
        }
    }

    /**
     * Calcula a data de verificação efetiva:
     *   1. data_ultima_verificacao (se preenchida)
     *   2. data_validade - 1 ano
     *   3. null
     */
    private function resolveDataEfetiva(array $item): ?string
    {
        $duv = $item['data_ultima_verificacao'] ?? null;
        if ($duv !== null && $duv !== '') {
            return (string) $duv;
        }

        $dv = $item['data_validade'] ?? null;
        if ($dv !== null && $dv !== '') {
            try {
                $dt = \DateTimeImmutable::createFromFormat('d/m/Y', (string) $dv);
                if ($dt !== false) {
                    return $dt->modify('-1 year')->format('d/m/Y');
                }
            } catch (\Throwable) {
                // ignora data inválida
            }
        }

        return null;
    }

    /** @return array<int, array<string, mixed>> */
    private function extractFaixas(array $item): array
    {
        return isset($item['faixas']) && is_array($item['faixas']) ? $item['faixas'] : [];
    }

    private function buildFaixa(array $data, RadarMedidor $radar): RadarFaixa
    {
        $faixa = new RadarFaixa();
        $faixa->setRadarMedidor($radar);
        $faixa->setNumeroFaixa($data['numero_faixa'] ?? null);
        $faixa->setNumeroInmetro($data['numero_inmetro'] ?? null);
        $faixa->setNumeroSerie($data['numero_serie'] ?? null);
        $faixa->setSentido($data['sentido'] ?? null);
        $faixa->setVelocidadeNominal($data['velocidade_nominal'] ?? null);
        return $faixa;
    }

    private function computeIdentityHash(array $item): string
    {
        $parts = [];
        foreach (self::IDENTITY_FIELDS as $field) {
            $parts[] = strtolower(trim((string) ($item[$field] ?? '')));
        }
        return hash('sha256', implode('|', $parts));
    }

    private function computeRowHash(array $item): string
    {
        $normalized = $item;
        unset($normalized['faixas'], $normalized['raw_data']);
        ksort($normalized);
        return hash('sha256', json_encode($normalized, JSON_UNESCAPED_UNICODE));
    }
}
