<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261105060000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add comment field to delivery_order for imported PDF remarks';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE delivery_order ADD comment TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE delivery_order DROP comment');
    }
}
