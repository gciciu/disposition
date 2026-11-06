<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261105031000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add phone and email to subcontractor';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE subcontractor ADD phone VARCHAR(32) DEFAULT NULL, ADD email VARCHAR(180) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE subcontractor DROP phone, DROP email');
    }
}
