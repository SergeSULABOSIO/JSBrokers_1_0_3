<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * CRM phases 3-5 : Customer Success, automatisations, support & marketing.
 * Tables additives : crm_health_snapshot, crm_notification, crm_automation_log,
 * crm_ticket, crm_campagne, crm_campagne_cible. Aucune table existante modifiée.
 */
final class Version20260626140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'CRM : tables snapshot santé, notifications, journal automatisations, tickets support, campagnes marketing.';
    }

    public function up(Schema $schema): void
    {
        $charset = 'DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB';

        $this->addSql("CREATE TABLE crm_health_snapshot (
            id INT AUTO_INCREMENT NOT NULL,
            utilisateur_id INT NOT NULL,
            score INT NOT NULL,
            couleur VARCHAR(10) NOT NULL,
            details JSON NOT NULL,
            captured_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            INDEX IDX_CRM_SNAP_USER_DATE (utilisateur_id, captured_at),
            PRIMARY KEY(id)
        ) $charset");

        $this->addSql("CREATE TABLE crm_notification (
            id INT AUTO_INCREMENT NOT NULL,
            agent_id INT DEFAULT NULL,
            titre VARCHAR(200) NOT NULL,
            message LONGTEXT DEFAULT NULL,
            niveau VARCHAR(10) DEFAULT 'info' NOT NULL,
            lien VARCHAR(255) DEFAULT NULL,
            lu TINYINT(1) DEFAULT 0 NOT NULL,
            created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            INDEX IDX_CRM_NOTIF_AGENT (agent_id),
            PRIMARY KEY(id)
        ) $charset");

        $this->addSql("CREATE TABLE crm_automation_log (
            id INT AUTO_INCREMENT NOT NULL,
            regle VARCHAR(60) NOT NULL,
            cle_entite VARCHAR(120) NOT NULL,
            fired_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            UNIQUE INDEX UNIQ_CRM_AUTOLOG (regle, cle_entite),
            PRIMARY KEY(id)
        ) $charset");

        $this->addSql("CREATE TABLE crm_ticket (
            id INT AUTO_INCREMENT NOT NULL,
            client_id INT NOT NULL,
            agent_id INT DEFAULT NULL,
            reference VARCHAR(40) NOT NULL,
            sujet VARCHAR(200) NOT NULL,
            description LONGTEXT DEFAULT NULL,
            canal VARCHAR(20) DEFAULT 'email' NOT NULL,
            priorite VARCHAR(10) DEFAULT 'normale' NOT NULL,
            statut VARCHAR(12) DEFAULT 'ouvert' NOT NULL,
            sla_due_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
            resolu_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
            satisfaction INT DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
            INDEX IDX_CRM_TICKET_CLIENT (client_id),
            INDEX IDX_CRM_TICKET_AGENT (agent_id),
            PRIMARY KEY(id)
        ) $charset");

        $this->addSql("CREATE TABLE crm_campagne (
            id INT AUTO_INCREMENT NOT NULL,
            nom VARCHAR(150) NOT NULL,
            type VARCHAR(20) NOT NULL,
            statut VARCHAR(12) DEFAULT 'brouillon' NOT NULL,
            objet VARCHAR(200) NOT NULL,
            message LONGTEXT NOT NULL,
            segment_regles JSON NOT NULL,
            nb_cibles INT DEFAULT 0 NOT NULL,
            nb_envois INT DEFAULT 0 NOT NULL,
            nb_conversions INT DEFAULT 0 NOT NULL,
            created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            sent_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
            PRIMARY KEY(id)
        ) $charset");

        $this->addSql("CREATE TABLE crm_campagne_cible (
            id INT AUTO_INCREMENT NOT NULL,
            campagne_id INT NOT NULL,
            client_id INT NOT NULL,
            statut_envoi VARCHAR(12) DEFAULT 'en_attente' NOT NULL,
            sent_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
            converti TINYINT(1) DEFAULT 0 NOT NULL,
            INDEX IDX_CRM_CIBLE_CAMP (campagne_id),
            INDEX IDX_CRM_CIBLE_CLIENT (client_id),
            PRIMARY KEY(id)
        ) $charset");

        $this->addSql('ALTER TABLE crm_health_snapshot ADD CONSTRAINT FK_CRM_SNAP_USER FOREIGN KEY (utilisateur_id) REFERENCES utilisateur (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE crm_notification ADD CONSTRAINT FK_CRM_NOTIF_AGENT FOREIGN KEY (agent_id) REFERENCES utilisateur (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE crm_ticket ADD CONSTRAINT FK_CRM_TICKET_CLIENT FOREIGN KEY (client_id) REFERENCES utilisateur (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE crm_ticket ADD CONSTRAINT FK_CRM_TICKET_AGENT FOREIGN KEY (agent_id) REFERENCES utilisateur (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE crm_campagne_cible ADD CONSTRAINT FK_CRM_CIBLE_CAMP FOREIGN KEY (campagne_id) REFERENCES crm_campagne (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE crm_campagne_cible ADD CONSTRAINT FK_CRM_CIBLE_CLIENT FOREIGN KEY (client_id) REFERENCES utilisateur (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE crm_campagne_cible DROP FOREIGN KEY FK_CRM_CIBLE_CAMP');
        $this->addSql('ALTER TABLE crm_campagne_cible DROP FOREIGN KEY FK_CRM_CIBLE_CLIENT');
        $this->addSql('ALTER TABLE crm_ticket DROP FOREIGN KEY FK_CRM_TICKET_CLIENT');
        $this->addSql('ALTER TABLE crm_ticket DROP FOREIGN KEY FK_CRM_TICKET_AGENT');
        $this->addSql('ALTER TABLE crm_notification DROP FOREIGN KEY FK_CRM_NOTIF_AGENT');
        $this->addSql('ALTER TABLE crm_health_snapshot DROP FOREIGN KEY FK_CRM_SNAP_USER');

        $this->addSql('DROP TABLE crm_campagne_cible');
        $this->addSql('DROP TABLE crm_campagne');
        $this->addSql('DROP TABLE crm_ticket');
        $this->addSql('DROP TABLE crm_automation_log');
        $this->addSql('DROP TABLE crm_notification');
        $this->addSql('DROP TABLE crm_health_snapshot');
    }
}
