<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\WmeDispositivo;
use App\Service\Notification\NotificationTriggerService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:notificacoes:dispositivos-expirando',
    description: 'Cria alertas para dispositivos WME próximos da expiração de 90 dias.'
)]
final class NotificationsDeviceExpiringCommand extends Command
{
    private const EXPIRATION_DAYS = 90;
    private const WARNING_DAYS = 7;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NotificationTriggerService $triggers,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = (new \DateTimeImmutable())->modify(
            '-' . (self::EXPIRATION_DAYS - self::WARNING_DAYS) . ' days'
        );

        $devices = $this->em->createQueryBuilder()
            ->select('d')
            ->from(WmeDispositivo::class, 'd')
            ->where('d.estado = :estado')
            ->andWhere('COALESCE(d.ultimoUsoEm, d.ativadoEm, d.criadoEm) <= :limit')
            ->setParameter('estado', WmeDispositivo::ESTADO_ATIVO)
            ->setParameter('limit', $limit)
            ->getQuery()
            ->getResult();

        $created = 0;

        foreach ($devices as $device) {
            if ($this->triggers->deviceExpiring($device) !== null) {
                $created++;
            }
        }

        $this->em->flush();

        $output->writeln(sprintf(
            'Alertas de expiração criados: %d de %d dispositivos.',
            $created,
            count($devices)
        ));

        return Command::SUCCESS;
    }
}