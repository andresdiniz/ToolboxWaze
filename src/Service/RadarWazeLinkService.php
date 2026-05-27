<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Repository\RadarWazeLinkLogRepository;
use App\Repository\RadarWazeLinkRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;

/**
 * Encapsula toda a lógica de negócio relacionada a links Waze de radares:
 * validação, persistência e log de alterações.
 */
final class RadarWazeLinkService
{
    public function __construct(
        private readonly Connection                 $db,
        private readonly RadarWazeLinkRepository    $linkRepo,
        private readonly RadarWazeLinkLogRepository $logRepo,
    ) {}

    /**
     * Valida os dados do formulário de link Waze.
     *
     * @return array<string, string> Mapa campo => mensagem de erro (vazio = sem erros)
     */
    public function validate(string $wazeLink, string $motivoRevisao, bool $isUpdate): array
    {
        $errors = [];

        if ($wazeLink === '') {
            $errors['waze_link'] = 'O link do Waze é obrigatório.';
        } elseif (!filter_var($wazeLink, FILTER_VALIDATE_URL)) {
            $errors['waze_link'] = 'Informe uma URL válida.';
        } elseif (!preg_match('/[?&]permanentHazards=(\d+)/', $wazeLink)) {
            $errors['waze_link'] = 'A URL deve conter o parâmetro permanentHazards=NÚMERO.';
        }

        if ($isUpdate && $motivoRevisao === '') {
            $errors['motivo_revisao'] = 'Informe o motivo da revisão.';
        }

        return $errors;
    }

    /**
     * Persiste (cria ou atualiza) o link Waze de um radar e registra o log.
     *
     * @throws \InvalidArgumentException Se a URL não contiver permanentHazards.
     */
    public function save(int $radarId, string $wazeLink, string $motivoRevisao, User $user): void
    {
        preg_match('/[?&]permanentHazards=(\d+)/', $wazeLink, $m);
        $hazardId = (int) ($m[1] ?? throw new \InvalidArgumentException('URL sem permanentHazards.'));

        $userId  = $user->getId();
        $now     = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $existing = $this->linkRepo->findRawByRadarId($radarId);

        if ($existing) {
            $this->update($existing, $radarId, $wazeLink, $hazardId, $motivoRevisao, $userId, $now);
        } else {
            $this->insert($radarId, $wazeLink, $hazardId, $motivoRevisao, $userId, $now);
        }
    }

    // -------------------------------------------------------------------------
    // Internos
    // -------------------------------------------------------------------------

    private function update(
        array  $existing,
        int    $radarId,
        string $wazeLink,
        int    $hazardId,
        string $motivoRevisao,
        int    $userId,
        string $now,
    ): void {
        if ($wazeLink !== $existing['waze_link']) {
            $this->appendLog($existing['id'], $userId, 'waze_link', $existing['waze_link'], $wazeLink, $now);
        }

        if (($existing['observacao'] ?? '') !== $motivoRevisao) {
            $this->appendLog($existing['id'], $userId, 'motivo_revisao', $existing['observacao'] ?? null, $motivoRevisao, $now);
        }

        $this->db->executeStatement(
            'UPDATE radar_waze_link
             SET waze_link = ?, permanent_hazard_id = ?, observacao = ?, updated_by = ?, updated_at = ?
             WHERE id = ?',
            [$wazeLink, $hazardId, $motivoRevisao ?: null, $userId, $now, $existing['id']]
        );
    }

    private function insert(
        int    $radarId,
        string $wazeLink,
        int    $hazardId,
        string $motivoRevisao,
        int    $userId,
        string $now,
    ): void {
        $this->db->executeStatement(
            'INSERT INTO radar_waze_link
             (radar_medidor_id, waze_link, permanent_hazard_id, observacao, inserted_by, inserted_at)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$radarId, $wazeLink, $hazardId, $motivoRevisao ?: null, $userId, $now]
        );

        $newId = (int) $this->db->lastInsertId();
        $this->appendLog($newId, $userId, 'waze_link', null, $wazeLink, $now);
    }

    private function appendLog(
        int     $linkId,
        int     $userId,
        string  $campo,
        ?string $anterior,
        string  $novo,
        string  $now,
    ): void {
        $this->db->executeStatement(
            'INSERT INTO radar_waze_link_log
             (radar_waze_link_id, changed_by, campo_alterado, valor_anterior, valor_novo, changed_at)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$linkId, $userId, $campo, $anterior, $novo, $now]
        );
    }
}
