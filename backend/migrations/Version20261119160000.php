<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261119160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add delivery_order.arrival_delay_minutes for courier arrival adjustments';
    }

    public function up(Schema $schema): void
    {
        // Idempotent: column may already exist from a prior schema:update on local-dev.
        $columns = $this->connection->createSchemaManager()->listTableColumns('delivery_order');
        if (!isset($columns['arrival_delay_minutes'])) {
            $this->addSql('ALTER TABLE delivery_order ADD arrival_delay_minutes INT DEFAULT 0 NOT NULL');
        }

        // Preserve existing route-level delays on the first stop of each route.
        // Only fill stops that still have 0 so a re-run does not double-add.
        $this->addSql(<<<'SQL'
            UPDATE delivery_order o
            INNER JOIN delivery_route r ON o.route_id = r.id
            INNER JOIN (
                SELECT route_id, MIN(position) AS min_position
                FROM delivery_order
                GROUP BY route_id
            ) first_stop ON first_stop.route_id = o.route_id AND first_stop.min_position = o.position
            SET o.arrival_delay_minutes = o.arrival_delay_minutes + r.delay_minutes
            WHERE r.delay_minutes > 0
              AND o.arrival_delay_minutes = 0
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE delivery_order DROP arrival_delay_minutes');
    }
}
