<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261115120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cadastra os tipos de solicitação do Twig no FormBuilder para unificar site e WME.';
    }

    public function up(Schema $schema): void
    {
        $forms = [
            ['Imagem de Satélite', 'imagem_satelite', 'Solicitação de imagem de satélite.'],
            ['Gerente de Área', 'gerente_area', 'Solicitação relacionada a Gerente de Área.'],
            ['Gerente de Estado/País', 'gerente_estado_pais', 'Solicitação relacionada a Gerente de Estado ou País.'],
            ['Nível (Upgrade/Downgrade)', 'nivel', 'Solicitação de alteração de nível.'],
            ['Oops de Editor', 'oops', 'Solicitação de correção de Oops.'],
            ['Bandeira de Posto de Gasolina', 'bandeira_posto', 'Solicitação de bandeira de posto.'],
            ['Cadastro de ID de Segmento', 'id_segmento', 'Solicitação de cadastro de ID de segmento.'],
        ];

        foreach ($forms as [$nome, $slug, $descricao]) {
            $exists = $this->connection->fetchOne(
                'SELECT id FROM form_builder WHERE slug = ?',
                [$slug]
            );

            if ($exists !== false) {
                continue;
            }

            $this->connection->insert('form_builder', [
                'nome' => $nome,
                'descricao' => $descricao,
                'slug' => $slug,
                'configuracoes' => json_encode([
                    'wme_api_enabled' => true,
                    'mostrar_para' => 'todos',
                    'wme_roles' => [],
                ], JSON_UNESCAPED_UNICODE),
                'ativo' => 1,
                'criado_em' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM form_builder WHERE slug IN (
            'imagem_satelite',
            'gerente_area',
            'gerente_estado_pais',
            'nivel',
            'oops',
            'bandeira_posto',
            'id_segmento'
        )");
    }
}