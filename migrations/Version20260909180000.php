<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * UN IMPORT PEUT DÉSORMAIS S'INTERROMPRE ET REPRENDRE.
 *
 * ── LE PROBLÈME QUE CES TROIS COLONNES RÉSOLVENT ────────────────────────────────────
 * Le contrôle à blanc retient plusieurs mégaoctets par ligne, sans les rendre : il monte
 * un arbre de formulaires complet par opération, et cette empreinte ne redescend pas. Un
 * import devait pourtant tenir dans une seule requête — d'où un plafond calculé sur la
 * mémoire du serveur, qui tombait à une trentaine de lignes. La rubrique refusait ainsi
 * le seul cas pour lequel elle existe : la reprise d'un portefeuille réel.
 *
 * Le travail avance maintenant par PALIERS, chacun dans un processus neuf. Ce qui suppose
 * de savoir, entre deux paliers, OÙ l'on en était — et c'est tout l'objet de `curseur` et
 * de `total_lignes`.
 *
 * ── ET `travail_depuis` EST UN VERROU AUTANT QU'UN SIGNE DE VIE ─────────────────────
 * Verrou : deux paliers simultanés traiteraient la même fenêtre de lignes dans deux
 * transactions séparées — chacune créerait « son » client, et l'idempotence, qui ne voit
 * que ce qui est COMMITÉ, n'y pourrait rien. La prise se fait par UPDATE conditionnel,
 * comme pour les conversations de l'assistant : de deux exécutions concurrentes, une
 * seule peut voir la condition satisfaite.
 *
 * Signe de vie : un import dont le processus meurt — onglet fermé, worker arrêté, mémoire
 * épuisée — restait « en cours » pour toujours : plus confirmable, plus annulable, et
 * invisible du dépôt suivant. L'utilisateur venait de cliquer « Confirmer » et n'avait
 * aucun moyen de savoir si son portefeuille avait été repris. Un palier mort laisse sa
 * date derrière lui, et c'est ainsi qu'on le reconnaît.
 *
 * ⚠ LES CONTRÔLES DÉJÀ EN BASE N'ONT RIEN À RATTRAPER. Un curseur et un total à zéro
 * décrivent exactement un travail qui n'a pas commencé, et une colonne vide se lit
 * comme « aucun palier en cours » — ce qui est vrai. La reprise se fait d'elle-même.
 */
final class Version20260909180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Échange : le contrôle d\'import porte son curseur, son volume et son verrou de travail.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE echange_import_run ADD curseur INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE echange_import_run ADD total_lignes INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE echange_import_run ADD travail_depuis DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE echange_import_run DROP curseur');
        $this->addSql('ALTER TABLE echange_import_run DROP total_lignes');
        $this->addSql('ALTER TABLE echange_import_run DROP travail_depuis');
    }
}
