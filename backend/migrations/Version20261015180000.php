<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261015180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add delivery order status and proof-of-delivery fields';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE delivery_order ADD status VARCHAR(32) DEFAULT 'pending' NOT NULL");
        $this->addSql('ALTER TABLE delivery_order ADD completed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE delivery_order ADD proof_type VARCHAR(32) DEFAULT NULL');
        $this->addSql('ALTER TABLE delivery_order ADD proof_path VARCHAR(512) DEFAULT NULL');
        $this->addSql('ALTER TABLE delivery_order ADD proof_mime VARCHAR(128) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE delivery_order DROP proof_mime');
        $this->addSql('ALTER TABLE delivery_order DROP proof_path');
        $this->addSql('ALTER TABLE delivery_order DROP proof_type');
        $this->addSql('ALTER TABLE delivery_order DROP completed_at');
        $this->addSql('ALTER TABLE delivery_order DROP status');
    }
}
