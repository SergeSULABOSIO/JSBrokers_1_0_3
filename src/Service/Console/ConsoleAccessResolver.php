<?php

namespace App\Service\Console;

use App\Entity\Utilisateur;

/**
 * @file Résout les droits d'accès d'un collaborateur aux rubriques de la Console.
 * @description Source unique de la restriction par département (réutilisée par le
 * ConsoleAccessSubscriber pour le blocage serveur et par ConsoleAccessExtension pour
 * filtrer la navigation — DRY). Le super-admin et la Direction Générale ont un accès
 * complet. Politique de déploiement « fail-open jusqu'à affectation » : un
 * collaborateur SANS département conserve l'accès complet (comportement historique) ;
 * la restriction ne s'applique qu'une fois un département assigné. Cela évite tout
 * verrouillage pour un nouveau compte non encore rattaché — le super-admin restreint
 * en attribuant explicitement un département.
 */
class ConsoleAccessResolver
{
    /** Routes toujours accessibles à tout collaborateur (accueil + transparence org). */
    private const ALWAYS_ALLOWED = ['console.dashboard', 'console.departement.index'];

    /** Accès complet : super-admin, non affecté (pas encore restreint), ou Direction. */
    public function isAllAccess(Utilisateur $user): bool
    {
        if (in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)) {
            return true;
        }

        $departement = $user->getDepartement();

        return $departement === null || $departement->grantsAll();
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
