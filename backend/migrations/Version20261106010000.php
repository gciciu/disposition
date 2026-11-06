<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261106010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add unloading_duration_minutes to delivery_order (default 10)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE delivery_order ADD unloading_duration_minutes INT DEFAULT 10 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE delivery_order DROP unloading_duration_minutes');
    }
}
