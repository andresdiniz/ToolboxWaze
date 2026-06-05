<?php

declare(strict_types=1);

/**
 * Campos do radar_medidor que podem ser editados via interface.
 * Mova este arquivo para alterar rótulos ou adicionar/remover campos
 * sem tocar no RadarController ou RadarService.
 *
 * Formato: 'coluna_no_banco' => 'Rótulo exibido na UI'
 */
return [
    'sigla_uf'                  => 'UF',
    'uf'                        => 'Estado (nome)',
    'municipio'                 => 'Município',
    'logradouro'                => 'Logradouro',
    'cep'                       => 'CEP',
    'nome_empresa'              => 'Empresa',
    'cnpj_empresa'              => 'CNPJ',
    'tipo_medidor'              => 'Tipo de Medidor',
    'modelo_medidor'            => 'Modelo do Medidor',
    'marca_medidor'             => 'Marca do Medidor',
    'numero_serie'              => 'Nº de Série',
    'numero_certificado'        => 'Nº Certificado',
    'orgao_verificador'         => 'Órgão Verificador',
    'data_ultima_verificacao'   => 'Última Verificação',
    'data_verificacao_efetiva'  => 'Data Verificação Efetiva',
    'data_verificacao'          => 'Data Verificação',
    'data_lacre'                => 'Data Lacre',
    'lacre'                     => 'Lacre',
    'data_validade'             => 'Validade',
    'situacao'                  => 'Situação',
    'capacidade'                => 'Capacidade',
    'latitude'                  => 'Latitude',
    'longitude'                 => 'Longitude',
    'link_waze'                 => 'Link Waze',
];
