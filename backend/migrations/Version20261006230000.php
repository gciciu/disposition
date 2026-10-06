<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261006230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Assign courier to delivery route';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE delivery_route ADD courier_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE delivery_route ADD CONSTRAINT FK_DELIVERY_ROUTE_COURIER FOREIGN KEY (courier_id) REFERENCES courier (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_DELIVERY_ROUTE_COURIER ON delivery_route (courier_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE delivery_route DROP CONSTRAINT FK_DELIVERY_ROUTE_COURIER');
        $this->addSql('DROP INDEX IDX_DELIVERY_ROUTE_COURIER');
        $this->addSql('ALTER TABLE delivery_route DROP courier_id');
    }
}
