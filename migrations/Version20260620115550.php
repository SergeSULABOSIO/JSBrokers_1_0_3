<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260620115550 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE token_consumption (id INT AUTO_INCREMENT NOT NULL, entreprise_id INT NOT NULL, proprietaire_id INT NOT NULL, acteur_id INT DEFAULT NULL, entite_nom VARCHAR(100) NOT NULL, sens VARCHAR(10) NOT NULL, nombre INT NOT NULL, poids_unitaire INT NOT NULL, poids_total INT NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_CE7F63A4AEAFEA (entreprise_id), INDEX IDX_CE7F6376C50E4A (proprietaire_id), INDEX IDX_CE7F63DA6F574A (acteur_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE token_purchase (id INT AUTO_INCREMENT NOT NULL, utilisateur_id INT NOT NULL, pack VARCHAR(50) NOT NULL, tokens INT NOT NULL, montant_usd DOUBLE PRECISION NOT NULL, card_last4 VARCHAR(4) DEFAULT NULL, reference VARCHAR(40) NOT NULL, status VARCHAR(30) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_15480CB8FB88E14F (utilisateur_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE token_consumption ADD CONSTRAINT FK_CE7F63A4AEAFEA FOREIGN KEY (entreprise_id) REFERENCES entreprise (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE token_consumption ADD CONSTRAINT FK_CE7F6376C50E4A FOREIGN KEY (proprietaire_id) REFERENCES utilisateur (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE token_consumption ADD CONSTRAINT FK_CE7F63DA6F574A FOREIGN KEY (acteur_id) REFERENCES utilisateur (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE token_purchase ADD CONSTRAINT FK_15480CB8FB88E14F FOREIGN KEY (utilisateur_id) REFERENCES utilisateur (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE utilisateur ADD paid_tokens BIGINT DEFAULT 0 NOT NULL, ADD free_tokens INT DEFAULT 1000 NOT NULL, ADD free_window_started_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE token_consumption DROP FOREIGN KEY FK_CE7F63A4AEAFEA');
        $this->addSql('ALTER TABLE token_consumption DROP FOREIGN KEY FK_CE7F6376C50E4A');
        $this->addSql('ALTER TABLE token_consumption DROP FOREIGN KEY FK_CE7F63DA6F574A');
        $this->addSql('ALTER TABLE token_purchase DROP FOREIGN KEY FK_15480CB8FB88E14F');
        $this->addSql('DROP TABLE token_consumption');
        $this->addSql('DROP TABLE token_purchase');
        $this->addSql('ALTER TABLE utilisateur DROP paid_tokens, DROP free_tokens, DROP free_window_started_at');
    }
}
