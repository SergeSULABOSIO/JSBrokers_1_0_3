<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20241214193738 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE cotation (id INT AUTO_INCREMENT NOT NULL, invite_id INT DEFAULT NULL, assureur_id INT DEFAULT NULL, nom VARCHAR(255) NOT NULL, duree INT NOT NULL, create_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_996DA944EA417747 (invite_id), INDEX IDX_996DA94480F7E20A (assureur_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE cotation ADD CONSTRAINT FK_996DA944EA417747 FOREIGN KEY (invite_id) REFERENCES invite (id)');
        $this->addSql('ALTER TABLE cotation ADD CONSTRAINT FK_996DA94480F7E20A FOREIGN KEY (assureur_id) REFERENCES assureur (id)');
        $this->addSql('ALTER TABLE chargement_pour_prime ADD cotation_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE chargement_pour_prime ADD CONSTRAINT FK_8065D3CA5D14FAF0 FOREIGN KEY (cotation_id) REFERENCES cotation (id)');
        $this->addSql('CREATE INDEX IDX_8065D3CA5D14FAF0 ON chargement_pour_prime (cotation_id)');
        $this->addSql('ALTER TABLE piste ADD description_du_risque VARCHAR(255) NOT NULL');
        $this->addSql('ALTER TABLE revenu_pour_courtier ADD cotation_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE revenu_pour_courtier ADD CONSTRAINT FK_8CAA04C15D14FAF0 FOREIGN KEY (cotation_id) REFERENCES cotation (id)');
        $this->addSql('CREATE INDEX IDX_8CAA04C15D14FAF0 ON revenu_pour_courtier (cotation_id)');
        $this->addSql('ALTER TABLE tache ADD cotation_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE tache ADD CONSTRAINT FK_938720755D14FAF0 FOREIGN KEY (cotation_id) REFERENCES cotation (id)');
        $this->addSql('CREATE INDEX IDX_938720755D14FAF0 ON tache (cotation_id)');
        $this->addSql('ALTER TABLE tranche ADD cotation_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE tranche ADD CONSTRAINT FK_666758405D14FAF0 FOREIGN KEY (cotation_id) REFERENCES cotation (id)');
        $this->addSql('CREATE INDEX IDX_666758405D14FAF0 ON tranche (cotation_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE chargement_pour_prime DROP FOREIGN KEY FK_8065D3CA5D14FAF0');
        $this->addSql('ALTER TABLE revenu_pour_courtier DROP FOREIGN KEY FK_8CAA04C15D14FAF0');
        $this->addSql('ALTER TABLE tache DROP FOREIGN KEY FK_938720755D14FAF0');
        $this->addSql('ALTER TABLE tranche DROP FOREIGN KEY FK_666758405D14FAF0');
        $this->addSql('ALTER TABLE cotation DROP FOREIGN KEY FK_996DA944EA417747');
        $this->addSql('ALTER TABLE cotation DROP FOREIGN KEY FK_996DA94480F7E20A');
        $this->addSql('DROP TABLE cotation');
        $this->addSql('DROP INDEX IDX_8065D3CA5D14FAF0 ON chargement_pour_prime');
        $this->addSql('ALTER TABLE chargement_pour_prime DROP cotation_id');
        $this->addSql('ALTER TABLE piste DROP description_du_risque');
        $this->addSql('DROP INDEX IDX_8CAA04C15D14FAF0 ON revenu_pour_courtier');
        $this->addSql('ALTER TABLE revenu_pour_courtier DROP cotation_id');
        $this->addSql('DROP INDEX IDX_938720755D14FAF0 ON tache');
        $this->addSql('ALTER TABLE tache DROP cotation_id');
        $this->addSql('DROP INDEX IDX_666758405D14FAF0 ON tranche');
        $this->addSql('ALTER TABLE tranche DROP cotation_id');
    }
}
