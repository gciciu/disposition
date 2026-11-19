<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261114220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add delay_minutes to delivery_route for cumulative courier-reported delay';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE delivery_route ADD delay_minutes INT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE delivery_route DROP delay_minutes');
    }
}
