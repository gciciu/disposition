<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261106030000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store courier subcontractor as plain text and drop subcontractor entity';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE courier ADD subcontractor VARCHAR(255) DEFAULT NULL');
        $this->addSql('UPDATE courier c LEFT JOIN subcontractor s ON c.subcontractor_id = s.id SET c.subcontractor = s.name WHERE c.subcontractor_id IS NOT NULL');
        $this->addSql('ALTER TABLE courier DROP FOREIGN KEY FK_CF134C7C389244BD');
        $this->addSql('DROP INDEX IDX_CF134C7C389244BD ON courier');
        $this->addSql('ALTER TABLE courier DROP subcontractor_id');
        $this->addSql('DROP TABLE subcontractor');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TABLE subcontractor (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, phone VARCHAR(32) DEFAULT NULL, email VARCHAR(180) DEFAULT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE courier ADD subcontractor_id INT DEFAULT NULL');
        $this->addSql('INSERT INTO subcontractor (name, created_at) SELECT DISTINCT c.subcontractor, NOW() FROM courier c WHERE c.subcontractor IS NOT NULL AND c.subcontractor != \'\'');
        $this->addSql('UPDATE courier c INNER JOIN subcontractor s ON s.name = c.subcontractor SET c.subcontractor_id = s.id');
        $this->addSql('ALTER TABLE courier ADD CONSTRAINT FK_CF134C7C389244BD FOREIGN KEY (subcontractor_id) REFERENCES subcontractor (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_CF134C7C389244BD ON courier (subcontractor_id)');
        $this->addSql('ALTER TABLE courier DROP subcontractor');
    }
}
