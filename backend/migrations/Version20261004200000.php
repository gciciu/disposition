<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261004200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Google geocode fields to delivery_order';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE delivery_order ADD address_valid BOOLEAN DEFAULT FALSE NOT NULL');
        $this->addSql('ALTER TABLE delivery_order ADD formatted_address VARCHAR(512) DEFAULT NULL');
        $this->addSql('ALTER TABLE delivery_order ADD lat DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE delivery_order ADD lng DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE delivery_order ADD place_id VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE delivery_order DROP address_valid');
        $this->addSql('ALTER TABLE delivery_order DROP formatted_address');
        $this->addSql('ALTER TABLE delivery_order DROP lat');
        $this->addSql('ALTER TABLE delivery_order DROP lng');
        $this->addSql('ALTER TABLE delivery_order DROP place_id');
    }
}
