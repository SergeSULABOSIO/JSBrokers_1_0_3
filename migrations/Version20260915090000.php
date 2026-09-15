<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Supervision des erreurs : la table qui compte.
 *
 * ── POURQUOI UNE TABLE, ALORS QU'IL Y A DÉJÀ UN JOURNAL ─────────────────────
 * var/log/prod.log enregistre des OCCURRENCES : une ligne par fois. Il répond à
 * « que s'est-il passé à 14 h 03 ». Il ne répond pas à « qu'est-ce qui casse le
 * plus », qui est la seule question utile quand on décide par quoi commencer.
 *
 * Cette table enregistre des DÉFAUTS : une ligne par problème distinct, avec un
 * compteur. Mille occurrences d'un même bug y font une ligne et le nombre mille
 * — ce qui se priorise, au lieu de mille lignes qui se subissent.
 *
 * ── LA COLONNE « signature », ET POURQUOI ELLE EST UNIQUE ───────────────────
 * Elle porte sha256(côté + type + fichier + ligne). Le MESSAGE en est absent,
 * délibérément : « Client 42 introuvable » et « Client 77 introuvable » sont le
 * même défaut, à la même ligne du même fichier. Les compter séparément viderait
 * le compteur de son sens. L'unicité est ce qui fait le regroupement — c'est la
 * base, et non le code applicatif, qui garantit qu'un défaut n'existe qu'en un
 * exemplaire, même sous deux requêtes simultanées.
 *
 * ── PAS DE entreprise_id, ET C'EST VOULU ────────────────────────────────────
 * Table GLOBALE à la plateforme, comme toutes celles de la Console (coupon,
 * charge, taxe_vente). Un défaut logiciel n'appartient à aucun cabinet : il
 * appartient à l'équipe qui doit le corriger.
 *
 * Le cabinet et l'utilisateur de la dernière occurrence sont stockés en TEXTE,
 * sans clé étrangère. Une erreur doit survivre à la suppression du cabinet qui
 * l'a provoquée : une contrainte effacerait la trace au moment précis où l'on
 * cherche à comprendre ce qui s'est passé.
 *
 * Seule « assigne_a_id » est une vraie relation, en ON DELETE SET NULL : le
 * départ d'un collaborateur ne doit pas supprimer une erreur, seulement la
 * rendre à nouveau libre.
 */
final class Version20260915090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Supervision des erreurs : table erreur_applicative (globale, regroupée par signature).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE erreur_applicative (
                id INT AUTO_INCREMENT NOT NULL,
                assigne_a_id INT DEFAULT NULL,
                signature VARCHAR(64) NOT NULL,
                cote VARCHAR(16) NOT NULL,
                branche VARCHAR(16) NOT NULL,
                type VARCHAR(180) NOT NULL,
                message VARCHAR(500) NOT NULL,
                fichier VARCHAR(500) DEFAULT NULL,
                ligne INT DEFAULT NULL,
                trace LONGTEXT DEFAULT NULL,
                url VARCHAR(500) DEFAULT NULL,
                navigateur VARCHAR(300) DEFAULT NULL,
                dernier_utilisateur_email VARCHAR(180) DEFAULT NULL,
                dernier_cabinet VARCHAR(180) DEFAULT NULL,
                nombre_occurrences INT NOT NULL,
                premiere_occurrence_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                derniere_occurrence_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                statut VARCHAR(16) NOT NULL,
                dernier_palier_alerte INT NOT NULL,
                UNIQUE INDEX UNIQ_C458987BAE880141 (signature),
                INDEX IDX_C458987BBB1B0F33 (assigne_a_id),
                INDEX idx_erreur_derniere (derniere_occurrence_at),
                INDEX idx_erreur_statut (statut),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE erreur_applicative
                ADD CONSTRAINT FK_C458987BBB1B0F33
                FOREIGN KEY (assigne_a_id) REFERENCES utilisateur (id)
                ON DELETE SET NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE erreur_applicative');
    }
}
