<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261126220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add announcement_email_sent_at to delivery_order (Lieferankündigung)';
    }

    public function up(Schema $schema): void
    {
        $schemaManager = $this->connection->createSchemaManager();
        if (!$schemaManager->tablesExist(['delivery_order'])) {
            return;
        }

        $columns = $schemaManager->listTableColumns('delivery_order');
        if (!isset($columns['announcement_email_sent_at'])) {
            $this->addSql('ALTER TABLE delivery_order ADD announcement_email_sent_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        }
    }

    public function down(Schema $schema): void
    {
        $schemaManager = $this->connection->createSchemaManager();
        if (!$schemaManager->tablesExist(['delivery_order'])) {
            return;
        }

        $columns = $schemaManager->listTableColumns('delivery_order');
        if (isset($columns['announcement_email_sent_at'])) {
            $this->addSql('ALTER TABLE delivery_order DROP announcement_email_sent_at');
        }
    }
}
