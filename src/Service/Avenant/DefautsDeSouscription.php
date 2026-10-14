<?php

namespace App\Service\Avenant;

use App\Entity\Avenant;
use App\Entity\Cotation;
use App\Entity\Piste;
use App\Services\Canvas\Indicator\IndicatorCalculationHelper;
use App\Services\Search\PisteTransformationScope;

/**
 * CE QU'UN AVENANT REÇOIT QUAND ON SOUSCRIT UNE COTATION.
 *
 * Ces règles vivaient dans l'outil de l'assistant (`souscrire_cotation`), et nulle part
 * ailleurs. L'écran, lui, proposait « maintenant + 1 an » à tout avenant créé sous une
 * cotation — sans lire sa durée : une proposition de six mois devenait une police d'un an
 * dès qu'on la souscrivait à la main, et la même souscription donnait deux périodes selon
 * qu'on la faisait par l'écran ou par Ket.
 *
 * Une seule source désormais, pour trois chemins : le « + » des avenants d'une cotation,
 * l'action « Souscrire » d'une carte de piste, et Ket.
 */
final class DefautsDeSouscription
{
    /**
     * LA PRIME EST-ELLE EXIGÉE AU PREMIER AVENANT D'UNE COTATION, selon le type de sa piste ?
     *
     * Une garantie CRÉÉE, RECONDUITE ou ÉTENDUE sans prime n'a pas de sens : souscription,
     * prorogation, renouvellement. Sont exemptés les types sans prime ou à prime nulle :
     * l'annulation et la résiliation (MouvementAvenant::annuleLaPolice), et l'incorporation —
     * un assuré ajouté à un contrat santé groupe forfaitaire, un véhicule ajouté à une flotte
     * dont la prime se régularise en fin d'exercice (décision métier du 2026-10-13).
     *
     * ⚠ TOUT TYPE DU CODE Y FIGURE (PisteType::TYPE_AVENANT_LABELS) : un test le garde. Un
     * type ajouté demain sans décision ferait échouer la suite plutôt que de passer en silence.
     *
     * @var array<int, bool>
     */
    public const PRIME_EXIGEE = [
        Piste::AVENANT_SOUSCRIPTION   => true,
        Piste::AVENANT_INCORPORATION  => false,
        Piste::AVENANT_PROROGATION    => true,
        Piste::AVENANT_ANNULATION     => false,
        Piste::AVENANT_RESILIATION    => false,
        Piste::AVENANT_RENOUVELLEMENT => true,
    ];

    public function __construct(
        // Facultatif à la construction seulement : le conteneur l'injecte toujours. Sans lui,
        // la prime ne se lit pas, et estChiffree() le dit plutôt que de répondre au hasard.
        private readonly ?IndicatorCalculationHelper $indicateurs = null,
    ) {
    }

    /** Un type inconnu, ou une piste sans type, retombe sur « exigée » : c'est le cas courant. */
    public static function primeExigee(?int $typeAvenant): bool
    {
        return self::PRIME_EXIGEE[$typeAvenant] ?? true;
    }

    /** La cotation porte-t-elle une prime chiffrée ? Même seuil que le pipe (ROUND(Σ, 2) > 0). */
    public function estChiffree(Cotation $cotation): bool
    {
        if ($this->indicateurs === null) {
            throw new \LogicException('DefautsDeSouscription doit être obtenu du conteneur pour lire une prime.');
        }

        return PisteTransformationScope::primeChiffree($this->indicateurs->getCotationMontantPrimePayableParClient($cotation));
    }

    /**
     * POURQUOI CE PREMIER AVENANT NE PEUT PAS ÊTRE ÉCRIT — ou null s'il le peut.
     *
     * Null pour un avenant SUIVANT (la cotation en porte déjà un autre que `$nouveau`), pour
     * un type exempté, ou pour une cotation chiffrée. Le motif nomme le type : on ne dit pas
     * « souscription » à qui renouvelle.
     *
     * @param Avenant|null $nouveau l'avenant en cours d'écriture, s'il est déjà rattaché à la
     *                              cotation en mémoire (il ne compte pas comme « précédent »)
     */
    public function refusDAvenant(Cotation $cotation, ?Avenant $nouveau = null): ?string
    {
        foreach ($cotation->getAvenants() as $existant) {
            if ($existant !== $nouveau) {
                return null;
            }
        }
        $type = $cotation->getPiste()?->getTypeAvenant();
        if (!self::primeExigee($type) || $this->estChiffree($cotation)) {
            return null;
        }

        return sprintf(
            '%s sans prime n’a pas de sens : la proposition « %s » n’a pas encore de prime chiffrée. Saisissez d’abord sa prime.',
            self::LIBELLE_DU_GESTE[$type] ?? 'Une souscription',
            (string) $cotation->getNom(),
        );
    }

    /** Le geste « Souscrire » (carte, ligne, pipe, Ket) : pas encore souscrite, et rien ne s'y oppose. */
    public function peutEtreSouscrite(Cotation $cotation): bool
    {
        return !$this->dejaSouscrite($cotation) && $this->refusDAvenant($cotation) === null;
    }

    /** Le sujet du motif de refus, accordé au type. @var array<int, string> */
    private const LIBELLE_DU_GESTE = [
        Piste::AVENANT_SOUSCRIPTION   => 'Une souscription',
        Piste::AVENANT_PROROGATION    => 'Une prorogation',
        Piste::AVENANT_RENOUVELLEMENT => 'Un renouvellement',
    ];

    /**
     * Les valeurs de l'avenant, et les phrases qui annoncent celles qu'on a choisies à la
     * place de l'utilisateur (Ket les lui dit ; l'écran les lui montre dans le formulaire).
     *
     * @param \DateTimeImmutable|null $dateEffet la date dictée ; à défaut, aujourd'hui
     * @param string|null             $numero    le numéro dicté ; à défaut, 0 (la police d'origine)
     *
     * @return array{startingAt: \DateTimeImmutable, endingAt: ?\DateTimeImmutable, numero: string, description: string, annonces: list<string>}
     */
    public function pour(Cotation $cotation, ?\DateTimeImmutable $dateEffet = null, ?string $numero = null): array
    {
        $annonces = [];

        $debut = $dateEffet;
        if ($debut === null) {
            $debut = new \DateTimeImmutable('today');
            $annonces[] = sprintf('Prise d’effet : %s (aucune date dictée).', $debut->format('d/m/Y'));
        }

        // LA DURÉE DE LA PROPOSITION FAIT LA PÉRIODE : début + durée en mois − 1 jour. Sans
        // durée, on ne devine pas de fin.
        $duree = (int) $cotation->getDuree();
        $fin = $duree > 0 ? $debut->modify(sprintf('+%d month', $duree))->modify('-1 day') : null;
        if ($fin !== null) {
            $annonces[] = sprintf(
                'Période de couverture : du %s au %s (durée de %d mois portée par la proposition).',
                $debut->format('d/m/Y'),
                $fin->format('d/m/Y'),
                $duree,
            );
        }

        $numero = trim((string) $numero);
        if ($numero === '') {
            $numero = '0';
            $annonces[] = 'Numéro d’avenant : 0 (la police d’origine).';
        }

        return [
            'startingAt' => $debut,
            'endingAt' => $fin,
            'numero' => $numero,
            'description' => sprintf('Souscription de la proposition « %s ».', (string) $cotation->getNom()),
            'annonces' => $annonces,
        ];
    }

    /**
     * Une proposition déjà souscrite ne se souscrit pas deux fois : ce serait un second
     * contrat sur le même marché.
     */
    public function dejaSouscrite(Cotation $cotation): bool
    {
        return count($cotation->getAvenants()) > 0;
    }
}
