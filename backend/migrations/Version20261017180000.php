<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261017180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Link delivery_route to warehouse as route origin';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE delivery_route ADD warehouse_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE delivery_route ADD CONSTRAINT FK_DELIVERY_ROUTE_WAREHOUSE FOREIGN KEY (warehouse_id) REFERENCES warehouse (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_DELIVERY_ROUTE_WAREHOUSE ON delivery_route (warehouse_id)');
        // Existing routes get the current default warehouse when one exists.
        $this->addSql('UPDATE delivery_route SET warehouse_id = (SELECT id FROM warehouse WHERE is_default = true ORDER BY id ASC LIMIT 1) WHERE warehouse_id IS NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE delivery_route DROP CONSTRAINT FK_DELIVERY_ROUTE_WAREHOUSE');
        $this->addSql('DROP INDEX IDX_DELIVERY_ROUTE_WAREHOUSE');
        $this->addSql('ALTER TABLE delivery_route DROP warehouse_id');
    }
}
