<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261105050000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add cargo weight fields to delivery_route and delivery_order';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE delivery_route ADD total_weight_kg DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE delivery_order ADD weight_kg DOUBLE PRECISION DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE delivery_route DROP total_weight_kg');
        $this->addSql('ALTER TABLE delivery_order DROP weight_kg');
    }
}
