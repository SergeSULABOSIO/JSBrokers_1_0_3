<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * PERSISTANCE DE LA RUBRIQUE « IMPORTATION / EXPORTATION ».
 *
 * Trois choses, qui n'en font qu'une : de quoi compter les opérations, de quoi tenir un
 * contrôle d'import entre son analyse et la décision de l'utilisateur, et de quoi régler
 * le barème depuis la console.
 *
 * ⚠ LE BARÈME EST AU NIVEAU PLATEFORME, PAS PAR CABINET.
 *
 * La spécification prévoyait une table `exchange_config` par cabinet. Ce serait une
 * seconde grille tarifaire à côté de celle qui existe déjà (`plateforme_parametres`,
 * éditée dans /console/plan-tarifaire) : deux endroits où lire un prix, donc un jour
 * deux prix différents. Les deux colonnes ajoutées ici rejoignent les onze qui règlent
 * déjà les tokens, les documents IA et les paquets prépayés. Elles sont NULLABLES et
 * portent chacune son repli dans TokenPricing, comme leurs voisines.
 *
 * Conséquence heureuse : il n'y a rien à semer à la création d'un cabinet. Le décompte,
 * lui, est bien par cabinet — il vit dans `echange_occurrence.entreprise_id`, et un
 * cabinet neuf n'a simplement aucune ligne.
 */
final class Version20260903130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Échange de données : occurrences, contrôles d\'import, et barème dans la grille tarifaire.';
    }

    public function up(Schema $schema): void
    {
        // ── OCCURRENCES ─────────────────────────────────────────────────────────────
        // Une ligne par opération ABOUTIE. Sert à deux choses à la fois : compter le
        // quota gratuit (qui ne se déduit d'aucun journal de tokens, puisque les
        // opérations gratuites n'en consomment aucun) et tracer les sorties de données
        // personnelles.
        //
        // `cle_idempotence` est UNIQUE, et c'est la base qui le garantit : seule une
        // contrainte résiste à deux requêtes concurrentes. Une vérification applicative
        // laisserait passer le double débit exactement dans le cas qu'elle prétend couvrir.
        $this->addSql(<<<'SQL'
            CREATE TABLE echange_occurrence (
                id INT AUTO_INCREMENT NOT NULL,
                entreprise_id INT NOT NULL,
                invite_id INT DEFAULT NULL,
                type VARCHAR(16) NOT NULL,
                perimetre JSON NOT NULL COMMENT '(DC2Type:json)',
                nb_lignes INT NOT NULL,
                tokens_debites INT NOT NULL,
                cle_idempotence VARCHAR(64) NOT NULL,
                empreinte_fichier VARCHAR(64) DEFAULT NULL,
                nom_fichier VARCHAR(255) DEFAULT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX UNIQ_C7C43B63F525847C (cle_idempotence),
                INDEX idx_echange_occurrence_entreprise (entreprise_id),
                INDEX IDX_C7C43B63EA417747 (invite_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        // ── CONTRÔLES D'IMPORT ──────────────────────────────────────────────────────
        // Le travail de contrôle doit survivre entre l'analyse et la décision. On y
        // garde le RAPPORT, jamais les données déposées : le fichier reste sur disque,
        // hors `public/`, et n'est relu qu'à la confirmation.
        $this->addSql(<<<'SQL'
            CREATE TABLE echange_import_run (
                id INT AUTO_INCREMENT NOT NULL,
                entreprise_id INT NOT NULL,
                invite_id INT DEFAULT NULL,
                nom_fichier VARCHAR(255) NOT NULL,
                chemin_fichier VARCHAR(500) DEFAULT NULL,
                empreinte_fichier VARCHAR(64) DEFAULT NULL,
                statut VARCHAR(32) NOT NULL,
                rapport JSON NOT NULL COMMENT '(DC2Type:json)',
                suppressions_autorisees TINYINT(1) NOT NULL,
                expire_le DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX idx_echange_import_run_entreprise (entreprise_id),
                INDEX IDX_BD22BED0EA417747 (invite_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql('ALTER TABLE echange_occurrence ADD CONSTRAINT FK_C7C43B63A4AEAFEA FOREIGN KEY (entreprise_id) REFERENCES entreprise (id)');
        $this->addSql('ALTER TABLE echange_occurrence ADD CONSTRAINT FK_C7C43B63EA417747 FOREIGN KEY (invite_id) REFERENCES invite (id)');
        $this->addSql('ALTER TABLE echange_import_run ADD CONSTRAINT FK_BD22BED0A4AEAFEA FOREIGN KEY (entreprise_id) REFERENCES entreprise (id)');
        $this->addSql('ALTER TABLE echange_import_run ADD CONSTRAINT FK_BD22BED0EA417747 FOREIGN KEY (invite_id) REFERENCES invite (id)');

        // ── BARÈME ──────────────────────────────────────────────────────────────────
        // NULLABLES : NULL signifie « prends le défaut du code ». Aucune reprise à faire,
        // et la plateforme démarre sur TokenPricing sans qu'on ait rien à écrire.
        $this->addSql('ALTER TABLE plateforme_parametres ADD echange_quota_gratuit INT DEFAULT NULL, ADD echange_cout_occurrence INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE echange_occurrence DROP FOREIGN KEY FK_C7C43B63A4AEAFEA');
        $this->addSql('ALTER TABLE echange_occurrence DROP FOREIGN KEY FK_C7C43B63EA417747');
        $this->addSql('ALTER TABLE echange_import_run DROP FOREIGN KEY FK_BD22BED0A4AEAFEA');
        $this->addSql('ALTER TABLE echange_import_run DROP FOREIGN KEY FK_BD22BED0EA417747');
        $this->addSql('DROP TABLE echange_occurrence');
        $this->addSql('DROP TABLE echange_import_run');
        $this->addSql('ALTER TABLE plateforme_parametres DROP echange_quota_gratuit, DROP echange_cout_occurrence');
    }
}
