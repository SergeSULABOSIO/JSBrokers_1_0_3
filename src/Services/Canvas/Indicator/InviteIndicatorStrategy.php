<?php

namespace App\Services\Canvas\Indicator;

use App\Entity\Invite;
use App\Entity\Tache;
use App\Repository\UtilisateurRepository;
use App\Services\ServiceDates;
use Symfony\Contracts\Translation\TranslatorInterface;
use DateTimeImmutable;

class InviteIndicatorStrategy implements IndicatorCalculationStrategyInterface
{
    public function __construct(
        private ServiceDates $serviceDates,
        private TranslatorInterface $translator,
        private UtilisateurRepository $utilisateurRepository
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Invite::class;
    }

    public function calculate(object $entity): array
    {
        /** @var Invite $entity */
        return [
            'ageInvitation' => $this->calculateInviteAge($entity),
            'tachesEnCours' => $this->countInviteTachesEnCours($entity),
            'rolePrincipal' => $this->getInviteRolePrincipal($entity),
            'proprietaireString' => $this->getInviteProprietaireString($entity),
            'status_string' => $this->getInviteStatusString($entity),
            // Booléen strict (jamais null) : condition des actions « portefeuille »
            // de la toolbar, comparée en == lâche côté JS.
            'hasPortefeuille' => !$entity->getPortefeuilles()->isEmpty(),
            // Ligne secondaire : l'absence de portefeuille est une information métier,
            // affichée explicitement (pas de null masquant comme sur la fiche client).
            'portefeuilleNom' => $this->getInvitePortefeuilleNom($entity),
        ];
    }

    // --- Méthodes privées déplacées depuis CalculationProvider ---

    private function calculateInviteAge(Invite $invite): string
    {
        if (!$invite->getCreatedAt()) {
            return 'N/A';
        }
        $jours = $this->serviceDates->daysEntre($invite->getCreatedAt(), new DateTimeImmutable()) ?? 0;
        return $jours . ' jour(s)';
    }

    private function countInviteTachesEnCours(Invite $invite): int
    {
        return $invite->getTaches()->filter(fn(Tache $tache) => !$tache->isClosed())->count();
    }

    private function getInviteRolePrincipal(Invite $invite): string
    {
        $roles = [];
        if (!$invite->getRolesEnProduction()->isEmpty()) {
            $roles[] = 'Production';
        }
        if (!$invite->getRolesEnSinistre()->isEmpty()) {
            $roles[] = 'Sinistre';
        }
        if (!$invite->getRolesEnMarketing()->isEmpty()) {
            $roles[] = 'Marketing';
        }
        if (!$invite->getRolesEnFinance()->isEmpty()) {
            $roles[] = 'Finance';
        }
        if (!$invite->getRolesEnAdministration()->isEmpty()) {
            $roles[] = 'Administration';
        }

        if (empty($roles)) {
            return 'Aucun rôle';
        }

        return implode(' / ', $roles);
    }

    private function getInviteProprietaireString(Invite $invite): string
    {
        return $invite->isProprietaire() ? 'Oui' : 'Non';
    }

    private function getInvitePortefeuilleNom(Invite $invite): string
    {
        $portefeuille = $invite->getPortefeuilles()->first();

        return $portefeuille ? (string) $portefeuille->getNom() : 'Aucun portefeuille';
    }

    private function getInviteStatusString(Invite $invite): string
    {
        $user = $invite->getUtilisateur();

        if (!$user) {
            return "Invitation envoyée";
        }

        if ($user->isVerified()) {
            return "Actif";
        }

        return "En attente de vérification";
    }
}