<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\AbortMigration;

/**
 * UN COMPTE N'A QU'UNE SEULE INVITATION PAR CABINET.
 *
 * ── POURQUOI CETTE CONTRAINTE EXISTE ──────────────────────────────────────────
 * `Utilisateur::$invites` est une OneToMany et rien ne protégeait le couple
 * (utilisateur, entreprise). Or tout le cloisonnement repose sur le gardien
 * `CabinetActif`, qui résout l'invité par `findOneBy(['utilisateur', 'entreprise'])` :
 * avec deux lignes, Doctrine en rend UNE, arbitrairement.
 *
 * Deux invitations aux droits différents donnaient donc un périmètre effectif NON
 * DÉTERMINISTE — le même utilisateur, le même cabinet, et des droits qui changent selon
 * ce que la base sert en premier. C'est exactement ce que le gardien prétend garantir.
 *
 * ── LES INVITATIONS EN ATTENTE RESTENT POSSIBLES ──────────────────────────────
 * `utilisateur_id` est NULL tant que la personne n'a pas créé son compte
 * (`InvitationLinker` le renseigne alors). En SQL, un index unique n'applique pas
 * l'unicité aux NULL : un cabinet peut donc avoir autant d'invitations en attente qu'il
 * veut. La contrainte ne mord que sur les comptes RATTACHÉS, qui sont précisément ceux
 * dont le gardien doit trancher le périmètre.
 *
 * ── POURQUOI CETTE MIGRATION REFUSE AU LIEU DE DÉDOUBLONNER ───────────────────
 * Soixante-neuf tables référencent `invite`. Supprimer un doublon « en gardant le plus
 * ancien » casserait des clés étrangères, ou — pire — orphelinerait des portefeuilles,
 * des pistes et des jeux de rôles sans que personne ne le voie passer : le portefeuille
 * d'un gestionnaire supprimé ne disparaît pas, il change silencieusement de main.
 *
 * Et le bon choix n'est PAS mécanique. Entre deux invitations du même compte, laquelle
 * porte les droits voulus ? Laquelle doit hériter des rattachements de l'autre ? Ce sont
 * des questions métier, auxquelles une migration ne peut pas répondre à la place du
 * propriétaire du cabinet.
 *
 * Elle s'arrête donc en NOMMANT les couples fautifs. Sur cette base, zéro doublon : la
 * migration passe sans rien dire. Si elle parle un jour, c'est qu'il y a une décision à
 * prendre, pas une ligne à supprimer.
 */
final class Version20261005210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Un compte n'a qu'une seule invitation par cabinet (hors invitations en attente).";
    }

    public function up(Schema $schema): void
    {
        $this->refuserSiDoublons();

        $this->addSql(
            'CREATE UNIQUE INDEX uniq_invite_utilisateur_entreprise ON invite (utilisateur_id, entreprise_id)',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_invite_utilisateur_entreprise ON invite');
    }

    /**
     * S'arrête en nommant les couples, plutôt que de choisir à la place de quelqu'un.
     */
    private function refuserSiDoublons(): void
    {
        $doublons = $this->connection->fetchAllAssociative(
            'SELECT utilisateur_id, entreprise_id, COUNT(*) AS n,'
            . ' GROUP_CONCAT(id ORDER BY id) AS identifiants'
            . ' FROM invite WHERE utilisateur_id IS NOT NULL'
            . ' GROUP BY utilisateur_id, entreprise_id HAVING n > 1',
        );

        if ($doublons === []) {
            return;
        }

        $lignes = array_map(
            static fn (array $d): string => sprintf(
                '  utilisateur #%s, cabinet #%s : %s lignes (#%s)',
                $d['utilisateur_id'],
                $d['entreprise_id'],
                $d['n'],
                str_replace(',', ', #', (string) $d['identifiants']),
            ),
            $doublons,
        );

        throw new AbortMigration(sprintf(
            "Des comptes possèdent plusieurs invitations dans le même cabinet :\n%s\n\n"
            . "Il faut trancher AVANT d'appliquer cette migration, et le choix n'est pas "
            . "mécanique : soixante-neuf tables référencent `invite`, et supprimer la mauvaise "
            . "ligne ferait changer de main un portefeuille, une piste ou un jeu de rôles sans "
            . "que personne ne le voie passer.\n\n"
            . "Pour chaque couple : décider quelle invitation porte les droits voulus, "
            . "repointer sur elle ce qui dépend de l'autre, puis supprimer l'autre.",
            implode("\n", $lignes),
        ));
    }
}
