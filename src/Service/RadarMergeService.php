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
 *      c. Marca absorvido como merged_into_id = sobrevivente.id
 *      d. Grava RadarMergeLog
 *   2. Tudo dentro de uma transação — rollback automático em caso de erro.
 *
 * NOTA: só são incluídos em $updatableFields as colunas que realmente
 * existem na tabela radar_medidor (verificado via DESCRIBE na migração).
 * Colunas como situacao, logradouro, cep, nome_empresa não existem.
 */
final class RadarMergeService
{
    /**
     * Colunas de radar_medidor que podem ser sobrescritas durante a mesclagem.
     * Mantenha sincronizado com o schema real da tabela.
     */
    private const UPDATABLE_FIELDS = [
        'sigla_uf',
        'estado',
        'municipio',
        'local_verificacao',
        'tipo_medidor',
        'proprietario_nome',
        'proprietario_municipio',
        'proprietario_estado',
        'data_ultima_verificacao',
        'data_validade',
        'data_verificacao_efetiva',
        'ultimo_resultado',
        'link_waze',
        'origem',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $db,
    ) {}

    /**
     * @param int    $survivorId   ID do radar que permanecerá
     * @param int[]  $absorbedIds  IDs dos radares que serão absorvidos
     * @param array  $fieldChoices [ campo => 'survivor'|'absorbed_N' ]
     * @param string $mergedBy     E-mail do usuário
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

            foreach ($absorbedIds as $idx => $absorbedId) {
                $absorbed = $this->db->fetchAssociative(
                    'SELECT * FROM radar_medidor WHERE id = ?', [$absorbedId]
                );
                if (!$absorbed) {
                    throw new \RuntimeException('Radar absorvido não encontrado (id=' . $absorbedId . ')');
                }

                // ── 1. Aplicar campos escolhidos no sobrevivente ──
                $overwritten = [];
                $setClauses  = [];
                $setParams   = [];
                $choiceKey   = 'absorbed_' . ($idx + 1);

                foreach (self::UPDATABLE_FIELDS as $field) {
                    // Ignorar campos que não existem no resultado (proteção extra)
                    if (!array_key_exists($field, $absorbed)) {
                        continue;
                    }
                    $choice = $fieldChoices[$field] ?? 'survivor';
                    if ($choice === $choiceKey) {
                        $old = $survivor[$field] ?? null;
                        if ($old !== $absorbed[$field]) {
                            $overwritten[$field] = $old;
                            $setClauses[]        = "`$field` = ?";
                            $setParams[]         = $absorbed[$field];
                            $survivor[$field]    = $absorbed[$field];
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

                // ── 2. Reassociar RadarFaixa ──
                $this->db->executeStatement(
                    'UPDATE radar_faixa SET radar_medidor_id = ? WHERE radar_medidor_id = ?',
                    [$survivorId, $absorbedId]
                );

                // ── 3. Marcar absorvido (apenas merged_into_id, sem coluna situacao) ──
                $this->db->executeStatement(
                    'UPDATE radar_medidor SET merged_into_id = ?, updated_at = NOW() WHERE id = ?',
                    [$survivorId, $absorbedId]
                );

                // ── 4. Gravar log ──
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
