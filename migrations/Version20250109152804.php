<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250109152804 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE article (id INT AUTO_INCREMENT NOT NULL, tranche_id INT DEFAULT NULL, note_id INT DEFAULT NULL, nom VARCHAR(255) NOT NULL, pourcentage DOUBLE PRECISION NOT NULL, INDEX IDX_23A0E66B76F6B31 (tranche_id), INDEX IDX_23A0E6626ED0855 (note_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE note (id INT AUTO_INCREMENT NOT NULL, invite_id INT DEFAULT NULL, client_id INT DEFAULT NULL, partenaire_id INT DEFAULT NULL, assureur_id INT DEFAULT NULL, nom VARCHAR(255) NOT NULL, type INT NOT NULL, addressed_to INT NOT NULL, description VARCHAR(255) DEFAULT NULL, INDEX IDX_CFBDFA14EA417747 (invite_id), INDEX IDX_CFBDFA1419EB6921 (client_id), INDEX IDX_CFBDFA1498DE13AC (partenaire_id), INDEX IDX_CFBDFA1480F7E20A (assureur_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE note_compte_bancaire (note_id INT NOT NULL, compte_bancaire_id INT NOT NULL, INDEX IDX_956D59AE26ED0855 (note_id), INDEX IDX_956D59AEAF1E371E (compte_bancaire_id), PRIMARY KEY(note_id, compte_bancaire_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE article ADD CONSTRAINT FK_23A0E66B76F6B31 FOREIGN KEY (tranche_id) REFERENCES tranche (id)');
        $this->addSql('ALTER TABLE article ADD CONSTRAINT FK_23A0E6626ED0855 FOREIGN KEY (note_id) REFERENCES note (id)');
        $this->addSql('ALTER TABLE note ADD CONSTRAINT FK_CFBDFA14EA417747 FOREIGN KEY (invite_id) REFERENCES invite (id)');
        $this->addSql('ALTER TABLE note ADD CONSTRAINT FK_CFBDFA1419EB6921 FOREIGN KEY (client_id) REFERENCES client (id)');
        $this->addSql('ALTER TABLE note ADD CONSTRAINT FK_CFBDFA1498DE13AC FOREIGN KEY (partenaire_id) REFERENCES partenaire (id)');
        $this->addSql('ALTER TABLE note ADD CONSTRAINT FK_CFBDFA1480F7E20A FOREIGN KEY (assureur_id) REFERENCES assureur (id)');
        $this->addSql('ALTER TABLE note_compte_bancaire ADD CONSTRAINT FK_956D59AE26ED0855 FOREIGN KEY (note_id) REFERENCES note (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE note_compte_bancaire ADD CONSTRAINT FK_956D59AEAF1E371E FOREIGN KEY (compte_bancaire_id) REFERENCES compte_bancaire (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE article DROP FOREIGN KEY FK_23A0E66B76F6B31');
        $this->addSql('ALTER TABLE article DROP FOREIGN KEY FK_23A0E6626ED0855');
        $this->addSql('ALTER TABLE note DROP FOREIGN KEY FK_CFBDFA14EA417747');
        $this->addSql('ALTER TABLE note DROP FOREIGN KEY FK_CFBDFA1419EB6921');
        $this->addSql('ALTER TABLE note DROP FOREIGN KEY FK_CFBDFA1498DE13AC');
        $this->addSql('ALTER TABLE note DROP FOREIGN KEY FK_CFBDFA1480F7E20A');
        $this->addSql('ALTER TABLE note_compte_bancaire DROP FOREIGN KEY FK_956D59AE26ED0855');
        $this->addSql('ALTER TABLE note_compte_bancaire DROP FOREIGN KEY FK_956D59AEAF1E371E');
        $this->addSql('DROP TABLE article');
        $this->addSql('DROP TABLE note');
        $this->addSql('DROP TABLE note_compte_bancaire');
    }
}
