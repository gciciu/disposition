<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261017210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Renumber delivery_order positions to start from 1 within each route';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE delivery_order AS o
            SET position = ranked.new_position
            FROM (
                SELECT
                    id,
                    ROW_NUMBER() OVER (PARTITION BY route_id ORDER BY position ASC, id ASC) AS new_position
                FROM delivery_order
            ) AS ranked
            WHERE o.id = ranked.id
        SQL);
    }

    public function down(Schema $schema): void
    {
        // Irreversible data normalization.
    }
}
