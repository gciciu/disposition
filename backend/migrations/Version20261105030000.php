<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261105030000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add subcontractor table and link couriers to subcontractors';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE subcontractor (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE courier ADD subcontractor_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE courier ADD CONSTRAINT FK_CF134C7C389244BD FOREIGN KEY (subcontractor_id) REFERENCES subcontractor (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_CF134C7C389244BD ON courier (subcontractor_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE courier DROP FOREIGN KEY FK_CF134C7C389244BD');
        $this->addSql('DROP INDEX IDX_CF134C7C389244BD ON courier');
        $this->addSql('ALTER TABLE courier DROP subcontractor_id');
        $this->addSql('DROP TABLE subcontractor');
    }
}
