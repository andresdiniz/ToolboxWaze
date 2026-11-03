<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class CoberturaVerificacaoService
{
    public function __construct(
        private readonly Connection $db,
    ) {
    }

    public function listarTrechos(
        User $usuario,
        ?string $uf,
        int $pagina,
        int $porPagina,
    ): array {
        [$where, $params] = $this->escopoUf(
            $usuario,
            $uf,
            't.uf'
        );

        [$pagina, $porPagina, $offset] = $this->paginacao(
            $pagina,
            $porPagina
        );

        $total = (int) $this->db->fetchOne(
            "SELECT COUNT(*)
             FROM cobertura_trecho t
             WHERE $where",
            $params
        );

        $itens = $this->db->fetchAllAssociative(
            "SELECT
                t.id,
                t.nome,
                t.uf,
                COUNT(DISTINCT r.id) AS total,
                COUNT(DISTINCT CASE
                    WHEN v.resultado = 'confirmado' THEN r.id
                END) AS confirmados,
                COUNT(DISTINCT CASE
                    WHEN v.resultado = 'divergente' THEN r.id
                END) AS divergentes
             FROM cobertura_trecho t
             LEFT JOIN cobertura_trecho_radar tr
                ON tr.trecho_id = t.id
             LEFT JOIN radar_medidor r
                ON r.id = tr.radar_id
                AND r.sigla_uf = t.uf
             LEFT JOIN cobertura_verificacao v
                ON v.radar_id = r.id
             WHERE $where
             GROUP BY t.id, t.nome, t.uf
             ORDER BY t.uf, t.nome, t.id
             LIMIT $porPagina OFFSET $offset",
            $params
        );

        foreach ($itens as &$item) {
            $item['total'] = (int) $item['total'];
            $item['confirmados'] = (int) $item['confirmados'];
            $item['divergentes'] = (int) $item['divergentes'];

            $item['pendentes'] = $item['total']
                - $item['confirmados']
                - $item['divergentes'];

            $item['percentual'] = $item['total'] > 0
                ? round(
                    100 * $item['confirmados'] / $item['total'],
                    1
                )
                : 0;
        }
        unset($item);

        return $this->resultadoPaginado(
            $itens,
            $total,
            $pagina,
            $porPagina
        );
    }

    public function listarRadaresDoTrecho(
        int $trechoId,
        User $usuario,
        int $pagina,
        int $porPagina,
    ): array {
        $trecho = $this->db->fetchAssociative(
            'SELECT id, nome, uf
             FROM cobertura_trecho
             WHERE id = ?',
            [$trechoId]
        );

        if (!$trecho) {
            throw new NotFoundHttpException(
                'Trecho não encontrado.'
            );
        }

        if (!$usuario->canAccessUf((string) $trecho['uf'])) {
            throw new AccessDeniedHttpException(
                'Você não possui acesso a este trecho.'
            );
        }

        [$pagina, $porPagina, $offset] = $this->paginacao(
            $pagina,
            $porPagina
        );

        $params = [$trechoId, $trecho['uf']];

        $total = (int) $this->db->fetchOne(
            'SELECT COUNT(*)
             FROM cobertura_trecho_radar tr
             INNER JOIN radar_medidor r ON r.id = tr.radar_id
             WHERE tr.trecho_id = ?
               AND r.sigla_uf = ?',
            $params
        );

        $itens = $this->db->fetchAllAssociative(
            "SELECT
                r.id,
                r.sigla_uf,
                r.municipio,
                r.logradouro,
                COALESCE(v.resultado, 'pendente') AS resultado,
                v.editor_nome,
                v.atualizado_em
             FROM cobertura_trecho_radar tr
             INNER JOIN radar_medidor r ON r.id = tr.radar_id
             LEFT JOIN cobertura_verificacao v ON v.radar_id = r.id
             WHERE tr.trecho_id = ?
               AND r.sigla_uf = ?
             ORDER BY r.municipio, r.id
             LIMIT $porPagina OFFSET $offset",
            $params
        );

        return [
            'trecho' => $trecho,
            ...$this->resultadoPaginado(
                $itens,
                $total,
                $pagina,
                $porPagina
            ),
        ];
    }

    public function consultarRadar(
        int $radarId,
        User $usuario,
    ): array {
        $radar = $this->radarPermitido($radarId, $usuario);

        $vinculos = (int) $this->db->fetchOne(
            'SELECT COUNT(*)
             FROM radar_waze_link
             WHERE radar_medidor_id = ?',
            [$radarId]
        );

        $verificacao = $this->db->fetchAssociative(
            'SELECT resultado, editor_nome, observacao, atualizado_em
             FROM cobertura_verificacao
             WHERE radar_id = ?',
            [$radarId]
        );

        return [
            'radar' => $radar,
            'origem' => 'banco_local',
            'quantidade_vinculos' => $vinculos,
            'tem_vinculo' => $vinculos > 0,
            'verificado_api_externa' => false,
            'verificacao' => $verificacao ?: null,
            'mensagem' => $vinculos > 0
                ? 'Há vínculo cadastrado. Confira o objeto no WME.'
                : 'Nenhum vínculo cadastrado para este radar.',
        ];
    }

    public function registrarVerificacao(
        int $radarId,
        User $usuario,
        string $resultado,
        string $observacao,
    ): array {
        if (!in_array(
            $resultado,
            ['confirmado', 'divergente', 'inconclusivo'],
            true
        )) {
            throw new BadRequestHttpException(
                'Resultado inválido.'
            );
        }

        $observacao = trim($observacao);

        if (mb_strlen($observacao) > 2000) {
            throw new BadRequestHttpException(
                'Observação acima de 2000 caracteres.'
            );
        }

        if ($resultado === 'divergente' && $observacao === '') {
            throw new BadRequestHttpException(
                'Descreva a divergência.'
            );
        }

        return $this->db->transactional(
            function (Connection $db) use (
                $radarId,
                $usuario,
                $resultado,
                $observacao
            ): array {
                // Serializa as verificações deste radar.
                $this->radarPermitido(
                    $radarId,
                    $usuario,
                    true
                );

                if ($resultado === 'confirmado') {
                    $vinculos = (int) $db->fetchOne(
                        'SELECT COUNT(*)
                         FROM radar_waze_link
                         WHERE radar_medidor_id = ?',
                        [$radarId]
                    );

                    if ($vinculos === 0) {
                        throw new BadRequestHttpException(
                            'Cadastre o vínculo Waze antes de confirmar.'
                        );
                    }
                }

                $anterior = $db->fetchAssociative(
                    'SELECT *
                     FROM cobertura_verificacao
                     WHERE radar_id = ?
                     FOR UPDATE',
                    [$radarId]
                );

                $editorId = (int) $usuario->getId();

                $nome = trim(
                    (string) $usuario->getWazeNickname()
                );

                if ($nome === '') {
                    $nome = 'Editor #' . $editorId;
                }

                $nome = mb_substr($nome, 0, 180);

                // Repetição exata não cria evento nem pontuação.
                if (
                    $anterior
                    && (int) $anterior['editor_id'] === $editorId
                    && $anterior['resultado'] === $resultado
                    && $anterior['observacao'] === $observacao
                ) {
                    return [
                        'alterado' => false,
                        'mensagem' => 'Verificação já registrada.',
                    ];
                }

                $agora = gmdate('Y-m-d H:i:s');

                $dados = [
                    'resultado' => $resultado,
                    'editor_id' => $editorId,
                    'editor_nome' => $nome,
                    'observacao' => $observacao,
                    'atualizado_em' => $agora,
                ];

                $primeiraConfirmacao = $resultado === 'confirmado'
                    && (
                        !$anterior
                        || $anterior['primeiro_editor_id'] === null
                    );

                if ($primeiraConfirmacao) {
                    $dados['primeiro_editor_id'] = $editorId;
                    $dados['primeiro_editor_nome'] = $nome;
                    $dados['primeira_confirmacao_em'] = $agora;
                }

                if ($anterior) {
                    $db->update(
                        'cobertura_verificacao',
                        $dados,
                        ['radar_id' => $radarId]
                    );
                } else {
                    $db->insert(
                        'cobertura_verificacao',
                        ['radar_id' => $radarId, ...$dados]
                    );
                }

                $db->insert(
                    'cobertura_verificacao_evento',
                    [
                        'radar_id' => $radarId,
                        'editor_id' => $editorId,
                        'editor_nome' => $nome,
                        'resultado' => $resultado,
                        'observacao' => $observacao,
                        'criado_em' => $agora,
                    ]
                );

                return [
                    'alterado' => true,
                    'resultado' => $resultado,
                    'primeira_confirmacao' => $primeiraConfirmacao,
                    'mensagem' => 'Verificação registrada.',
                ];
            }
        );
    }

    public function ranking(
        User $usuario,
        ?string $uf,
        \DateTimeImmutable $inicio,
        \DateTimeImmutable $fimExclusivo,
        int $pagina,
        int $porPagina,
    ): array {
        [$where, $params] = $this->escopoUf(
            $usuario,
            $uf,
            'r.sigla_uf'
        );

        [$pagina, $porPagina, $offset] = $this->paginacao(
            $pagina,
            $porPagina
        );

        $utc = new \DateTimeZone('UTC');

        $params[] = $inicio->setTimezone($utc)
            ->format('Y-m-d H:i:s');

        $params[] = $fimExclusivo->setTimezone($utc)
            ->format('Y-m-d H:i:s');

        $filtro = "$where
            AND v.resultado = 'confirmado'
            AND v.primeiro_editor_id IS NOT NULL
            AND v.primeira_confirmacao_em >= ?
            AND v.primeira_confirmacao_em < ?";

        $total = (int) $this->db->fetchOne(
            "SELECT COUNT(DISTINCT v.primeiro_editor_id)
             FROM cobertura_verificacao v
             INNER JOIN radar_medidor r ON r.id = v.radar_id
             WHERE $filtro",
            $params
        );

        $itens = $this->db->fetchAllAssociative(
            "SELECT
                v.primeiro_editor_id AS editor_id,
                MAX(v.primeiro_editor_nome) AS editor,
                COUNT(*) AS confirmacoes,
                MAX(v.primeira_confirmacao_em) AS ultima_confirmacao
             FROM cobertura_verificacao v
             INNER JOIN radar_medidor r ON r.id = v.radar_id
             WHERE $filtro
             GROUP BY v.primeiro_editor_id
             ORDER BY confirmacoes DESC,
                      ultima_confirmacao ASC,
                      editor_id ASC
             LIMIT $porPagina OFFSET $offset",
            $params
        );

        foreach ($itens as $indice => &$item) {
            $item['posicao'] = $offset + $indice + 1;
            $item['confirmacoes'] = (int) $item['confirmacoes'];
        }
        unset($item);

        return $this->resultadoPaginado(
            $itens,
            $total,
            $pagina,
            $porPagina
        );
    }

    private function radarPermitido(
        int $radarId,
        User $usuario,
        bool $bloquear = false,
    ): array {
        $sql = 'SELECT id, sigla_uf, municipio, logradouro
                FROM radar_medidor
                WHERE id = ?';

        if ($bloquear) {
            $sql .= ' FOR UPDATE';
        }

        $radar = $this->db->fetchAssociative(
            $sql,
            [$radarId]
        );

        if (!$radar) {
            throw new NotFoundHttpException(
                'Radar não encontrado.'
            );
        }

        if (!$usuario->canAccessUf((string) $radar['sigla_uf'])) {
            throw new AccessDeniedHttpException(
                'Você não possui acesso a este radar.'
            );
        }

        return $radar;
    }

    private function escopoUf(
        User $usuario,
        ?string $uf,
        string $coluna,
    ): array {
        // $coluna é sempre uma constante interna, nunca entrada HTTP.
        if ($uf !== null) {
            $uf = strtoupper(trim($uf));

            if (!in_array($uf, User::ALL_UFS, true)) {
                throw new BadRequestHttpException('UF inválida.');
            }

            if (!$usuario->canAccessUf($uf)) {
                throw new AccessDeniedHttpException(
                    'UF não autorizada.'
                );
            }

            return ["$coluna = ?", [$uf]];
        }

        $ufs = $usuario->getUfsForQuery();

        if ($ufs === null) {
            return ['1 = 1', []];
        }

        if ($ufs === []) {
            return ['1 = 0', []];
        }

        $marcadores = implode(
            ',',
            array_fill(0, count($ufs), '?')
        );

        return [
            "$coluna IN ($marcadores)",
            array_values($ufs),
        ];
    }

    private function paginacao(
        int $pagina,
        int $porPagina,
    ): array {
        $pagina = max(1, min($pagina, 10000));
        $porPagina = max(1, min($porPagina, 100));

        return [
            $pagina,
            $porPagina,
            ($pagina - 1) * $porPagina,
        ];
    }

    private function resultadoPaginado(
        array $itens,
        int $total,
        int $pagina,
        int $porPagina,
    ): array {
        return [
            'itens' => $itens,
            'total' => $total,
            'pagina' => $pagina,
            'por_pagina' => $porPagina,
            'paginas' => max(
                1,
                (int) ceil($total / $porPagina)
            ),
        ];
    }
}