<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261006220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add must_change_password flag for courier first login';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD must_change_password BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP must_change_password');
    }
}
