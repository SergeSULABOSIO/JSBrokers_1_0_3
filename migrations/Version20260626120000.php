<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Module CRM / RevOps (Console interne) — fondation.
 *
 * Migration STRICTEMENT ADDITIVE (aucune table/colonne existante modifiée ni
 * supprimée) afin de garantir l'absence de régression :
 *  - suivi d'activité léger sur `utilisateur` (last_login_at, login_count) ;
 *  - tables `crm_profil`, `crm_interaction`, `crm_tache`.
 */
final class Version20260626120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'CRM : suivi d\'activité (utilisateur.last_login_at/login_count) + tables crm_profil, crm_interaction, crm_tache.';
    }

    public function up(Schema $schema): void
    {
        // --- Suivi d'activité léger sur l'utilisateur ---
        $this->addSql("ALTER TABLE utilisateur ADD last_login_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', ADD login_count INT DEFAULT 0 NOT NULL");

        // --- Profil CRM (1-1 utilisateur) ---
        $this->addSql("CREATE TABLE crm_profil (
            utilisateur_id INT NOT NULL,
            agent_referent_id INT DEFAULT NULL,
            etape_pipeline VARCHAR(30) NOT NULL,
            etape_manuelle_forcee TINYINT(1) DEFAULT 0 NOT NULL,
            score_sante INT DEFAULT 0 NOT NULL,
            score_couleur VARCHAR(10) DEFAULT 'rouge' NOT NULL,
            risque_churn TINYINT(1) DEFAULT 0 NOT NULL,
            tags JSON NOT NULL,
            source VARCHAR(50) DEFAULT NULL,
            notes LONGTEXT DEFAULT NULL,
            dernier_contact_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
            prochaine_action_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
            created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
            INDEX IDX_CRM_PROFIL_AGENT (agent_referent_id),
            PRIMARY KEY(utilisateur_id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");

        // --- Interactions commerciales ---
        $this->addSql("CREATE TABLE crm_interaction (
            id INT AUTO_INCREMENT NOT NULL,
            client_id INT NOT NULL,
            agent_id INT DEFAULT NULL,
            type VARCHAR(20) NOT NULL,
            sujet VARCHAR(200) NOT NULL,
            contenu LONGTEXT DEFAULT NULL,
            direction VARCHAR(3) DEFAULT 'out' NOT NULL,
            occurred_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            INDEX IDX_CRM_INTER_CLIENT (client_id),
            INDEX IDX_CRM_INTER_AGENT (agent_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");

        // --- Tâches internes (commerciales / Customer Success) ---
        $this->addSql("CREATE TABLE crm_tache (
            id INT AUTO_INCREMENT NOT NULL,
            client_id INT DEFAULT NULL,
            assigne_a_id INT DEFAULT NULL,
            titre VARCHAR(200) NOT NULL,
            description LONGTEXT DEFAULT NULL,
            due_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            priorite VARCHAR(10) DEFAULT 'normale' NOT NULL,
            statut VARCHAR(10) DEFAULT 'a_faire' NOT NULL,
            origine VARCHAR(10) DEFAULT 'manuelle' NOT NULL,
            cle_auto VARCHAR(120) DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            closed_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
            UNIQUE INDEX UNIQ_CRM_TACHE_CLEAUTO (cle_auto),
            INDEX IDX_CRM_TACHE_CLIENT (client_id),
            INDEX IDX_CRM_TACHE_ASSIGNE (assigne_a_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");

        // --- Clés étrangères ---
        $this->addSql('ALTER TABLE crm_profil ADD CONSTRAINT FK_CRM_PROFIL_USER FOREIGN KEY (utilisateur_id) REFERENCES utilisateur (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE crm_profil ADD CONSTRAINT FK_CRM_PROFIL_AGENT FOREIGN KEY (agent_referent_id) REFERENCES utilisateur (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE crm_interaction ADD CONSTRAINT FK_CRM_INTER_CLIENT FOREIGN KEY (client_id) REFERENCES utilisateur (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE crm_interaction ADD CONSTRAINT FK_CRM_INTER_AGENT FOREIGN KEY (agent_id) REFERENCES utilisateur (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE crm_tache ADD CONSTRAINT FK_CRM_TACHE_CLIENT FOREIGN KEY (client_id) REFERENCES utilisateur (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE crm_tache ADD CONSTRAINT FK_CRM_TACHE_ASSIGNE FOREIGN KEY (assigne_a_id) REFERENCES utilisateur (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE crm_profil DROP FOREIGN KEY FK_CRM_PROFIL_USER');
        $this->addSql('ALTER TABLE crm_profil DROP FOREIGN KEY FK_CRM_PROFIL_AGENT');
        $this->addSql('ALTER TABLE crm_interaction DROP FOREIGN KEY FK_CRM_INTER_CLIENT');
        $this->addSql('ALTER TABLE crm_interaction DROP FOREIGN KEY FK_CRM_INTER_AGENT');
        $this->addSql('ALTER TABLE crm_tache DROP FOREIGN KEY FK_CRM_TACHE_CLIENT');
        $this->addSql('ALTER TABLE crm_tache DROP FOREIGN KEY FK_CRM_TACHE_ASSIGNE');

        $this->addSql('DROP TABLE crm_interaction');
        $this->addSql('DROP TABLE crm_tache');
        $this->addSql('DROP TABLE crm_profil');

        $this->addSql('ALTER TABLE utilisateur DROP last_login_at, DROP login_count');
    }
}
