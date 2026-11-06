<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261106050000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add email column to delivery_order for future notification sending';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE delivery_order ADD email VARCHAR(180) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE delivery_order DROP email');
    }
}
