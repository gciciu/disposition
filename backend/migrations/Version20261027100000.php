<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Initial MySQL schema (replaces former PostgreSQL migration chain).
 */
final class Version20261027100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create MySQL schema for delivery app + messenger_messages';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE courier (id INT AUTO_INCREMENT NOT NULL, phone VARCHAR(32) DEFAULT NULL, user_id INT NOT NULL, UNIQUE INDEX UNIQ_CF134C7CA76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE delivery_order (id INT AUTO_INCREMENT NOT NULL, position INT NOT NULL, client_name VARCHAR(255) NOT NULL, phone VARCHAR(64) DEFAULT NULL, address VARCHAR(512) NOT NULL, address_valid TINYINT NOT NULL, formatted_address VARCHAR(512) DEFAULT NULL, lat DOUBLE PRECISION DEFAULT NULL, lng DOUBLE PRECISION DEFAULT NULL, place_id VARCHAR(255) DEFAULT NULL, travel_duration_seconds INT DEFAULT NULL, travel_distance_meters INT DEFAULT NULL, products JSON NOT NULL, delivered_product_indexes JSON DEFAULT NULL, status VARCHAR(32) NOT NULL, completed_at DATETIME DEFAULT NULL, proof_type VARCHAR(32) DEFAULT NULL, proof_path VARCHAR(512) DEFAULT NULL, proof_mime VARCHAR(128) DEFAULT NULL, created_at DATETIME NOT NULL, route_id INT NOT NULL, INDEX IDX_E522750A34ECB4E6 (route_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE delivery_route (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, source_filename VARCHAR(255) DEFAULT NULL, total_duration_seconds INT DEFAULT NULL, total_distance_meters INT DEFAULT NULL, maps_url VARCHAR(2048) DEFAULT NULL, created_at DATETIME NOT NULL, origin_type VARCHAR(32) NOT NULL, started_at DATETIME DEFAULT NULL, completed_at DATETIME DEFAULT NULL, start_lat DOUBLE PRECISION DEFAULT NULL, start_lng DOUBLE PRECISION DEFAULT NULL, courier_id INT DEFAULT NULL, warehouse_id INT DEFAULT NULL, INDEX IDX_12CFC6EBE3D8151C (courier_id), INDEX IDX_12CFC6EB5080ECDE (warehouse_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE refresh_token (id INT AUTO_INCREMENT NOT NULL, token_hash VARCHAR(64) NOT NULL, remember TINYINT NOT NULL, expires_at DATETIME NOT NULL, created_at DATETIME NOT NULL, user_id INT NOT NULL, INDEX IDX_REFRESH_TOKEN_EXPIRES (expires_at), UNIQUE INDEX UNIQ_REFRESH_TOKEN_HASH (token_hash), INDEX IDX_C74F2195A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE `user` (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) NOT NULL, name VARCHAR(255) NOT NULL, is_active TINYINT NOT NULL, must_change_password TINYINT NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_USER_EMAIL (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE warehouse (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, address VARCHAR(512) NOT NULL, formatted_address VARCHAR(512) DEFAULT NULL, lat DOUBLE PRECISION DEFAULT NULL, lng DOUBLE PRECISION DEFAULT NULL, place_id VARCHAR(255) DEFAULT NULL, is_default TINYINT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 (queue_name, available_at, delivered_at, id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE courier ADD CONSTRAINT FK_CF134C7CA76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE delivery_order ADD CONSTRAINT FK_E522750A34ECB4E6 FOREIGN KEY (route_id) REFERENCES delivery_route (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE delivery_route ADD CONSTRAINT FK_12CFC6EBE3D8151C FOREIGN KEY (courier_id) REFERENCES courier (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE delivery_route ADD CONSTRAINT FK_12CFC6EB5080ECDE FOREIGN KEY (warehouse_id) REFERENCES warehouse (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE refresh_token ADD CONSTRAINT FK_C74F2195A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE courier DROP FOREIGN KEY FK_CF134C7CA76ED395');
        $this->addSql('ALTER TABLE delivery_order DROP FOREIGN KEY FK_E522750A34ECB4E6');
        $this->addSql('ALTER TABLE delivery_route DROP FOREIGN KEY FK_12CFC6EBE3D8151C');
        $this->addSql('ALTER TABLE delivery_route DROP FOREIGN KEY FK_12CFC6EB5080ECDE');
        $this->addSql('ALTER TABLE refresh_token DROP FOREIGN KEY FK_C74F2195A76ED395');
        $this->addSql('DROP TABLE courier');
        $this->addSql('DROP TABLE delivery_order');
        $this->addSql('DROP TABLE delivery_route');
        $this->addSql('DROP TABLE refresh_token');
        $this->addSql('DROP TABLE `user`');
        $this->addSql('DROP TABLE warehouse');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
