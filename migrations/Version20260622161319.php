<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260622161319 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE coupon (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(40) NOT NULL, type VARCHAR(10) NOT NULL, valeur DOUBLE PRECISION NOT NULL, date_debut DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', date_fin DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', usage_limit INT DEFAULT NULL, usage_count INT NOT NULL, actif TINYINT(1) NOT NULL, pack_cible VARCHAR(50) DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX UNIQ_64BF3F0277153098 (code), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE plateforme_parametres (id INT AUTO_INCREMENT NOT NULL, packs JSON DEFAULT NULL COMMENT \'(DC2Type:json)\', free_allowance INT DEFAULT NULL, free_window_hours INT DEFAULT NULL, read_weight INT DEFAULT NULL, default_write_weight INT DEFAULT NULL, write_weights JSON DEFAULT NULL COMMENT \'(DC2Type:json)\', usd_per_token DOUBLE PRECISION DEFAULT NULL, updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE token_purchase ADD remise_usd DOUBLE PRECISION DEFAULT \'0\' NOT NULL, ADD coupon_code VARCHAR(40) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE coupon');
        $this->addSql('DROP TABLE plateforme_parametres');
        $this->addSql('ALTER TABLE token_purchase DROP remise_usd, DROP coupon_code');
    }
}
