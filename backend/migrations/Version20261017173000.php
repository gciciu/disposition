<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261017173000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add is_default flag to warehouse';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE warehouse ADD is_default BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE warehouse DROP is_default');
    }
}
