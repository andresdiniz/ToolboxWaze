<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\BrazilianState;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

/**
 * Popula a tabela brazilian_state com os 27 estados brasileiros.
 *
 * Uso:
 *   php bin/console doctrine:fixtures:load
 *   php bin/console doctrine:fixtures:load --append   (não apaga outros fixtures)
 */
class BrazilianStateFixtures extends Fixture
{
    private const STATES = [
        // [UF, Nome, Região]
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

    public function load(ObjectManager $manager): void
    {
        foreach (self::STATES as [$uf, $name, $region]) {
            // Evita duplicar se rodar --append
            $existing = $manager->getRepository(BrazilianState::class)->findOneBy(['uf' => $uf]);

            if ($existing !== null) {
                continue;
            }

            $state = new BrazilianState($uf, $name, $region);
            $manager->persist($state);
        }

        $manager->flush();
    }
}
