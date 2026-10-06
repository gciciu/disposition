<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261006213000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store route leg duration/distance and totals computed at import';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE delivery_order ADD travel_duration_seconds INT DEFAULT NULL');
        $this->addSql('ALTER TABLE delivery_order ADD travel_distance_meters INT DEFAULT NULL');
        $this->addSql('ALTER TABLE delivery_route ADD total_duration_seconds INT DEFAULT NULL');
        $this->addSql('ALTER TABLE delivery_route ADD total_distance_meters INT DEFAULT NULL');
        $this->addSql('ALTER TABLE delivery_route ADD maps_url VARCHAR(2048) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE delivery_order DROP travel_duration_seconds');
        $this->addSql('ALTER TABLE delivery_order DROP travel_distance_meters');
        $this->addSql('ALTER TABLE delivery_route DROP total_duration_seconds');
        $this->addSql('ALTER TABLE delivery_route DROP total_distance_meters');
        $this->addSql('ALTER TABLE delivery_route DROP maps_url');
    }
}
