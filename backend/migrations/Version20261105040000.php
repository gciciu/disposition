<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261105040000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add scheduled date/time to delivery_route and unique courier+date constraint';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE delivery_route ADD scheduled_date DATE DEFAULT NULL, ADD scheduled_start_time TIME DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_delivery_route_courier_scheduled_date ON delivery_route (courier_id, scheduled_date)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_delivery_route_courier_scheduled_date ON delivery_route');
        $this->addSql('ALTER TABLE delivery_route DROP scheduled_date, DROP scheduled_start_time');
    }
}
