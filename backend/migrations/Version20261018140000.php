<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261018140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add phone column to courier';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE courier ADD phone VARCHAR(32) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE courier DROP phone');
    }
}
