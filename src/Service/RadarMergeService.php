<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\RadarMergeLog;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Executa a mesclagem de dois ou mais radares em um único (o "sobrevivente").
 *
 * Fluxo:
 *   1. Para cada radar absorvido:
 *      a. Aplica os campos escolhidos pelo admin no sobrevivente
 *      b. Reassocia todas as RadarFaixa do absorvido → sobrevivente
 *      c. Marca absorvido como situacao = 'MESCLADO' e merged_into_id = sobrevivente.id
 *      d. Grava RadarMergeLog
 *   2. Tudo dentro de uma transação — rollback automático em caso de erro.
 */
final class RadarMergeService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $db,
    ) {}

    /**
     * @param int   $survivorId  ID do radar que permanecerá
     * @param int[] $absorbedIds IDs dos radares que serão absorvidos
     * @param array $fieldChoices [ campo => 'survivor'|'absorbed_N' ] — qual valor prevalece por campo
     *                            Ex: ['municipio' => 'absorbed_1', 'link_waze' => 'survivor']
     * @param string $mergedBy   E-mail do usuário
     */
    public function merge(
        int    $survivorId,
        array  $absorbedIds,
        array  $fieldChoices,
        string $mergedBy,
    ): void {
        $this->em->wrapInTransaction(function () use ($survivorId, $absorbedIds, $fieldChoices, $mergedBy): void {

            $survivor = $this->db->fetchAssociative(
                'SELECT * FROM radar_medidor WHERE id = ?', [$survivorId]
            );
            if (!$survivor) {
                throw new \RuntimeException('Radar sobrevivente não encontrado (id=' . $survivorId . ')');
            }

            $updatableFields = [
                'sigla_uf', 'municipio', 'logradouro', 'cep',
                'nome_empresa', 'cnpj_empresa',
                'tipo_medidor', 'marca_medidor', 'modelo_medidor',
                'numero_serie', 'capacidade', 'situacao',
                'data_verificacao', 'data_ultima_verificacao',
                'data_validade', 'data_verificacao_efetiva',
                'data_lacre', 'lacre', 'numero_certificado',
                'orgao_verificador', 'latitude', 'longitude', 'link_waze',
            ];

            foreach ($absorbedIds as $idx => $absorbedId) {
                $absorbed = $this->db->fetchAssociative(
                    'SELECT * FROM radar_medidor WHERE id = ?', [$absorbedId]
                );
                if (!$absorbed) {
                    throw new \RuntimeException('Radar absorvido não encontrado (id=' . $absorbedId . ')');
                }

                // ── 1. Aplicar campos escolhidos no sobrevivente ──────────────
                $overwritten = [];
                $setClauses  = [];
                $setParams   = [];
                $choiceKey   = 'absorbed_' . ($idx + 1);

                foreach ($updatableFields as $field) {
                    $choice = $fieldChoices[$field] ?? 'survivor';
                    if ($choice === $choiceKey && isset($absorbed[$field])) {
                        $old = $survivor[$field] ?? null;
                        if ($old !== $absorbed[$field]) {
                            $overwritten[$field] = $old;
                            $setClauses[]        = "`$field` = ?";
                            $setParams[]         = $absorbed[$field];
                            $survivor[$field]    = $absorbed[$field]; // atualiza snapshot local
                        }
                    }
                }

                if ($setClauses !== []) {
                    $setParams[] = $survivorId;
                    $this->db->executeStatement(
                        'UPDATE radar_medidor SET ' . implode(', ', $setClauses) .
                        ', updated_at = NOW() WHERE id = ?',
                        $setParams
                    );
                }

                // ── 2. Reassociar RadarFaixa ──────────────────────────────────
                $this->db->executeStatement(
                    'UPDATE radar_faixa SET radar_medidor_id = ? WHERE radar_medidor_id = ?',
                    [$survivorId, $absorbedId]
                );

                // ── 3. Marcar absorvido ───────────────────────────────────────
                $this->db->executeStatement(
                    "UPDATE radar_medidor SET situacao = 'MESCLADO', merged_into_id = ?, updated_at = NOW() WHERE id = ?",
                    [$survivorId, $absorbedId]
                );

                // ── 4. Gravar log ─────────────────────────────────────────────
                $log = new RadarMergeLog(
                    survivorId:        $survivorId,
                    absorbedId:        $absorbedId,
                    mergedBy:          $mergedBy,
                    absorbedSnapshot:  $absorbed,
                    fieldsOverwritten: $overwritten ?: null,
                );
                $this->em->persist($log);
            }

            $this->em->flush();
        });
    }
}
