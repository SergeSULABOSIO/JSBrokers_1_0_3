<?php

namespace App\Service\Console;

use App\Entity\Utilisateur;

/**
 * @file Résout les droits d'accès d'un collaborateur aux rubriques de la Console.
 * @description Source unique de la restriction par département (réutilisée par le
 * ConsoleAccessSubscriber pour le blocage serveur et par ConsoleAccessExtension pour
 * filtrer la navigation — DRY). Le super-admin et la Direction Générale ont un accès
 * complet.
 *
 * ── UN COMPTE SANS DÉPARTEMENT N'A PLUS TOUT ────────────────────────────────
 * La politique était « fail-open jusqu'à affectation » : un collaborateur SANS
 * département gardait l'accès COMPLET — finances, fiscalité, CRM, entreprises,
 * clients, supervision —, la restriction ne commençant qu'une fois un département
 * assigné. L'intention était d'éviter le verrouillage d'un compte neuf ; l'effet
 * était l'inverse de celui d'un garde-fou : l'état par défaut, celui qu'on obtient
 * en oubliant un champ, était le plus permissif de tous.
 *
 * Il est désormais le plus étroit. Un agent non affecté garde les routes
 * ALWAYS_ALLOWED ci-dessous — son tableau de bord, l'organigramme et sa propre
 * fiche d'évaluation —, de quoi se connecter, se situer et demander son
 * rattachement. Le super-admin ouvre le reste en posant un département, ce qui est
 * de toute façon le geste qu'il fait déjà.
 */
class ConsoleAccessResolver
{
    /**
     * Routes toujours accessibles à tout collaborateur, quel que soit son
     * département : accueil, organigramme (transparence) et sa propre fiche
     * d'évaluation en lecture seule (chacun consulte ses objectifs).
     */
    private const ALWAYS_ALLOWED = ['console.dashboard', 'console.departement.index', 'console.evaluation.mine'];

    /** Accès complet : super-admin, ou Direction Générale. */
    public function isAllAccess(Utilisateur $user): bool
    {
        if (in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)) {
            return true;
        }

        return $user->getDepartement()?->grantsAll() === true;
    }

    /**
     * Préfixes de routes accessibles. ['console.'] = tout ; [] = aucun (non affecté,
     * seules les routes ALWAYS_ALLOWED restent joignables).
     *
     * @return string[]
     */
    public function accessiblePrefixes(Utilisateur $user): array
    {
        if ($this->isAllAccess($user)) {
            return ['console.'];
        }

        return $user->getDepartement()?->routePrefixes() ?? [];
    }

    /** Le collaborateur peut-il atteindre la route console nommée $routeName ? */
    public function canAccessRoute(Utilisateur $user, string $routeName): bool
    {
        foreach (self::ALWAYS_ALLOWED as $allowed) {
            if (str_starts_with($routeName, $allowed)) {
                return true;
            }
        }

        foreach ($this->accessiblePrefixes($user) as $prefix) {
            if (str_starts_with($routeName, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
