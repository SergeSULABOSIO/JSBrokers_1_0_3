<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20241215105807 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE modele_piece_sinistre (id INT AUTO_INCREMENT NOT NULL, entreprise_id INT DEFAULT NULL, nom VARCHAR(255) NOT NULL, description VARCHAR(255) NOT NULL, INDEX IDX_3723B98AA4AEAFEA (entreprise_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE notification_sinistre (id INT AUTO_INCREMENT NOT NULL, assure_id INT DEFAULT NULL, risque_id INT DEFAULT NULL, invite_id INT DEFAULT NULL, description_de_fait VARCHAR(255) NOT NULL, occured_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', lieu VARCHAR(255) NOT NULL, reference_police VARCHAR(255) NOT NULL, reference_sinistre VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', dommage DOUBLE PRECISION DEFAULT NULL, INDEX IDX_A0BC42C21F4BE942 (assure_id), INDEX IDX_A0BC42C24ECC2413 (risque_id), INDEX IDX_A0BC42C2EA417747 (invite_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE piece_sinistre (id INT AUTO_INCREMENT NOT NULL, type_id INT DEFAULT NULL, invite_id INT DEFAULT NULL, notification_sinistre_id INT DEFAULT NULL, description VARCHAR(255) NOT NULL, received_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', fourni_par VARCHAR(255) NOT NULL, INDEX IDX_C1DE1588C54C8C93 (type_id), INDEX IDX_C1DE1588EA417747 (invite_id), INDEX IDX_C1DE1588F4F2559E (notification_sinistre_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE modele_piece_sinistre ADD CONSTRAINT FK_3723B98AA4AEAFEA FOREIGN KEY (entreprise_id) REFERENCES entreprise (id)');
        $this->addSql('ALTER TABLE notification_sinistre ADD CONSTRAINT FK_A0BC42C21F4BE942 FOREIGN KEY (assure_id) REFERENCES client (id)');
        $this->addSql('ALTER TABLE notification_sinistre ADD CONSTRAINT FK_A0BC42C24ECC2413 FOREIGN KEY (risque_id) REFERENCES risque (id)');
        $this->addSql('ALTER TABLE notification_sinistre ADD CONSTRAINT FK_A0BC42C2EA417747 FOREIGN KEY (invite_id) REFERENCES invite (id)');
        $this->addSql('ALTER TABLE piece_sinistre ADD CONSTRAINT FK_C1DE1588C54C8C93 FOREIGN KEY (type_id) REFERENCES modele_piece_sinistre (id)');
        $this->addSql('ALTER TABLE piece_sinistre ADD CONSTRAINT FK_C1DE1588EA417747 FOREIGN KEY (invite_id) REFERENCES invite (id)');
        $this->addSql('ALTER TABLE piece_sinistre ADD CONSTRAINT FK_C1DE1588F4F2559E FOREIGN KEY (notification_sinistre_id) REFERENCES notification_sinistre (id)');
        $this->addSql('ALTER TABLE contact ADD notification_sinistre_id INT DEFAULT NULL, ADD type INT NOT NULL');
        $this->addSql('ALTER TABLE contact ADD CONSTRAINT FK_4C62E638F4F2559E FOREIGN KEY (notification_sinistre_id) REFERENCES notification_sinistre (id)');
        $this->addSql('CREATE INDEX IDX_4C62E638F4F2559E ON contact (notification_sinistre_id)');
        $this->addSql('ALTER TABLE document ADD piece_sinistre_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE document ADD CONSTRAINT FK_D8698A76DFB9960E FOREIGN KEY (piece_sinistre_id) REFERENCES piece_sinistre (id)');
        $this->addSql('CREATE INDEX IDX_D8698A76DFB9960E ON document (piece_sinistre_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE contact DROP FOREIGN KEY FK_4C62E638F4F2559E');
        $this->addSql('ALTER TABLE document DROP FOREIGN KEY FK_D8698A76DFB9960E');
        $this->addSql('ALTER TABLE modele_piece_sinistre DROP FOREIGN KEY FK_3723B98AA4AEAFEA');
        $this->addSql('ALTER TABLE notification_sinistre DROP FOREIGN KEY FK_A0BC42C21F4BE942');
        $this->addSql('ALTER TABLE notification_sinistre DROP FOREIGN KEY FK_A0BC42C24ECC2413');
        $this->addSql('ALTER TABLE notification_sinistre DROP FOREIGN KEY FK_A0BC42C2EA417747');
        $this->addSql('ALTER TABLE piece_sinistre DROP FOREIGN KEY FK_C1DE1588C54C8C93');
        $this->addSql('ALTER TABLE piece_sinistre DROP FOREIGN KEY FK_C1DE1588EA417747');
        $this->addSql('ALTER TABLE piece_sinistre DROP FOREIGN KEY FK_C1DE1588F4F2559E');
        $this->addSql('DROP TABLE modele_piece_sinistre');
        $this->addSql('DROP TABLE notification_sinistre');
        $this->addSql('DROP TABLE piece_sinistre');
        $this->addSql('DROP INDEX IDX_4C62E638F4F2559E ON contact');
        $this->addSql('ALTER TABLE contact DROP notification_sinistre_id, DROP type');
        $this->addSql('DROP INDEX IDX_D8698A76DFB9960E ON document');
        $this->addSql('ALTER TABLE document DROP piece_sinistre_id');
    }
}
