<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261030023000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add completion_pdf_path to delivery_route';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE delivery_route ADD completion_pdf_path VARCHAR(512) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE delivery_route DROP completion_pdf_path');
    }
}
