<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Console — Départements & rôles des collaborateurs + évaluations.
 *
 * Ajoute l'organisation des collaborateurs : département + fonction sur
 * utilisateur (couche d'accès), et les tables objectif / evaluation (suivi RH).
 * DML de sécurité : tous les agents existants (ROLE_ADMIN / ROLE_SUPER_ADMIN) sont
 * affectés à « Direction Générale / Responsable » pour éviter tout verrouillage au
 * déploiement — le super-admin les réaffecte ensuite. Modifications additives.
 */
final class Version20260629120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Console : départements & fonctions des collaborateurs + objectifs / évaluations.';
    }

    public function up(Schema $schema): void
    {
        // Couche organisationnelle / d'accès sur le collaborateur.
        $this->addSql('ALTER TABLE utilisateur ADD departement VARCHAR(30) DEFAULT NULL, ADD fonction VARCHAR(30) DEFAULT NULL');

        // Anti-verrouillage : les agents existants gardent un accès complet (Direction).
        $this->addSql("UPDATE utilisateur SET departement = 'direction', fonction = 'responsable'
            WHERE departement IS NULL AND (roles LIKE '%ROLE_ADMIN%' OR roles LIKE '%ROLE_SUPER_ADMIN%')");

        // Objectifs (SMART) par collaborateur et par période.
        $this->addSql('CREATE TABLE objectif (
            id INT AUTO_INCREMENT NOT NULL,
            collaborateur_id INT NOT NULL,
            annee INT NOT NULL,
            trimestre INT DEFAULT 0 NOT NULL,
            titre VARCHAR(180) NOT NULL,
            description LONGTEXT DEFAULT NULL,
            cible DOUBLE PRECISION NOT NULL,
            unite VARCHAR(40) DEFAULT \'\' NOT NULL,
            poids INT DEFAULT 25 NOT NULL,
            mode VARCHAR(20) DEFAULT \'manuel\' NOT NULL,
            metrique VARCHAR(60) DEFAULT NULL,
            valeur_manuelle DOUBLE PRECISION DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX IDX_OBJECTIF_COLLAB (collaborateur_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE objectif ADD CONSTRAINT FK_OBJECTIF_COLLAB FOREIGN KEY (collaborateur_id) REFERENCES utilisateur (id) ON DELETE CASCADE');

        // Fiches d'évaluation (qualitatif + clôture), une par collaborateur et période.
        $this->addSql('CREATE TABLE evaluation (
            id INT AUTO_INCREMENT NOT NULL,
            collaborateur_id INT NOT NULL,
            annee INT NOT NULL,
            trimestre INT DEFAULT 0 NOT NULL,
            appreciation LONGTEXT DEFAULT NULL,
            cloturee TINYINT(1) DEFAULT 0 NOT NULL,
            score_fige DOUBLE PRECISION DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX IDX_EVALUATION_COLLAB (collaborateur_id),
            UNIQUE INDEX UNIQ_EVAL_PERIODE (collaborateur_id, annee, trimestre),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE evaluation ADD CONSTRAINT FK_EVALUATION_COLLAB FOREIGN KEY (collaborateur_id) REFERENCES utilisateur (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE evaluation');
        $this->addSql('DROP TABLE objectif');
        $this->addSql('ALTER TABLE utilisateur DROP departement, DROP fonction');
    }
}
