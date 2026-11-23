<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261122220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add confirmation email mode and test recipient to app_settings';
    }

    public function up(Schema $schema): void
    {
        $schemaManager = $this->connection->createSchemaManager();
        if (!$schemaManager->tablesExist(['app_settings'])) {
            return;
        }

        $columns = $schemaManager->listTableColumns('app_settings');
        if (!isset($columns['confirmation_email_mode'])) {
            $this->addSql("ALTER TABLE app_settings ADD confirmation_email_mode VARCHAR(16) DEFAULT 'test' NOT NULL");
        }
        if (!isset($columns['confirmation_email_test_recipient'])) {
            $this->addSql('ALTER TABLE app_settings ADD confirmation_email_test_recipient VARCHAR(255) DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $schemaManager = $this->connection->createSchemaManager();
        if (!$schemaManager->tablesExist(['app_settings'])) {
            return;
        }

        $columns = $schemaManager->listTableColumns('app_settings');
        if (isset($columns['confirmation_email_test_recipient'])) {
            $this->addSql('ALTER TABLE app_settings DROP confirmation_email_test_recipient');
        }
        if (isset($columns['confirmation_email_mode'])) {
            $this->addSql('ALTER TABLE app_settings DROP confirmation_email_mode');
        }
    }
}
