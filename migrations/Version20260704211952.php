<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Portefeuille client : nouvelle entité (nom + gestionnaire invité) et rattachement
 * optionnel des clients (client.portefeuille_id).
 */
final class Version20260704211952 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Portefeuille client rattaché à un gestionnaire de compte + relation Client.portefeuille';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE portefeuille (id INT AUTO_INCREMENT NOT NULL, gestionnaire_id INT NOT NULL, entreprise_id INT NOT NULL, invite_id INT DEFAULT NULL, nom VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_2955FFFE6885AC1B (gestionnaire_id), INDEX IDX_2955FFFEA4AEAFEA (entreprise_id), INDEX IDX_2955FFFEEA417747 (invite_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE portefeuille ADD CONSTRAINT FK_2955FFFE6885AC1B FOREIGN KEY (gestionnaire_id) REFERENCES invite (id)');
        $this->addSql('ALTER TABLE portefeuille ADD CONSTRAINT FK_2955FFFEA4AEAFEA FOREIGN KEY (entreprise_id) REFERENCES entreprise (id)');
        $this->addSql('ALTER TABLE portefeuille ADD CONSTRAINT FK_2955FFFEEA417747 FOREIGN KEY (invite_id) REFERENCES invite (id)');
        $this->addSql('ALTER TABLE client ADD portefeuille_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE client ADD CONSTRAINT FK_C7440455513EC3CA FOREIGN KEY (portefeuille_id) REFERENCES portefeuille (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_C7440455513EC3CA ON client (portefeuille_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE client DROP FOREIGN KEY FK_C7440455513EC3CA');
        $this->addSql('DROP INDEX IDX_C7440455513EC3CA ON client');
        $this->addSql('ALTER TABLE client DROP portefeuille_id');
        $this->addSql('ALTER TABLE portefeuille DROP FOREIGN KEY FK_2955FFFE6885AC1B');
        $this->addSql('ALTER TABLE portefeuille DROP FOREIGN KEY FK_2955FFFEA4AEAFEA');
        $this->addSql('ALTER TABLE portefeuille DROP FOREIGN KEY FK_2955FFFEEA417747');
        $this->addSql('DROP TABLE portefeuille');
    }
}
