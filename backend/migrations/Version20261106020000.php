<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261106020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add product_background_colors to delivery_order for PDF article cell fills';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE delivery_order ADD product_background_colors JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE delivery_order DROP product_background_colors');
    }
}
