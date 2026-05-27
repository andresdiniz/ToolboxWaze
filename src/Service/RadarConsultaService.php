<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\RadarFaixa;
use App\Entity\RadarMedidor;
use App\Repository\RadarFaixaRepository;
use App\Repository\RadarMedidorRepository;

/**
 * Consulta radares por número de série ou número INMETRO.
 *
 * Estratégia de busca:
 *   1. Tenta localizar pelo numero_serie da tabela radar_faixa.
 *   2. Se não encontrar, tenta pelo numero_serie da tabela radar_medidor.
 *   3. Para numero_inmetro, busca exclusivamente em radar_faixa.
 *
 * Retorna um DTO array com todos os campos relevantes do medidor + faixas.
 */
final class RadarConsultaService
{
    public function __construct(
        private readonly RadarMedidorRepository $medidorRepo,
        private readonly RadarFaixaRepository   $faixaRepo,
    ) {}

    /**
     * Busca um radar individual.
     * Retorna null se não encontrado.
     *
     * @return array<string, mixed>|null
     */
    public function buscar(?string $numeroSerie, ?string $numeroInmetro): ?array
    {
        $medidor = null;

        if ($numeroInmetro !== null) {
            $faixa = $this->faixaRepo->findOneBy(['numeroInmetro' => $numeroInmetro]);
            if ($faixa !== null) {
                $medidor = $faixa->getRadarMedidor();
            }
        }

        if ($medidor === null && $numeroSerie !== null) {
            // Tenta primeiro na tabela de faixas
            $faixa = $this->faixaRepo->findOneBy(['numeroSerie' => $numeroSerie]);
            if ($faixa !== null) {
                $medidor = $faixa->getRadarMedidor();
            }
        }

        if ($medidor === null && $numeroSerie !== null) {
            // Fallback: busca diretamente no medidor
            $medidor = $this->medidorRepo->findOneBy(['numeroSerie' => $numeroSerie]);
        }

        return $medidor !== null ? $this->toDto($medidor) : null;
    }

    /**
     * Busca múltiplos radares em lote.
     * Itens não encontrados são silenciosamente omitidos.
     *
     * @param  string[] $numSeries
     * @param  string[] $numInmetros
     * @return array<int, array<string, mixed>>
     */
    public function buscarLote(array $numSeries, array $numInmetros): array
    {
        $medidores = []; // indexed by medidor id to avoid duplicates

        // Por numero_inmetro
        if ($numInmetros !== []) {
            $faixas = $this->faixaRepo->findBy(['numeroInmetro' => $numInmetros]);
            foreach ($faixas as $faixa) {
                $m = $faixa->getRadarMedidor();
                $medidores[$m->getId()] = $m;
            }
        }

        // Por numero_serie nas faixas
        if ($numSeries !== []) {
            $faixas = $this->faixaRepo->findBy(['numeroSerie' => $numSeries]);
            foreach ($faixas as $faixa) {
                $m = $faixa->getRadarMedidor();
                $medidores[$m->getId()] = $m;
            }
        }

        // Fallback: numero_serie no medidor
        if ($numSeries !== []) {
            $diretos = $this->medidorRepo->findBy(['numeroSerie' => $numSeries]);
            foreach ($diretos as $m) {
                $medidores[$m->getId()] = $m;
            }
        }

        return array_values(array_map([$this, 'toDto'], $medidores));
    }

    // ── DTO ──────────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function toDto(RadarMedidor $m): array
    {
        return [
            'id'                       => $m->getId(),
            'sigla_uf'                 => $m->getSiglaUf() ?? $m->getUf(),
            'municipio'                => $m->getMunicipio(),
            'logradouro'               => $m->getLogradouro(),
            'cep'                      => $m->getCep(),
            'nome_empresa'             => $m->getNomeEmpresa(),
            'cnpj_empresa'             => $m->getCnpjEmpresa(),
            'tipo_medidor'             => $m->getTipoMedidor(),
            'marca_medidor'            => $m->getMarcaMedidor(),
            'modelo_medidor'           => $m->getModeloMedidor(),
            'numero_serie'             => $m->getNumeroSerie(),
            'capacidade'               => $m->getCapacidade(),
            'situacao'                 => $m->getSituacao(),
            'data_verificacao'         => $m->getDataVerificacao(),
            'data_ultima_verificacao'  => $m->getDataUltimaVerificacao(),
            'data_verificacao_efetiva' => $m->getDataVerificacaoEfetiva(),
            'data_validade'            => $m->getDataValidade(),
            'data_lacre'               => $m->getDataLacre(),
            'lacre'                    => $m->getLacre(),
            'numero_certificado'       => $m->getNumeroCertificado(),
            'orgao_verificador'        => $m->getOrgaoVerificador(),
            'latitude'                 => $m->getLatitude(),
            'longitude'                => $m->getLongitude(),
            'link_waze'                => $m->getLinkWaze(),
            'imported_at'              => $m->getImportedAt()->format('Y-m-d H:i:s'),
            'updated_at'               => $m->getUpdatedAt()?->format('Y-m-d H:i:s'),
            'faixas'                   => array_map(
                static fn(RadarFaixa $f): array => [
                    'numero_faixa'      => $f->getNumeroFaixa(),
                    'numero_inmetro'    => $f->getNumeroInmetro(),
                    'numero_serie'      => $f->getNumeroSerie(),
                    'sentido'           => $f->getSentido(),
                    'velocidade_nominal'=> $f->getVelocidadeNominal(),
                ],
                $m->getFaixas()->toArray(),
            ),
        ];
    }
}
