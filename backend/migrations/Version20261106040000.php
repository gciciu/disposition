<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261106040000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Replace delivery_order.comment text with colored comments JSON list';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE delivery_order ADD comments JSON DEFAULT NULL');
        $this->addSql(<<<'SQL'
            UPDATE delivery_order
            SET comments = CASE
                WHEN comment IS NOT NULL AND TRIM(comment) <> ''
                    THEN JSON_ARRAY(JSON_OBJECT(
                        'id', REPLACE(UUID(), '-', ''),
                        'text', comment,
                        'color', 'amber'
                    ))
                ELSE JSON_ARRAY()
            END
        SQL);
        $this->addSql('ALTER TABLE delivery_order MODIFY comments JSON NOT NULL');
        $this->addSql('ALTER TABLE delivery_order DROP comment');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE delivery_order ADD comment LONGTEXT DEFAULT NULL');
        $this->addSql(<<<'SQL'
            UPDATE delivery_order
            SET comment = NULLIF(
                TRIM(BOTH ' ' FROM COALESCE(
                    JSON_UNQUOTE(JSON_EXTRACT(comments, '$[0].text')),
                    ''
                )),
                ''
            )
        SQL);
        $this->addSql('ALTER TABLE delivery_order DROP comments');
    }
}
