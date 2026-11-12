<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261110010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add accessory_product_indexes to delivery_order for Zubehör grouping';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE delivery_order ADD accessory_product_indexes JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE delivery_order DROP accessory_product_indexes');
    }
}
