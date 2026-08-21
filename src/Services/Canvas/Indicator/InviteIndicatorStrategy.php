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
        private UtilisateurRepository $utilisateurRepository,
        private IndicatorCalculationHelper $calculationHelper,
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Invite::class;
    }

    public function calculate(object $entity): array
    {
        /** @var Invite $entity */
        return $this->indicateursFinanciers($entity) + [
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

    /**
     * LES CHIFFRES DE L'INVITÉ SONT CEUX DE SON EFFORT COMMERCIAL, PAS DE SA GESTION.
     *
     * Toutes les colonnes numériques de la rubrique décrivent les affaires dont il est
     * BÉNÉFICIAIRE — celles qu'il a apportées, où une condition de partage le rémunère
     * (filtre `agentCible`). Jamais celles qu'il gère comme gestionnaire de compte
     * (`inviteCible` → Piste::invite), qui ne disent rien de ce qu'il a produit.
     *
     * LA DISTINCTION N'EST PAS COSMÉTIQUE. Un gestionnaire de compte suit des dizaines
     * d'affaires apportées par d'autres : afficher leurs primes sur sa ligne lui
     * attribuerait un résultat commercial qui n'est pas le sien, juste à côté d'une
     * rétrocommission à zéro. La ligne se lit donc d'un bloc : même périmètre pour la
     * prime, la commission, la réserve et la rétro.
     *
     * (Ces quatre colonnes étaient déclarées par le canevas mais jamais alimentées — la
     * stratégie n'appelait pas getIndicateursGlobaux() et Invite était absente de
     * CalculationProvider::CIBLES_AGREGEES : l'écran affichait quatre « — ».)
     *
     * @return array<string, float|bool>
     */
    private function indicateursFinanciers(Invite $invite): array
    {
        $entreprise = $invite->getEntreprise();
        if ($entreprise === null) {
            return [];
        }

        $apporte = $this->calculationHelper->getIndicateursGlobaux($entreprise, false, ['agentCible' => $invite]);

        $retroDue = round($apporte['retro_commission_agent'], 2);
        $retroPayee = round($apporte['retro_commission_agent_payee'], 2);
        $retroExigible = round($apporte['retro_commission_agent_exigible'] ?? 0.0, 2);

        return [
            // Ce que ses affaires ont produit.
            'primeTotale' => round($apporte['prime_totale'], 2),
            'montantTTC' => round($apporte['commission_totale'], 2),
            'montantPur' => round($apporte['commission_pure'], 2),
            'reserve' => round($apporte['reserve'], 2),

            // Ce qui lui revient dessus.
            'retroAgentDue' => $retroDue,
            'retroAgentPayee' => $retroPayee,
            // Plafonné à zéro : un trop-versé n'est pas une dette de l'agent envers le cabinet.
            'retroAgentSolde' => round(max(0.0, $retroDue - $retroPayee), 2),

            // Ce qui est RÉCLAMABLE aujourd'hui : le cabinet a encaissé sa commission,
            // la dette envers l'agent est donc née.
            'retroAgentExigible' => $retroExigible,

            // Condition des actions de la barre d'outils (rapport, reversement) : booléen
            // strict, comparé en == lâche côté JS.
            'hasRetroAgent' => $retroDue > 0.0 || !$invite->getConditionsPartageAgent()->isEmpty(),

            // LE MÊME, MAIS EXIGEANT. Les deux actions « rapport » et « reversement » ne
            // paraissent que si quelque chose est réellement réclamable : proposer de
            // verser un dû que le cabinet n'a pas encore encaissé, c'est inviter à
            // avancer sa trésorerie sur une créance non recouvrée.
            'hasRetroAgentExigible' => $retroExigible > 0.0,
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