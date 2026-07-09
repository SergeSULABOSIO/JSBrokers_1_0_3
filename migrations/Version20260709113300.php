<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Historique des envois du SOA par e-mail (soa_envoi).
 * NB : le diff auto incluait la même dérive de schéma sans rapport que la
 * migration précédente (renommages d'index CRM & co), volontairement écartée.
 */
final class Version20260709113300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Crée la table soa_envoi (historique des envois du relevé de compte par e-mail)";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE soa_envoi (id INT AUTO_INCREMENT NOT NULL, client_id INT NOT NULL, entreprise_id INT NOT NULL, invite_id INT DEFAULT NULL, email_destinataire VARCHAR(255) NOT NULL, nom_destinataire VARCHAR(255) NOT NULL, message LONGTEXT DEFAULT NULL, lien_expire_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_FAA966A119EB6921 (client_id), INDEX IDX_FAA966A1A4AEAFEA (entreprise_id), INDEX IDX_FAA966A1EA417747 (invite_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE soa_envoi ADD CONSTRAINT FK_FAA966A119EB6921 FOREIGN KEY (client_id) REFERENCES client (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE soa_envoi ADD CONSTRAINT FK_FAA966A1A4AEAFEA FOREIGN KEY (entreprise_id) REFERENCES entreprise (id)');
        $this->addSql('ALTER TABLE soa_envoi ADD CONSTRAINT FK_FAA966A1EA417747 FOREIGN KEY (invite_id) REFERENCES invite (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE soa_envoi DROP FOREIGN KEY FK_FAA966A119EB6921');
        $this->addSql('ALTER TABLE soa_envoi DROP FOREIGN KEY FK_FAA966A1A4AEAFEA');
        $this->addSql('ALTER TABLE soa_envoi DROP FOREIGN KEY FK_FAA966A1EA417747');
        $this->addSql('DROP TABLE soa_envoi');
    }
}
