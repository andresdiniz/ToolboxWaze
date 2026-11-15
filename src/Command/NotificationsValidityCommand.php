<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\RadarMedidor;
use App\Service\Notification\NotificationTriggerService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:notificacoes:validade',
    description: 'Cria alertas de radares com validade nos próximos 30 dias.'
)]
final class NotificationsValidityCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NotificationTriggerService $triggers,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dias', null, InputOption::VALUE_REQUIRED, 'Janela em dias', '30');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $days = min(365, max(1, (int) $input->getOption('dias')));

        $start = new \DateTimeImmutable('today');
        $end = $start->modify('+' . $days . ' days');

        $radars = $this->em->createQueryBuilder()
            ->select('r')
            ->from(RadarMedidor::class, 'r')
            ->where('r.mergedIntoId IS NULL')
            ->andWhere('r.dataValidadeDate IS NOT NULL')
            ->andWhere('r.dataValidadeDate >= :start')
            ->andWhere('r.dataValidadeDate <= :end')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getQuery()
            ->getResult();

        $created = 0;

        foreach ($radars as $radar) {
            if ($this->triggers->validityReminder($radar) !== null) {
                $created++;
            }
        }

        $this->em->flush();

        $output->writeln(sprintf(
            'Alertas de validade criados: %d de %d radares.',
            $created,
            count($radars)
        ));

        return Command::SUCCESS;
    }
}
