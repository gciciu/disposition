<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261018120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Track route start/completion and courier start coordinates';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE delivery_route ADD started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE delivery_route ADD completed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE delivery_route ADD start_lat DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE delivery_route ADD start_lng DOUBLE PRECISION DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE delivery_route DROP started_at');
        $this->addSql('ALTER TABLE delivery_route DROP completed_at');
        $this->addSql('ALTER TABLE delivery_route DROP start_lat');
        $this->addSql('ALTER TABLE delivery_route DROP start_lng');
    }
}
