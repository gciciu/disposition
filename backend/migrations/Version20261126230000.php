<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261126230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add return_destination to delivery_order (Retourschein disposition)';
    }

    public function up(Schema $schema): void
    {
        $schemaManager = $this->connection->createSchemaManager();
        if (!$schemaManager->tablesExist(['delivery_order'])) {
            return;
        }

        $columns = $schemaManager->listTableColumns('delivery_order');
        if (!isset($columns['return_destination'])) {
            $this->addSql('ALTER TABLE delivery_order ADD return_destination VARCHAR(32) DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $schemaManager = $this->connection->createSchemaManager();
        if (!$schemaManager->tablesExist(['delivery_order'])) {
            return;
        }

        $columns = $schemaManager->listTableColumns('delivery_order');
        if (isset($columns['return_destination'])) {
            $this->addSql('ALTER TABLE delivery_order DROP return_destination');
        }
    }
}
