<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * LE MODULE DE GESTION DES CONGÉS — SOCLE.
 *
 * Un agent pose une demande, un valideur décide, et le compteur de chacun se tient tout
 * seul. Six tables, deux droits, une relation de pièce jointe.
 *
 * ── LE JOURNAL EST LE COMPTEUR ──────────────────────────────────────────────────────
 * Il n'y a volontairement AUCUNE colonne « solde » nulle part. Le solde d'un agent est
 * la somme des lignes de `mouvement_conge`, et rien d'autre. Un forfait recopié dans une
 * table de compteurs aurait fini par diverger de son journal — et c'est toujours le
 * chiffre faux que l'on découvre le jour où quelqu'un réclame ses jours.
 *
 * De la même façon, `demande_conge` n'a pas d'état « consommée » : une demande approuvée
 * dont la date de fin est passée reste APPROUVEE, et l'échéance se lit sur la date. Cela
 * évite une tâche de bascule nocturne, donc une tâche de plus qui peut ne pas tourner.
 *
 * ── LES CLÉS ÉTRANGÈRES DISENT CE QUI SURVIT À QUOI ─────────────────────────────────
 *  - `regime_travail.agent_id` → CASCADE : un régime de travail n'a aucun sens sans son
 *    collaborateur.
 *  - `historique_demande.demande_id` → CASCADE : la trace suit son dossier.
 *  - `mouvement_conge.demande_id` → SET NULL : le mouvement, lui, SURVIT à la demande.
 *    C'est tout l'intérêt d'un journal immuable — le compteur ne doit pas se réécrire
 *    parce qu'on a supprimé une ligne d'écran. Le mouvement reste alors rattaché à son
 *    agent et à son exercice, sans son dossier d'origine.
 *
 * ── ⚠ LA REPRISE EST LE CŒUR DE CETTE MIGRATION, PAS LES TABLES ─────────────────────
 * La politique d'accès de l'application est fail-closed : une colonne de droits vide
 * signifie « aucun accès ». Déployer les tables sans plus livrerait donc une rubrique
 * INVISIBLE À TOUT LE MONDE, y compris au propriétaire, et personne ne saurait pourquoi.
 *
 * On accorde donc, à tous les rôles existants, la Lecture et l'Écriture sur les congés :
 * tout salarié doit pouvoir demander un congé dès le premier jour — c'est le sens même
 * de la rubrique, et c'est aussi la seule attribution d'office de l'application. Le
 * PARAMÉTRAGE (types d'absence, jours fériés), lui, reste fermé : il engage le cabinet
 * bien au-delà d'un dossier, et le propriétaire y accède de toute façon sans droit
 * explicite.
 *
 * ── ÉCRITE À LA MAIN ────────────────────────────────────────────────────────────────
 * Un `doctrine:migrations:diff` proposait 131 instructions, dont une centaine sans aucun
 * rapport avec ce lot : renommages d'index hérités du module CRM, de l'assistant et des
 * avenants, dérive préexistante du schéma de développement. Cette migration ne contient
 * QUE les 30 instructions du module de congés.
 */
final class Version20260831120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Module de gestion des congés : six tables, deux droits dans RolesEnAdministration avec reprise (Lecture + Écriture accordées à tous), pièces jointes sur les demandes.';
    }

    public function up(Schema $schema): void
    {
        // ── RÉFÉRENTIELS ────────────────────────────────────────────────────────────
        $this->addSql(<<<'SQL'
            CREATE TABLE type_absence (
                id INT AUTO_INCREMENT NOT NULL,
                entreprise_id INT NOT NULL,
                invite_id INT DEFAULT NULL,
                code VARCHAR(20) NOT NULL,
                libelle VARCHAR(100) NOT NULL,
                decompte TINYINT(1) NOT NULL,
                justificatif_requis TINYINT(1) NOT NULL,
                plafond_par_demande NUMERIC(5, 1) DEFAULT NULL,
                autorise_demi_journee TINYINT(1) NOT NULL,
                couleur VARCHAR(20) DEFAULT NULL,
                actif TINYINT(1) NOT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX IDX_5E7B8F3CA4AEAFEA (entreprise_id),
                INDEX IDX_5E7B8F3CEA417747 (invite_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE jour_ferie (
                id INT AUTO_INCREMENT NOT NULL,
                entreprise_id INT NOT NULL,
                invite_id INT DEFAULT NULL,
                date DATE NOT NULL COMMENT '(DC2Type:date_immutable)',
                libelle VARCHAR(150) NOT NULL,
                exercice INT NOT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX IDX_122AB5A4AEAFEA (entreprise_id),
                INDEX IDX_122AB5EA417747 (invite_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE regime_travail (
                id INT AUTO_INCREMENT NOT NULL,
                agent_id INT DEFAULT NULL,
                entreprise_id INT NOT NULL,
                invite_id INT DEFAULT NULL,
                jours_ouvres JSON NOT NULL COMMENT '(DC2Type:json)',
                taux_occupation NUMERIC(4, 2) NOT NULL,
                date_debut DATE NOT NULL COMMENT '(DC2Type:date_immutable)',
                date_fin DATE DEFAULT NULL COMMENT '(DC2Type:date_immutable)',
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX IDX_D3B45DC3414710B (agent_id),
                INDEX IDX_D3B45DCA4AEAFEA (entreprise_id),
                INDEX IDX_D3B45DCEA417747 (invite_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        // ── LE DOSSIER ET SES CONSÉQUENCES ──────────────────────────────────────────
        $this->addSql(<<<'SQL'
            CREATE TABLE demande_conge (
                id INT AUTO_INCREMENT NOT NULL,
                agent_id INT DEFAULT NULL,
                type_absence_id INT DEFAULT NULL,
                valideur_id INT DEFAULT NULL,
                entreprise_id INT NOT NULL,
                invite_id INT DEFAULT NULL,
                date_debut DATE NOT NULL COMMENT '(DC2Type:date_immutable)',
                date_fin DATE NOT NULL COMMENT '(DC2Type:date_immutable)',
                demi_journee_debut TINYINT(1) NOT NULL,
                demi_journee_fin TINYINT(1) NOT NULL,
                nb_jours NUMERIC(5, 1) DEFAULT NULL,
                motif LONGTEXT DEFAULT NULL,
                statut VARCHAR(20) NOT NULL,
                date_decision DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                commentaire_decision LONGTEXT DEFAULT NULL,
                origine VARCHAR(10) NOT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX IDX_D80610613414710B (agent_id),
                INDEX IDX_D806106130FCF5AA (type_absence_id),
                INDEX IDX_D8061061780262A7 (valideur_id),
                INDEX IDX_D8061061A4AEAFEA (entreprise_id),
                INDEX IDX_D8061061EA417747 (invite_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE mouvement_conge (
                id INT AUTO_INCREMENT NOT NULL,
                agent_id INT DEFAULT NULL,
                type_absence_id INT DEFAULT NULL,
                demande_id INT DEFAULT NULL,
                auteur_id INT DEFAULT NULL,
                entreprise_id INT NOT NULL,
                invite_id INT DEFAULT NULL,
                exercice INT NOT NULL,
                nature VARCHAR(30) NOT NULL,
                quantite NUMERIC(6, 1) NOT NULL,
                origine VARCHAR(10) NOT NULL,
                commentaire LONGTEXT DEFAULT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX IDX_40C0DC33414710B (agent_id),
                INDEX IDX_40C0DC330FCF5AA (type_absence_id),
                INDEX IDX_40C0DC380E95E18 (demande_id),
                INDEX IDX_40C0DC360BB6FE6 (auteur_id),
                INDEX IDX_40C0DC3A4AEAFEA (entreprise_id),
                INDEX IDX_40C0DC3EA417747 (invite_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE historique_demande (
                id INT AUTO_INCREMENT NOT NULL,
                demande_id INT DEFAULT NULL,
                auteur_id INT DEFAULT NULL,
                entreprise_id INT NOT NULL,
                invite_id INT DEFAULT NULL,
                statut_avant VARCHAR(20) DEFAULT NULL,
                statut_apres VARCHAR(20) NOT NULL,
                origine VARCHAR(10) NOT NULL,
                commentaire LONGTEXT DEFAULT NULL,
                auto_approuvee TINYINT(1) NOT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX IDX_448088DA80E95E18 (demande_id),
                INDEX IDX_448088DA60BB6FE6 (auteur_id),
                INDEX IDX_448088DAA4AEAFEA (entreprise_id),
                INDEX IDX_448088DAEA417747 (invite_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        // ── CLÉS ÉTRANGÈRES ─────────────────────────────────────────────────────────
        $this->addSql('ALTER TABLE type_absence ADD CONSTRAINT FK_5E7B8F3CA4AEAFEA FOREIGN KEY (entreprise_id) REFERENCES entreprise (id)');
        $this->addSql('ALTER TABLE type_absence ADD CONSTRAINT FK_5E7B8F3CEA417747 FOREIGN KEY (invite_id) REFERENCES invite (id)');

        $this->addSql('ALTER TABLE jour_ferie ADD CONSTRAINT FK_122AB5A4AEAFEA FOREIGN KEY (entreprise_id) REFERENCES entreprise (id)');
        $this->addSql('ALTER TABLE jour_ferie ADD CONSTRAINT FK_122AB5EA417747 FOREIGN KEY (invite_id) REFERENCES invite (id)');

        $this->addSql('ALTER TABLE regime_travail ADD CONSTRAINT FK_D3B45DC3414710B FOREIGN KEY (agent_id) REFERENCES invite (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE regime_travail ADD CONSTRAINT FK_D3B45DCA4AEAFEA FOREIGN KEY (entreprise_id) REFERENCES entreprise (id)');
        $this->addSql('ALTER TABLE regime_travail ADD CONSTRAINT FK_D3B45DCEA417747 FOREIGN KEY (invite_id) REFERENCES invite (id)');

        $this->addSql('ALTER TABLE demande_conge ADD CONSTRAINT FK_D80610613414710B FOREIGN KEY (agent_id) REFERENCES invite (id)');
        $this->addSql('ALTER TABLE demande_conge ADD CONSTRAINT FK_D806106130FCF5AA FOREIGN KEY (type_absence_id) REFERENCES type_absence (id)');
        $this->addSql('ALTER TABLE demande_conge ADD CONSTRAINT FK_D8061061780262A7 FOREIGN KEY (valideur_id) REFERENCES invite (id)');
        $this->addSql('ALTER TABLE demande_conge ADD CONSTRAINT FK_D8061061A4AEAFEA FOREIGN KEY (entreprise_id) REFERENCES entreprise (id)');
        $this->addSql('ALTER TABLE demande_conge ADD CONSTRAINT FK_D8061061EA417747 FOREIGN KEY (invite_id) REFERENCES invite (id)');

        $this->addSql('ALTER TABLE mouvement_conge ADD CONSTRAINT FK_40C0DC33414710B FOREIGN KEY (agent_id) REFERENCES invite (id)');
        $this->addSql('ALTER TABLE mouvement_conge ADD CONSTRAINT FK_40C0DC330FCF5AA FOREIGN KEY (type_absence_id) REFERENCES type_absence (id)');
        // SET NULL, et non CASCADE : le mouvement SURVIT à la suppression de son dossier.
        $this->addSql('ALTER TABLE mouvement_conge ADD CONSTRAINT FK_40C0DC380E95E18 FOREIGN KEY (demande_id) REFERENCES demande_conge (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE mouvement_conge ADD CONSTRAINT FK_40C0DC360BB6FE6 FOREIGN KEY (auteur_id) REFERENCES invite (id)');
        $this->addSql('ALTER TABLE mouvement_conge ADD CONSTRAINT FK_40C0DC3A4AEAFEA FOREIGN KEY (entreprise_id) REFERENCES entreprise (id)');
        $this->addSql('ALTER TABLE mouvement_conge ADD CONSTRAINT FK_40C0DC3EA417747 FOREIGN KEY (invite_id) REFERENCES invite (id)');

        $this->addSql('ALTER TABLE historique_demande ADD CONSTRAINT FK_448088DA80E95E18 FOREIGN KEY (demande_id) REFERENCES demande_conge (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE historique_demande ADD CONSTRAINT FK_448088DA60BB6FE6 FOREIGN KEY (auteur_id) REFERENCES invite (id)');
        $this->addSql('ALTER TABLE historique_demande ADD CONSTRAINT FK_448088DAA4AEAFEA FOREIGN KEY (entreprise_id) REFERENCES entreprise (id)');
        $this->addSql('ALTER TABLE historique_demande ADD CONSTRAINT FK_448088DAEA417747 FOREIGN KEY (invite_id) REFERENCES invite (id)');

        // ── PIÈCES JOINTES ──────────────────────────────────────────────────────────
        // La relation suffit : DocumentFichier::parentsPossibles() la découvre par les
        // métadonnées Doctrine, et les actions « Attacher des pièces » / « Voir les
        // documents » apparaissent d'elles-mêmes sur la rubrique.
        $this->addSql('ALTER TABLE document ADD demande_conge_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE document ADD CONSTRAINT FK_D8698A768CF5D39B FOREIGN KEY (demande_conge_id) REFERENCES demande_conge (id)');
        $this->addSql('CREATE INDEX IDX_D8698A768CF5D39B ON document (demande_conge_id)');

        // ── LES DEUX DROITS, ET LEUR REPRISE ────────────────────────────────────────
        $this->addSql("ALTER TABLE roles_en_administration ADD access_conge LONGTEXT NOT NULL COMMENT '(DC2Type:array)', ADD access_conge_parametre LONGTEXT NOT NULL COMMENT '(DC2Type:array)'");

        // ⚠ MySQL remplit les lignes existantes avec la CHAÎNE VIDE, que le type Doctrine
        // ARRAY ne sait pas désérialiser : toute lecture de rôle lèverait. On pose donc
        // d'abord le tableau sérialisé vide PARTOUT.
        $this->addSql("UPDATE roles_en_administration SET access_conge = 'a:0:{}', access_conge_parametre = 'a:0:{}'");

        // REPRISE : Lecture (0) et Écriture (1) sur les congés pour tous les rôles
        // existants — soit exactement `serialize([0, 1])`. Tout salarié doit pouvoir
        // poser un congé sans attendre que quelqu'un coche une case pour lui.
        //
        // Le paramétrage, lui, reste à `a:0:{}` : le propriétaire y accède de toute façon
        // par son bypass, et il choisira à qui d'autre l'ouvrir.
        $this->addSql("UPDATE roles_en_administration SET access_conge = 'a:2:{i:0;i:0;i:1;i:1;}'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE document DROP FOREIGN KEY FK_D8698A768CF5D39B');
        $this->addSql('DROP INDEX IDX_D8698A768CF5D39B ON document');
        $this->addSql('ALTER TABLE document DROP demande_conge_id');

        $this->addSql('ALTER TABLE roles_en_administration DROP access_conge, DROP access_conge_parametre');

        // Ordre imposé par les clés étrangères : les conséquences avant le dossier, le
        // dossier avant ses référentiels.
        $this->addSql('DROP TABLE historique_demande');
        $this->addSql('DROP TABLE mouvement_conge');
        $this->addSql('DROP TABLE demande_conge');
        $this->addSql('DROP TABLE regime_travail');
        $this->addSql('DROP TABLE jour_ferie');
        $this->addSql('DROP TABLE type_absence');
    }
}
