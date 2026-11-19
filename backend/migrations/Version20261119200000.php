<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261119200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add delivery_method, neighbor_name, confirmation_email_sent_at to delivery_order (Doc1 §4)';
    }

    public function up(Schema $schema): void
    {
        $columns = $this->connection->createSchemaManager()->listTableColumns('delivery_order');

        if (!isset($columns['delivery_method'])) {
            $this->addSql('ALTER TABLE delivery_order ADD delivery_method VARCHAR(32) DEFAULT NULL');
        }
        if (!isset($columns['neighbor_name'])) {
            $this->addSql('ALTER TABLE delivery_order ADD neighbor_name VARCHAR(255) DEFAULT NULL');
        }
        if (!isset($columns['confirmation_email_sent_at'])) {
            $this->addSql('ALTER TABLE delivery_order ADD confirmation_email_sent_at DATETIME DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE delivery_order DROP delivery_method');
        $this->addSql('ALTER TABLE delivery_order DROP neighbor_name');
        $this->addSql('ALTER TABLE delivery_order DROP confirmation_email_sent_at');
    }
}
