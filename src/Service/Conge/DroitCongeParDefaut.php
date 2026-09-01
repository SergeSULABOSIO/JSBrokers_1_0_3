<?php

namespace App\Service\Conge;

use App\Entity\Invite;
use App\Entity\RolesEnAdministration;

/**
 * TOUT COLLABORATEUR PEUT DEMANDER UN CONGÉ DÈS SON ARRIVÉE.
 *
 * ── LA SEULE ATTRIBUTION D'OFFICE DE L'APPLICATION, ET POURQUOI ─────────────────────
 * Partout ailleurs, le modèle est fail-closed : un invité n'a que ce que le propriétaire
 * lui coche. C'est la bonne politique pour des polices ou des commissions. Elle ne tient
 * pas ici : poser un congé n'est pas une faveur qu'on accorde, c'est un droit du contrat
 * de travail. Un collaborateur qui doit attendre qu'on lui ouvre la rubrique pour
 * demander ses jours ne verrait qu'un menu vide, sans rien qui lui dise pourquoi.
 *
 * On accorde donc, et seulement, la LECTURE (voir ses demandes — les siennes, cf.
 * CongeVisibiliteScope) et l'ÉCRITURE (en poser). Ni Modification — qui ferait de chacun
 * un valideur —, ni Suppression, ni le paramétrage.
 *
 * ── UNE VRAIE LIGNE DE RÔLE, PAS UNE EXCEPTION DANS LE MOTEUR ──────────────────────
 * Le droit est écrit dans un enregistrement RolesEnAdministration ordinaire. Il apparaît
 * donc dans le gestionnaire des rôles, où le propriétaire le VOIT et peut le retirer.
 * L'alternative — un cas particulier dans WorkspaceAccessResolver — aurait accordé un
 * accès que personne n'aurait pu ni constater ni révoquer.
 *
 * Idempotent : un invité qui a déjà un accès aux congés n'est jamais retouché. On ne
 * défait pas le réglage d'un cabinet en rejouant un semis.
 */
class DroitCongeParDefaut
{
    /** Nom du rôle créé d'office, reconnaissable dans le gestionnaire des rôles. */
    public const NOM_ROLE = 'Congés (accès de base)';

    /**
     * Accorde la lecture et l'écriture sur les congés à cet invité, si ce n'est déjà fait.
     *
     * N'appelle ni persist ni flush : le rôle est attaché à la collection de l'invité,
     * qui est en cascade persist. L'appelant maîtrise sa transaction.
     *
     * @return bool vrai si un droit a été accordé (donc s'il reste quelque chose à écrire)
     */
    public function appliquer(Invite $invite): bool
    {
        if ($this->aDejaUnAccesConge($invite)) {
            return false;
        }

        $role = (new RolesEnAdministration())
            ->setNom(self::NOM_ROLE)
            ->setAccessConge([Invite::ACCESS_LECTURE, Invite::ACCESS_ECRITURE]);

        // AuditableTrait exige l'entreprise (NOT NULL en base) ; l'invité est posé par
        // l'adder, qui maintient la relation dans les deux sens.
        $role->setEntreprise($invite->getEntreprise());
        $invite->addRolesEnAdministration($role);

        return true;
    }

    /**
     * L'invité dispose-t-il déjà d'un accès aux congés, quel qu'en soit le niveau ?
     *
     * On regarde TOUS ses rôles en administration : le propriétaire a pu lui en attribuer
     * un ailleurs, sous un autre nom. Ce qui compte est l'accès effectif, pas l'étiquette.
     */
    private function aDejaUnAccesConge(Invite $invite): bool
    {
        foreach ($invite->getRolesEnAdministration() as $role) {
            if ($role->getAccessConge() !== []) {
                return true;
            }
        }

        return false;
    }
}
