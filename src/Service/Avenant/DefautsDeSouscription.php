<?php

namespace App\Service\Avenant;

use App\Ai\Mouvement\MouvementAvenant;
use App\Entity\Avenant;
use App\Entity\Cotation;
use App\Entity\Piste;
use App\Services\Canvas\Indicator\IndicatorCalculationHelper;
use App\Services\Search\PisteTransformationScope;
use Doctrine\ORM\EntityManagerInterface;

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
        // Pour lire le lien police ↔ piste dérivée du côté de la POLICE (cf. policeDeBase).
        private readonly ?EntityManagerInterface $em = null,
    ) {
    }

    /**
     * LA POLICE QUE CETTE PISTE FAIT ÉVOLUER, lue des DEUX côtés du lien — comme
     * AvenantRenouvellementResolver : un lien posé d'un seul côté reste un mouvement.
     */
    public function policeDeBase(?Piste $piste): ?Avenant
    {
        if ($piste === null) {
            return null;
        }

        return $piste->getAvenantDeBase()
            ?? ($piste->getId() !== null && $this->em !== null
                ? $this->em->getRepository(Avenant::class)->findOneBy(['pisteDeRenouvellement' => $piste])
                : null);
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

    /**
     * LE VERBE DU GESTE QUI CRÉE LE PREMIER AVENANT, selon le type de la piste — la carte du
     * pipe, et les messages qui orientent. Repris des actions de la police
     * (AvenantFormCanvasProvider) pour les quatre mouvements.
     *
     * @var array<int, string>
     */
    public const VERBE = [
        Piste::AVENANT_SOUSCRIPTION   => 'Souscrire',
        Piste::AVENANT_INCORPORATION  => 'Souscrire',
        Piste::AVENANT_PROROGATION    => 'Proroger',
        Piste::AVENANT_ANNULATION     => 'Annuler la police',
        Piste::AVENANT_RESILIATION    => 'Résilier la police',
        Piste::AVENANT_RENOUVELLEMENT => 'Renouveler',
    ];

    public static function verbe(?int $typeAvenant): string
    {
        return self::VERBE[$typeAvenant] ?? 'Souscrire';
    }

    /**
     * L'icône du même geste — celles des mouvements de la police (AvenantFormCanvasProvider),
     * pour qu'un même acte ait la même image sur la carte, la ligne et le menu de la police.
     *
     * @var array<int, string>
     */
    public const ICONE = [
        Piste::AVENANT_SOUSCRIPTION   => 'action:completed',
        Piste::AVENANT_INCORPORATION  => 'action:completed',
        Piste::AVENANT_PROROGATION    => 'action:prorogation',
        Piste::AVENANT_ANNULATION     => 'action:annulation',
        Piste::AVENANT_RESILIATION    => 'action:resiliation',
        Piste::AVENANT_RENOUVELLEMENT => 'action:renew',
    ];

    public static function icone(?int $typeAvenant): string
    {
        return self::ICONE[$typeAvenant] ?? 'action:completed';
    }

    /**
     * LE CHEMIN DU TYPE : null = le formulaire d'avenant (souscription, incorporation) ;
     * sinon le MOUVEMENT de la police de base, qui adopte la piste et sa cotation
     * (MouvementAvenantBuilder) — dates, statut de la base et échéances y vivent déjà.
     */
    public static function mouvementDe(?int $typeAvenant): ?MouvementAvenant
    {
        return MouvementAvenant::depuisTypeAvenant($typeAvenant);
    }

    /**
     * UN REFUS QUI MÈNE QUELQUE PART : la proposition d'une piste de mouvement ne se
     * « souscrit » pas, elle se valide par le mouvement de sa police. Null pour une
     * souscription ou une incorporation.
     *
     * Sans police de base, aucun geste de l'écran ne rattache une piste EXISTANTE à une
     * police : on oriente vers celui qui existe, le mouvement lancé depuis la police.
     */
    public function orientationVersLeMouvement(Cotation $cotation): ?string
    {
        $piste = $cotation->getPiste();
        $mouvement = self::mouvementDe($piste?->getTypeAvenant());
        if ($mouvement === null) {
            return null;
        }
        $base = $this->policeDeBase($piste);
        if ($base === null) {
            return sprintf(
                'Cette piste n’est rattachée à aucune police. « %s » se fait depuis la police elle-même : '
                . 'rubrique Avenants, menu « Mouvements de la police ».',
                $mouvement->libelle(),
            );
        }

        return sprintf(
            '« %s » n’est pas une souscription : la proposition « %s » se valide par le mouvement « %s » de la police « %s », qui l’adopte.',
            $mouvement->libelle(),
            (string) $cotation->getNom(),
            self::verbe($piste->getTypeAvenant()),
            (string) $base->getReferencePolice(),
        );
    }

    /**
     * L'ACTION D'UNE CARTE DU PIPE, selon son étape et le type de sa piste — la clé de
     * `vue_cartes.actions` (PisteListCanvasProvider) et de ses droits.
     *
     * Un type EXEMPTÉ de prime (incorporation, annulation, résiliation) n'a pas de prime à
     * saisir : dès « En cotation », sa carte propose le geste qui crée son avenant.
     */
    public static function actionDuPipe(?string $etape, ?int $typeAvenant): ?string
    {
        return match ($etape) {
            PisteTransformationScope::ETAPE_A_COTER          => PisteTransformationScope::ETAPE_A_COTER,
            PisteTransformationScope::ETAPE_EN_COTATION      => self::primeExigee($typeAvenant)
                ? PisteTransformationScope::ETAPE_EN_COTATION
                : self::cleDeSouscription($typeAvenant),
            PisteTransformationScope::ETAPE_OFFRES_CHIFFREES => self::cleDeSouscription($typeAvenant),
            default                                          => null,
        };
    }

    /** « souscrire-{type} » ; un type inconnu suit la souscription. */
    public static function cleDeSouscription(?int $typeAvenant): string
    {
        return 'souscrire-' . (isset(self::VERBE[$typeAvenant]) ? $typeAvenant : Piste::AVENANT_SOUSCRIPTION);
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

        // L'INCORPORATION S'ACHÈVE AVEC LA POLICE qu'elle complète (décision métier du
        // 2026-10-13) : l'assuré ou le véhicule ajouté l'est jusqu'à l'échéance commune.
        // Sans police de base connue, la règle de la souscription s'applique — et se dit.
        $piste = $cotation->getPiste();
        $finDeLaBase = $piste?->getTypeAvenant() === Piste::AVENANT_INCORPORATION
            ? $this->policeDeBase($piste)?->getEndingAt()
            : null;
        // Une police déjà échue ne borne rien : l'incorporation finirait avant de commencer.
        if ($finDeLaBase !== null && \DateTimeImmutable::createFromInterface($finDeLaBase) < $debut) {
            $annonces[] = sprintf(
                'La police « %s » est échue depuis le %s : la période de l’incorporation suit la durée de la proposition.',
                (string) $this->policeDeBase($piste)->getReferencePolice(),
                $finDeLaBase->format('d/m/Y'),
            );
            $finDeLaBase = null;
            $piste = null;
        }
        $fin = null;
        if ($finDeLaBase !== null) {
            $fin = \DateTimeImmutable::createFromInterface($finDeLaBase);
            $annonces[] = sprintf(
                'Période de couverture : du %s au %s, échéance de la police « %s » qu’elle complète.',
                $debut->format('d/m/Y'),
                $fin->format('d/m/Y'),
                (string) $this->policeDeBase($piste)->getReferencePolice(),
            );
        } elseif ($piste?->getTypeAvenant() === Piste::AVENANT_INCORPORATION) {
            $annonces[] = 'Aucune police de base rattachée à cette incorporation : sa période suit la durée de la proposition, comme une souscription.';
        }

        // LA DURÉE DE LA PROPOSITION FAIT LA PÉRIODE : début + durée en mois − 1 jour. Sans
        // durée, on ne devine pas de fin.
        $duree = (int) $cotation->getDuree();
        $fin ??= $duree > 0 ? $debut->modify(sprintf('+%d month', $duree))->modify('-1 day') : null;
        if ($fin !== null && $finDeLaBase === null) {
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
