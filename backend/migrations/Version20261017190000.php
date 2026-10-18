<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261017190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add route origin_type (warehouse / courier_location / none)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE delivery_route ADD origin_type VARCHAR(32) DEFAULT 'warehouse' NOT NULL");
        $this->addSql("UPDATE delivery_route SET origin_type = 'none' WHERE warehouse_id IS NULL");
        $this->addSql("UPDATE delivery_route SET origin_type = 'warehouse' WHERE warehouse_id IS NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE delivery_route DROP origin_type');
    }
}
