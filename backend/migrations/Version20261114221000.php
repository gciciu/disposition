<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261114221000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Align delivery_order.courier_comment with Doctrine text mapping (LONGTEXT)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE delivery_order CHANGE courier_comment courier_comment LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE delivery_order CHANGE courier_comment courier_comment TEXT DEFAULT NULL');
    }
}
