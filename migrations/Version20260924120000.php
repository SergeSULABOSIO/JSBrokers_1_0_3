<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * LES RÉGLAGES DE KET passent en base : outils coupés, seuils métier, et le journal
 * de qui a changé quoi.
 *
 * ── DEUX OBJETS, DEUX RÔLES ───────────────────────────────────────────────────
 * `plateforme_parametres.ket_reglages` porte l'ÉTAT — ce qui est coupé, quels seuils
 * ont bougé. `ket_reglage_journal` porte l'HISTOIRE — qui, quand, pourquoi. Le
 * premier suffit à faire tourner l'application ; le second sert à comprendre et à
 * défaire, et c'est pour cela que son motif est NOT NULL : un journal sans motif dit
 * ce qui a changé, jamais si c'était délibéré.
 *
 * ── NULL = RIEN DE PERSONNALISÉ ───────────────────────────────────────────────
 * Comme `ket_fournisseurs`, `packs` ou `write_weights` sur cette même table. On n'y
 * écrit que les ÉCARTS : un outil absent de la carte est actif, un paramètre absent
 * vaut sa constante. Aucun déploiement ne change de comportement du seul fait de
 * cette migration — et « rétablir les réglages par défaut » se réduit à remettre
 * NULL, sans liste à reconstruire.
 *
 * ── LE JOURNAL N'A PAS D'ENTREPRISE ───────────────────────────────────────────
 * C'est un journal de PLATEFORME : ses changements valent pour tous les cabinets à
 * la fois. Lui coller la colonne `entreprise_id` du trait d'audit du projet aurait
 * menti sur la portée. L'auteur, lui, est en ON DELETE SET NULL et son nom recopié :
 * un collaborateur qui quitte Joseara n'efface pas l'histoire des réglages.
 */
final class Version20260924120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Réglages de Ket : plateforme_parametres.ket_reglages (JSON) + table ket_reglage_journal.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE plateforme_parametres ADD ket_reglages JSON DEFAULT NULL');

        $this->addSql(<<<'SQL'
            CREATE TABLE ket_reglage_journal (
                id INT AUTO_INCREMENT NOT NULL,
                auteur_id INT DEFAULT NULL,
                element VARCHAR(120) NOT NULL,
                type VARCHAR(20) NOT NULL,
                ancienne_valeur VARCHAR(255) DEFAULT NULL,
                nouvelle_valeur VARCHAR(255) DEFAULT NULL,
                motif LONGTEXT NOT NULL,
                auteur_nom VARCHAR(180) NOT NULL,
                effectue_le DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX idx_ket_reglage_journal_date (effectue_le),
                INDEX IDX_KET_REGLAGE_JOURNAL_AUTEUR (auteur_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(
            'ALTER TABLE ket_reglage_journal ADD CONSTRAINT FK_KET_REGLAGE_JOURNAL_AUTEUR'
            . ' FOREIGN KEY (auteur_id) REFERENCES utilisateur (id) ON DELETE SET NULL'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ket_reglage_journal DROP FOREIGN KEY FK_KET_REGLAGE_JOURNAL_AUTEUR');
        $this->addSql('DROP TABLE ket_reglage_journal');
        $this->addSql('ALTER TABLE plateforme_parametres DROP ket_reglages');
    }
}
