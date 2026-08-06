<?php

namespace App\Ai\Programme\Effet;

use App\Ai\Programme\EffetMetierVerifieur;
use App\Ai\Scope\AiScope;
use App\Entity\PaiementPrime;
use App\Entity\Tranche;
use App\Services\Canvas\Indicator\IndicatorCalculationHelper;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Effet métier d'un SIGNALEMENT DE PAIEMENT DE PRIME : la prime de la tranche
 * est-elle réellement soldée ?
 *
 * C'est l'écart exact de l'incident fondateur : trois paiements « enregistrés
 * avec succès », les trois PaiementPrime bien présents en base — et deux tranches
 * toujours affichées comme impayées, parce que le montant signalé (le solde par
 * défaut au moment de la préparation) ne couvrait pas la prime due. L'écriture
 * était conforme, la conséquence non ; personne ne le disait.
 *
 * FRAÎCHEUR — non négociable. Le rapport est établi dans la MÊME requête que
 * l'exécution de la dernière étape, et un `setTranche()` ne synchronise jamais la
 * collection inverse en mémoire (gotcha connu du projet). Le total déclaré est
 * donc relu par une AGRÉGATION SQL, pas par la collection de l'objet : sans quoi
 * le rapport signalerait un écart imaginaire sur le paiement qui vient d'être
 * écrit. On prend ensuite le max avec l'indicateur d'affichage, qui porte en plus
 * les inférences (notes soldées, bordereaux) — ainsi le rapport dit la même chose
 * que les chips de la rubrique.
 */
final class PaiementPrimeEffet implements EffetMetierVerifieur
{
    /** Tolérance d'arrondi monétaire : en deçà, la prime est tenue pour soldée. */
    private const EPSILON = 0.01;

    public function __construct(
        private readonly IndicatorCalculationHelper $calculationHelper,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function supporte(string $entiteShortName): bool
    {
        return $entiteShortName === 'PaiementPrime';
    }

    public function verifier(object $entite, AiScope $scope): array
    {
        $vide = ['constats' => [], 'ecarts' => [], 'correction' => null];
        if (!$entite instanceof PaiementPrime) {
            return $vide;
        }
        $tranche = $entite->getTranche();
        if (!$tranche instanceof Tranche) {
            return $vide;
        }

        $primeDue = round(
            $this->calculationHelper->getCotationMontantPrimePayableParClient($tranche->getCotation())
            * $this->calculationHelper->getTrancheTauxFactor($tranche),
            2,
        );
        if ($primeDue <= 0.0) {
            return $vide;
        }

        $payee = max($this->totalDeclareEnBase($tranche), $this->calculationHelper->getTranchePrimePayee($tranche));
        $solde = round($primeDue - $payee, 2);
        $idTranche = (int) $tranche->getId();

        if ($solde <= self::EPSILON) {
            return [
                'constats'   => [sprintf('La prime de la tranche #%d est soldée (%s réglés).', $idTranche, $this->somme($payee))],
                'ecarts'     => [],
                'correction' => null,
            ];
        }

        return [
            'constats' => [],
            'ecarts'   => [sprintf(
                'La prime de la tranche #%d n’est PAS soldée : %s réglés sur %s dus, il reste %s. '
                . 'La tranche reste donc dans le suivi des impayés.',
                $idTranche,
                $this->somme($payee),
                $this->somme($primeDue),
                $this->somme($solde),
            )],
            'correction' => [
                'outil'     => 'signaler_paiement_prime',
                'libelle'   => sprintf('Solder la prime de la tranche #%d (%s)', $idTranche, $this->somme($solde)),
                'arguments' => ['trancheId' => $idTranche, 'montant' => $solde],
            ],
        ];
    }

    /**
     * Total DÉCLARÉ pour la tranche, relu en base par agrégation — jamais par la
     * collection en mémoire (cf. l'entête de classe).
     */
    private function totalDeclareEnBase(Tranche $tranche): float
    {
        $total = $this->em->createQuery(
            'SELECT COALESCE(SUM(pp.montant), 0) FROM App\Entity\PaiementPrime pp WHERE pp.tranche = :tranche'
        )->setParameter('tranche', $tranche)->getSingleScalarResult();

        return round((float) $total, 2);
    }

    private function somme(float $montant): string
    {
        return number_format($montant, 2, ',', ' ');
    }
}
