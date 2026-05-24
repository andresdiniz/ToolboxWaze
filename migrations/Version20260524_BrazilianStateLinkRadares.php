<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adiciona a coluna link_base_radares em brazilian_state.
 *
 * Armazena a URL completa de download CSV por estado,
 * permitindo personalizar a fonte de dados sem alterar código.
 */
final class Version20260524_BrazilianStateLinkRadares extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona link_base_radares (VARCHAR 500, nullable) em brazilian_state';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE brazilian_state
             ADD COLUMN link_base_radares VARCHAR(500) NULL DEFAULT NULL
             COMMENT "URL completa do CSV de radares para este estado (NULL = usa default do código)"'
        );

        // Preenche os links já conhecidos (os mesmos do UF_GID_MAP)
        $baseUrl = 'https://docs.google.com/spreadsheets/d/e/2PACX-1vS4vA88i8iSLxf0jxatyMI2hHQG_U8AIc5D8qSAMvH2Q8kPS1k3BWxlGCZVNOP3RkwTgNBlp84i-6zx/pub?output=csv';

        $gidMap = [
            'AC' => '1827024199',
            'AL' => '615899950',
            'AM' => '279389996',
            'AP' => '1337174808',
            'BA' => '973830127',
            'CE' => '158670580',
            'ES' => '17948801',
            'GO' => '256806712',
            'MA' => '1874573610',
            'MG' => '750233625',
            'MS' => '154970032',
            'MT' => '794262885',
            'PA' => '771805655',
            'PB' => '1250233818',
            'PE' => '1954639181',
            'PI' => '1262412902',
            'PR' => '2052829212',
            'RJ' => '1880219963',
            'RN' => '1196895486',
            'RO' => '794263067',
            'RR' => '1848043218',
            'RS' => '1570302815',
            'SC' => '1070009330',
            'SE' => '473072021',
            'SP' => '1492817692',
            'TO' => '846230006',
        ];

        foreach ($gidMap as $uf => $gid) {
            $url = $baseUrl . '&gid=' . $gid;
            $this->addSql(
                'UPDATE brazilian_state SET link_base_radares = :url WHERE uf = :uf',
                ['url' => $url, 'uf' => $uf]
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE brazilian_state DROP COLUMN link_base_radares');
    }
}
