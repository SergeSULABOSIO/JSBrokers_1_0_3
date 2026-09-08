<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * LE PROPRIÉTAIRE EST TOUJOURS GESTIONNAIRE DES INVITÉS — et la base le dit enfin.
 *
 * ── CE QUI CLOCHAIT ─────────────────────────────────────────────────────────────────
 * `WorkspaceAccessResolver::canManageInvites()` accorde ce pouvoir au propriétaire par
 * son seul statut, sans regarder `invite.gestionnaire_invites`. Le drapeau ne décidait
 * donc rien pour lui — il se contentait de dire le contraire à l'écran : un propriétaire
 * ouvrant sa propre fiche y trouvait une case décochée en face d'un pouvoir qu'il a.
 *
 * Un écran qui contredit la règle est un écran auquel on cesse de se fier. Et le jour où
 * un autre bout de code lira le drapeau plutôt que le résolveur, la contradiction cessera
 * d'être cosmétique.
 *
 * ── CE QUE CETTE MIGRATION FAIT ─────────────────────────────────────────────────────
 * Elle redresse les lignes existantes. Les créations futures sont couvertes par
 * `Invite::onProprietaireToujoursGestionnaire()` (PrePersist), et la modification par le
 * formulaire, où la case est verrouillée sur la fiche d'un propriétaire.
 *
 * ── PAS DE `down()` QUI DÉFAIT ──────────────────────────────────────────────────────
 * Remettre ces drapeaux à NULL restaurerait une incohérence, pas un état antérieur utile.
 * On ne sait pas non plus lesquels étaient à NULL avant : les redresser tous à l'aveugle
 * effacerait le réglage de délégués légitimes. Le `down()` ne fait donc rien, et le dit.
 */
final class Version20260908140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Le propriétaire du cabinet est toujours gestionnaire des invités et des rôles.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE invite
               SET gestionnaire_invites = 1
             WHERE proprietaire = 1
               AND (gestionnaire_invites IS NULL OR gestionnaire_invites = 0)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Redresser une incohérence ne se défait pas : on ne saurait pas distinguer les '
            . 'propriétaires remis à NULL des délégués qui ne l\'ont jamais été.'
        );
    }
}
