<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261120220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create app_settings table for PDF report recipient email';
    }

    public function up(Schema $schema): void
    {
        $schemaManager = $this->connection->createSchemaManager();
        if ($schemaManager->tablesExist(['app_settings'])) {
            return;
        }

        $this->addSql('CREATE TABLE app_settings (id INT AUTO_INCREMENT NOT NULL, pdf_report_recipient_email VARCHAR(255) DEFAULT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE app_settings');
    }
}
