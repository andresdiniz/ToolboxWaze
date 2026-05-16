<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\BrazilianState;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Popula a tabela brazilian_state com os 27 estados brasileiros.
 * Não requer DoctrineFixturesBundle.
 *
 * Uso:
 *   php bin/console app:seed-states            # insere/ignora existentes
 *   php bin/console app:seed-states --reset    # apaga tudo e recria
 */
#[AsCommand(
    name: 'app:seed-states',
    description: 'Popula a tabela brazilian_state com os 27 estados do Brasil',
)]
final class SeedBrazilianStatesCommand extends Command
{
    private const STATES = [
        ['AC', 'Acre',                'Norte'],
        ['AL', 'Alagoas',             'Nordeste'],
        ['AM', 'Amazonas',            'Norte'],
        ['AP', 'Amapá',               'Norte'],
        ['BA', 'Bahia',               'Nordeste'],
        ['CE', 'Ceará',               'Nordeste'],
        ['DF', 'Distrito Federal',    'Centro-Oeste'],
        ['ES', 'Espírito Santo',      'Sudeste'],
        ['GO', 'Goiás',               'Centro-Oeste'],
        ['MA', 'Maranhão',            'Nordeste'],
        ['MG', 'Minas Gerais',        'Sudeste'],
        ['MS', 'Mato Grosso do Sul',  'Centro-Oeste'],
        ['MT', 'Mato Grosso',         'Centro-Oeste'],
        ['PA', 'Pará',                'Norte'],
        ['PB', 'Paraíba',             'Nordeste'],
        ['PE', 'Pernambuco',          'Nordeste'],
        ['PI', 'Piauí',               'Nordeste'],
        ['PR', 'Paraná',              'Sul'],
        ['RJ', 'Rio de Janeiro',      'Sudeste'],
        ['RN', 'Rio Grande do Norte', 'Nordeste'],
        ['RO', 'Rondônia',            'Norte'],
        ['RR', 'Roraima',             'Norte'],
        ['RS', 'Rio Grande do Sul',   'Sul'],
        ['SC', 'Santa Catarina',      'Sul'],
        ['SE', 'Sergipe',             'Nordeste'],
        ['SP', 'São Paulo',           'Sudeste'],
        ['TO', 'Tocantins',           'Norte'],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'reset',
            null,
            InputOption::VALUE_NONE,
            'Apaga todos os registros existentes antes de inserir'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io   = new SymfonyStyle($input, $output);
        $repo = $this->em->getRepository(BrazilianState::class);

        $io->title('Seed — Estados Brasileiros');

        if ($input->getOption('reset')) {
            $io->warning('Apagando registros existentes...');
            foreach ($repo->findAll() as $state) {
                $this->em->remove($state);
            }
            $this->em->flush();
        }

        $inserted = 0;
        $skipped  = 0;

        foreach (self::STATES as [$uf, $name, $region]) {
            if ($repo->findOneBy(['uf' => $uf]) !== null) {
                ++$skipped;
                continue;
            }

            $this->em->persist(new BrazilianState($uf, $name, $region));
            ++$inserted;
        }

        $this->em->flush();

        $io->table(
            ['Resultado', 'Quantidade'],
            [
                ['Inseridos',  $inserted],
                ['Já existiam (ignorados)', $skipped],
                ['Total na tabela', $inserted + $skipped],
            ]
        );

        $io->success('Tabela brazilian_state pronta!');

        return Command::SUCCESS;
    }
}
