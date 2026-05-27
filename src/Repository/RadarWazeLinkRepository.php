<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RadarWazeLink;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RadarWazeLink>
 */
class RadarWazeLinkRepository extends ServiceEntityRepository
{
    private Connection $db;

    public function __construct(ManagerRegistry $registry, Connection $db)
    {
        parent::__construct($registry, RadarWazeLink::class);
        $this->db = $db;
    }

    /**
     * Retorna o link Waze de um radar como array puro (DBAL), ou null.
     */
    public function findRawByRadarId(int $radarId): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT wl.*, ui.email AS inserted_by_email, uu.email AS updated_by_email
             FROM radar_waze_link wl
             JOIN user ui ON ui.id = wl.inserted_by
             LEFT JOIN user uu ON uu.id = wl.updated_by
             WHERE wl.radar_medidor_id = ?',
            [$radarId]
        );

        return $row ?: null;
    }

    /**
     * Retorna o histórico de alterações de um link Waze (ordenado do mais recente).
     */
    public function findLogByLinkId(int $linkId): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT wll.*, u.email AS changed_by_email
             FROM radar_waze_link_log wll
             JOIN user u ON u.id = wll.changed_by
             WHERE wll.radar_waze_link_id = ?
             ORDER BY wll.changed_at DESC',
            [$linkId]
        );
    }
}
