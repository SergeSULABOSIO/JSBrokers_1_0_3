<?php

namespace App\Ai\Mouvement;

use App\Ai\Scope\AiScope;
use App\Entity\Avenant;
use App\Entity\Cotation;
use App\Entity\Piste;
use App\Form\AvenantType;
use App\Service\Avenant\DefautsDeSouscription;
use App\Services\Search\PisteTransformationScope;
use App\Services\Canvas\Indicator\IndicatorCalculationHelper;
use App\Services\Piste\NomDePisteDerivee;
use App\Services\ReconductionPartageService;
use App\Services\Tranche\RegularisationDesEcheances;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * DÉCALQUE d'une police : traduit un MOUVEMENT (renouvellement, prorogation,
 * annulation, résiliation) en la liste d'opérations que preparer_operations sait
 * exécuter — sans jamais rien écrire.
 *
 * RAISON D'ÊTRE. Un renouvellement « à l'identique » n'a aucune information à
 * demander : tout est déjà dans la police en cours. Le laisser au modèle
 * signifiait lui faire LIRE puis RECOPIER une dizaine de montants (chargements,
 * tranches, revenus) — coûteux en lectures, et surtout faillible : un chargement
 * oublié, et la prime du renouvellement est fausse. Ici le décalque est calculé
 * par le serveur, à partir des entités elles-mêmes. Le modèle ne transcrit
 * aucun chiffre ; il annonce ce que le plan contient.
 *
 * CE QUI EST RECONDUIT, ET CE QUI NE L'EST PAS. Les partenaires et les
 * conditions de partage suivent la police (via ReconductionPartageService, la
 * MÊME règle que le submit du formulaire de piste dérivée). Les TÂCHES et les
 * FEEDBACKS de la police de base ne suivent JAMAIS : ils appartiennent à
 * l'exercice écoulé. À la place, une affaire qui poursuit son cycle de vie
 * reçoit UNE tâche neuve — suivre le paiement de la prime auprès de l'assuré,
 * car c'est ce paiement qui rend la commission du courtier exigible.
 *
 * DÉCOUPAGE EN ÉTAPES. Les quatre opérations structurelles portent la MÊME
 * étape, celle de l'opération de tête : MutationPlan::filtrerEtapes() retient
 * toujours l'étape socle, ce qui les rend indécochables — un « @renvoi » ne peut
 * donc jamais se retrouver orphelin. Seule la tâche de suivi porte une étape
 * distincte : rien ne dépend d'elle, son décochage est sûr.
 */
final class MouvementAvenantBuilder
{
    /** Étape (décochable) de la tâche de suivi du paiement. */
    public const ETAPE_TACHE = 'Tâche de suivi du paiement';

    /** Étiquettes de renvoi entre opérations du même plan. */
    private const REF_PISTE    = 'mouvement';
    private const REF_COTATION = 'cotation';

    /** Format attendu par un DateTimeType `single_text` (HTML5 local). */
    private const FORMAT_DATETIME = 'Y-m-d\TH:i:s';
    /** Format attendu par un DateType `single_text`. */
    private const FORMAT_DATE = 'Y-m-d';

    public function __construct(
        private readonly IndicatorCalculationHelper $indicatorHelper,
        private readonly ReconductionPartageService $reconductionPartage,
        private readonly RegularisationDesEcheances $regularisation,
        private readonly ClockInterface $horloge,
        // La règle de la prime du premier avenant, pour la proposition ADOPTÉE.
        private readonly DefautsDeSouscription $defautsDeSouscription,
        // Fuseau de l'application, EXPLICITE : « aujourd'hui » décide si une période est
        // déjà échue, et une heure de mur différente de celle des chips d'échéance ferait
        // dire à l'aperçu le contraire de la rubrique autour de minuit.
        #[Autowire('%app.timezone%')]
        private readonly string $fuseau = '',
    ) {
    }

    /** Minuit du jour, dans le fuseau de l'application (APP_TIMEZONE). */
    public function aujourdhui(): \DateTimeImmutable
    {
        try {
            $zone = new \DateTimeZone($this->fuseau);
        } catch (\Exception) {
            $zone = new \DateTimeZone(date_default_timezone_get());
        }

        return \DateTimeImmutable::createFromInterface($this->horloge->now())->setTimezone($zone)->setTime(0, 0);
    }

    /**
     * Prise d'effet d'un renouvellement ou d'une prorogation quand l'utilisateur n'en
     * donne pas : le lendemain de l'échéance, pour une couverture continue. Source
     * unique de cette règle — le picker y lit la date qu'il pré-remplit.
     */
    public function dateEffetParDefaut(Avenant $base): ?\DateTimeImmutable
    {
        $fin = $base->getEndingAt();

        return $fin !== null ? $this->dateEffetParDefautDepuis($fin) : null;
    }

    private function dateEffetParDefautDepuis(\DateTimeImmutable $finBase): \DateTimeImmutable
    {
        return $finBase->modify('+1 day');
    }

    /**
     * @param array<string, mixed> $args arguments de l'outil (dates, durée, écarts)
     *
     * @return array{
     *     bloquant?: string,
     *     aDemander?: array<int, array{champ: string, question: string}>,
     *     operations?: array<int, array<string, mixed>>,
     *     source?: array<string, mixed>,
     *     defauts?: array<int, string>,
     *     ecarts?: array<int, string>,
     *     reconduit?: array<string, int>,
     *     avertissements?: array<int, string>
     * }
     *
     * @param Cotation|null $adoptee la proposition DÉJÀ MONTÉE sur la piste dérivée de la
     *                               police, à adopter au lieu d'en créer une (cf. adoption())
     */
    public function construire(MouvementAvenant $mouvement, Avenant $base, array $args, AiScope $scope, ?Cotation $adoptee = null): array
    {
        $cotationBase = $base->getCotation();
        $pisteBase    = $cotationBase?->getPiste();
        $debutBase    = $base->getStartingAt();
        $finBase      = $base->getEndingAt();

        if ($cotationBase === null || $pisteBase === null) {
            return ['bloquant' => sprintf(
                'La police « %s » n’est rattachée à aucune proposition (ou la proposition à aucune '
                . 'opportunité) : impossible d’en dériver un mouvement. Complétez ce rattachement d’abord.',
                (string) $base->getReferencePolice(),
            )];
        }
        if ($debutBase === null || $finBase === null) {
            return ['bloquant' => sprintf(
                'La police « %s » n’a pas de période de couverture complète : impossible d’en calculer une suite.',
                (string) $base->getReferencePolice(),
            )];
        }

        // L'identité de la police (client, risque, assureur, période de base) ne
        // dépend d'AUCUN argument : elle accompagne donc TOUTES les réponses, y
        // compris celle qui réclame encore une date. Sans cela, l'écran affichait
        // « Client non renseigné » à l'ouverture d'une prorogation, alors que le
        // client était parfaitement connu.
        $source = $this->source($base, $pisteBase, $cotationBase, $debutBase, $finBase);

        if ($adoptee !== null && ($refus = $this->refusDAdoption($mouvement, $base, $adoptee)) !== null) {
            return ['bloquant' => $refus, 'source' => $source];
        }

        $periode = $this->periode($mouvement, $debutBase, $finBase, $args, $adoptee);
        if (isset($periode['bloquant'])) {
            return ['bloquant' => $periode['bloquant'], 'source' => $source];
        }
        if (isset($periode['aDemander'])) {
            return [
                'aDemander'      => [$periode['aDemander']],
                'source'         => $source,
                'avertissements' => $periode['avertissements'] ?? [],
            ];
        }

        /** @var \DateTimeImmutable $debut */
        $debut = $periode['debut'];
        /** @var \DateTimeImmutable $fin */
        $fin = $periode['fin'];

        // Facteur appliqué à TOUT montant reconduit : 1 pour un renouvellement
        // (à l'identique), le prorata des jours pour une prorogation, 0 pour un
        // acte qui ne porte aucune prime.
        $joursBase = $this->joursInclus($debutBase, $finBase);
        $facteur = match (true) {
            !$mouvement->porteUnePrime()               => 0.0,
            $mouvement === MouvementAvenant::Prorogation => $joursBase > 0 ? $periode['jours'] / $joursBase : 1.0,
            default                                    => 1.0,
        };

        $avertissements = [];
        $ecarts         = $periode['ecarts'];

        // UN AVENANT NÉ EXPIRÉ. Une police échue depuis longtemps, reprise au lendemain
        // de son échéance, donne une période elle-même déjà échue : l'avenant entrera à
        // son tour dans « Échus » dès sa création. C'est juste pour une régularisation
        // (l'assureur a bien reconduit, la saisie rattrape), faux pour une reprise après
        // interruption — seul l'utilisateur le sait, il doit donc le VOIR avant de valider.
        if ($mouvement->porteUnePrime() && $fin < $this->aujourdhui()) {
            $avertissements[] = $this->avertissementPeriodeEchue($debut, $fin);
        }

        if ($adoptee !== null) {
            return $this->adoption($mouvement, $base, $pisteBase, $adoptee, $debut, $fin, $periode['jours'], $args, $ecarts, $avertissements, $source, $scope);
        }
        $etape          = sprintf('%s de la police', $mouvement->libelle());

        // ---------------------------------------------------------------- Piste dérivée
        $opPiste = [
            'op'     => 'create',
            'entite' => 'Piste',
            'ref'    => self::REF_PISTE,
            'etape'  => $etape,
            'champs' => $this->champsPiste($mouvement, $base, $pisteBase, $cotationBase, $debut, $facteur),
        ];
        $conditions = $this->conditionsReconduites($pisteBase, $etape, $avertissements);
        if ($conditions !== []) {
            $opPiste['collections'] = [['collection' => 'conditionsPartageExceptionnelles', 'elements' => $conditions]];
        }

        // ---------------------------------------------------------------- Cotation
        $chargements = $mouvement->porteUnePrime()
            ? $this->chargementsReconduits($cotationBase, $facteur, $etape, $args, $ecarts, $avertissements)
            : [];
        $tranches = $mouvement->porteUnePrime()
            ? $this->tranchesReconduites($mouvement, $cotationBase, $debutBase, $debut, $etape)
            : [];
        $revenus = $mouvement->porteUnePrime()
            ? $this->revenusReconduits($cotationBase, $facteur, $etape)
            : [];

        // UNE GARANTIE RECONDUITE OU ÉTENDUE SANS PRIME N'A PAS DE SENS — la règle du
        // premier avenant (DefautsDeSouscription::PRIME_EXIGEE), dite ICI, avant le plan.
        // Sinon le contrôle à blanc l'ignore (la cotation n'est encore qu'une promesse),
        // l'utilisateur valide, et le refus tombe à l'écriture de l'avenant. Le cas existe :
        // une police reprise sans prime.
        if (DefautsDeSouscription::primeExigee($mouvement->typeAvenant())) {
            $primeReconduite = array_sum(array_map(
                static fn (array $c): float => (float) ($c['champs']['montantFlatExceptionel'] ?? 0.0),
                $chargements,
            ));
            if (!PisteTransformationScope::primeChiffree($primeReconduite)) {
                return ['bloquant' => sprintf(
                    '%s sans prime n’a pas de sens : la police « %s » n’a aucune prime à reconduire. '
                    . 'Indiquez la prime de ce mouvement, ou saisissez d’abord celle de la police.',
                    $mouvement === MouvementAvenant::Prorogation ? 'Une prorogation' : 'Un renouvellement',
                    (string) $base->getReferencePolice(),
                ), 'source' => $source];
            }
        }

        $collectionsCotation = [];
        foreach (['chargements' => $chargements, 'tranches' => $tranches, 'revenus' => $revenus] as $nom => $elements) {
            if ($elements !== []) {
                $collectionsCotation[] = ['collection' => $nom, 'elements' => $elements];
            }
        }

        // Tâche de suivi du paiement : seulement quand l'affaire poursuit son
        // cycle de vie. Une annulation ne fait naître aucune prime à recouvrer.
        $tache = null;
        if ($mouvement->poursuitLeCycle()) {
            $tache = $this->tacheDeSuivi($base, $pisteBase, $tranches, $debut, $scope);
            $collectionsCotation[] = ['collection' => 'taches', 'elements' => [$tache]];
        }

        $opCotation = [
            'op'     => 'create',
            'entite' => 'Cotation',
            'ref'    => self::REF_COTATION,
            'etape'  => $etape,
            'champs' => $this->champsCotation($cotationBase, $facteur, $mouvement, $args, $ecarts),
        ];
        if ($collectionsCotation !== []) {
            $opCotation['collections'] = $collectionsCotation;
        }

        // ---------------------------------------------------------------- Avenant + lien
        $opAvenant = [
            'op'     => 'create',
            'entite' => 'Avenant',
            'etape'  => $etape,
            'champs' => $this->champsAvenant($mouvement, $base, $debut, $fin, $periode['jours'], $args, $ecarts, $avertissements),
        ];

        // LE STATUT DE LA BASE SUIT SON SORT, pour les quatre mouvements. Resté « En
        // cours », une police renouvelée était comptée parmi les polices actives À CÔTÉ de
        // son successeur. Le statut d'avant est mémorisé par l'opportunité dérivée
        // (Piste::$statutBaseAvantMouvement) et restitué si le mouvement est abandonné.
        $champsLien = [
            'pisteDeRenouvellement' => '@' . self::REF_PISTE,
            'renewalStatus'         => (string) $mouvement->statutDeLaBase(),
        ];
        $opLien = [
            'op'     => 'edit',
            'entite' => 'Avenant',
            'id'     => $base->getId(),
            'etape'  => $etape,
            'champs' => $champsLien,
        ];

        // LES ÉCHÉANCES QUE L'ACTE REND SANS OBJET. Une annulation ou une résiliation fait
        // passer « à régulariser » les échéances de la police qui couvrent une période où
        // elle n'existe plus : elles sortent du recouvrement, sans qu'aucun montant change.
        // Une écriture par échéance, DANS le plan : écran et Ket l'annoncent et la facturent
        // pareil. La règle vit dans RegularisationDesEcheances, pas ici.
        [$opsRegularisation, $regularisation] = $mouvement->annuleLaPolice()
            ? $this->regularisationDesEcheances($base, $debut, $etape, $avertissements)
            : [[], null];

        if ($mouvement === MouvementAvenant::Renouvellement
            && $pisteBase->getRenewalCondition() === Piste::RENEWAL_CONDITION_ONCE_OFF_AND_EXTENDABLE) {
            $avertissements[] = 'L’opportunité de base est marquée « temporaire non renouvelable » : '
                . 'ce renouvellement s’écarte de la condition enregistrée.';
        }

        return [
            'operations'     => [$opPiste, $opCotation, $opAvenant, $opLien, ...$opsRegularisation],
            'source'         => $source,
            'defauts'        => array_merge(
                $this->defauts($mouvement, $debut, $fin, $periode['jours'], $facteur),
                $regularisation['defaut'] ?? [],
            ),
            'regularisation' => $regularisation,
            'ecarts'         => $ecarts,
            'reconduit'      => [
                'chargements'  => count($chargements),
                'tranches'     => count($tranches),
                'revenus'      => count($revenus),
                'partenaire'   => isset($opPiste['champs']['partenaire']) ? 1 : 0,
                'conditions'   => count($conditions),
                // Conditions d'agents internes RATTACHÉES (jamais clonées) : à annoncer,
                // sans quoi l'utilisateur ne saurait pas que la rémunération de son
                // apporteur suit la police renouvelée.
                'conditionsAgent' => count($opPiste['champs']['conditionsPartageAgent'] ?? []),
                'tacheDeSuivi' => $tache === null ? 0 : 1,
            ],
            'avertissements' => $avertissements,
        ];
    }

    /**
     * Les échéances qu'un acte à effet du $dateEffet met « à régulariser » : une opération
     * par échéance (« @mouvement » pose l'état et mémorise l'état d'avant), le résumé de
     * l'aperçu, et les signalements — commission déjà facturée, ristourne possible.
     *
     * @param array<int, string> $avertissements enrichi par référence
     *
     * @return array{0: array<int, array<string, mixed>>, 1: array<string, mixed>}
     */
    private function regularisationDesEcheances(Avenant $base, \DateTimeImmutable $dateEffet, string $etape, array &$avertissements, int|string|null $piste = null): array
    {
        // La piste dérivée : promise par le plan (« @mouvement »), ou déjà écrite (adoption).
        $piste ??= '@' . self::REF_PISTE;
        $analyse = $this->regularisation->analyser($base, $dateEffet);
        $ops = [];
        $echeances = [];

        foreach ($analyse['aRegulariser'] as $ligne) {
            $tranche = $ligne['tranche'];
            if ($tranche->getId() === null) {
                continue;
            }
            $ops[] = [
                'op'     => 'edit',
                'entite' => 'Tranche',
                'id'     => $tranche->getId(),
                'etape'  => $etape,
                'champs' => ['regularisationPar' => $piste],
            ];
            $commissionFacturee = RegularisationDesEcheances::phraseCommissionFacturee($ligne['notesDeCommission']);
            $echeances[] = [
                'nom'                => (string) $tranche->getNom(),
                'date'               => RegularisationDesEcheances::dateDeReference($tranche)?->format('d/m/Y'),
                'prime'              => $ligne['prime'],
                'commissionFacturee' => $commissionFacturee,
            ];
            if ($commissionFacturee !== null) {
                $avertissements[] = sprintf('Échéance « %s » : %s', (string) $tranche->getNom(), $commissionFacturee);
            }
        }

        $ristournes = [];
        foreach ($analyse['ristournePossible'] as $ligne) {
            $ristournes[] = [
                'nom'        => (string) $ligne['tranche']->getNom(),
                'date'       => RegularisationDesEcheances::dateDeReference($ligne['tranche'])?->format('d/m/Y'),
                'prime'      => $ligne['prime'],
                'primePayee' => $ligne['primePayee'],
            ];
            $avertissements[] = sprintf(
                'Échéance « %s » déjà encaissée (%s sur %s) : elle n’est pas touchée, mais peut donner lieu à '
                . 'ristourne — par note de crédit.',
                (string) $ligne['tranche']->getNom(),
                number_format($ligne['primePayee'], 2, ',', ' '),
                number_format($ligne['prime'], 2, ',', ' '),
            );
        }

        $nombre = count($echeances);
        $defaut = $nombre === 0 ? [] : [sprintf(
            '%d échéance%s de la police (%s de prime) passe%s « à régulariser » : elle%s sort%s du recouvrement. '
            . 'La ristourne éventuelle se fait par note de crédit.',
            $nombre,
            $nombre > 1 ? 's' : '',
            number_format($analyse['montant'], 2, ',', ' '),
            $nombre > 1 ? 'nt' : '',
            $nombre > 1 ? 's' : '',
            $nombre > 1 ? 'ent' : '',
        )];

        return [$ops, [
            'nombre'            => $nombre,
            'montant'           => $analyse['montant'],
            'echeances'         => $echeances,
            'ristournePossible' => $ristournes,
            'defaut'            => $defaut,
        ]];
    }

    // ------------------------------------------------------------------ adoption

    /**
     * LA DURÉE DE LA PROPOSITION, en jours, à partir de `$debut` : `durée` mois, dernier jour
     * inclus. Null quand la proposition n'en porte pas — la règle de la police de base
     * s'applique alors. Source unique : le picker y lit la durée qu'il pré-remplit.
     */
    public function joursDeLaProposition(Cotation $proposition, \DateTimeImmutable $debut): ?int
    {
        $mois = (int) $proposition->getDuree();
        if ($mois <= 0) {
            return null;
        }

        return $this->joursInclus($debut, $debut->modify(sprintf('+%d month', $mois))->modify('-1 day'));
    }

    /**
     * La proposition peut-elle être ADOPTÉE par ce mouvement de cette police ? Null si oui,
     * sinon la raison, dans les mots du métier.
     *
     * Elle doit appartenir à la piste dérivée DE CETTE POLICE (les deux sens du lien, comme
     * AvenantRenouvellementResolver), d'un type qui est CE mouvement, encore ouverte, et sans
     * avenant. Un mouvement qui porte une prime l'exige chiffrée (DefautsDeSouscription).
     */
    public function refusDAdoption(MouvementAvenant $mouvement, Avenant $base, Cotation $proposition): ?string
    {
        $piste = $proposition->getPiste();
        $deLaPolice = $piste !== null && (
            $piste->getAvenantDeBase()?->getId() === $base->getId()
            || $base->getPisteDeRenouvellement()?->getId() === $piste->getId()
        );
        if (!$deLaPolice) {
            return sprintf(
                'La proposition « %s » n’appartient pas à la piste de mouvement de la police « %s ».',
                (string) $proposition->getNom(),
                (string) $base->getReferencePolice(),
            );
        }
        if ($piste->getTypeAvenant() !== $mouvement->typeAvenant()) {
            return sprintf(
                'La piste « %s » prépare une %s, pas une %s : choisissez le mouvement qui lui correspond.',
                (string) $piste->getNom(),
                mb_strtolower(MouvementAvenant::depuisTypeAvenant($piste->getTypeAvenant())?->libelle() ?? 'souscription'),
                mb_strtolower($mouvement->libelle()),
            );
        }
        if ($piste->isClosed()) {
            return sprintf('La piste « %s » est clôturée : elle ne se souscrit plus.', (string) $piste->getNom());
        }
        if (count($proposition->getAvenants()) > 0) {
            return sprintf('La proposition « %s » porte déjà son avenant : elle est souscrite.', (string) $proposition->getNom());
        }
        // UNE PISTE DE MOUVEMENT DONNE UN SEUL AVENANT : deux offres d'assureurs pour un même
        // renouvellement sont concurrentes — en adopter une rend l'autre caduque.
        foreach ($piste->getCotations() as $autre) {
            if ($autre !== $proposition && count($autre->getAvenants()) > 0) {
                return sprintf(
                    'La piste « %s » a déjà donné son avenant, par la proposition « %s » : la police est déjà traitée.',
                    (string) $piste->getNom(),
                    (string) $autre->getNom(),
                );
            }
        }

        return $this->defautsDeSouscription->refusDAvenant($proposition);
    }

    /**
     * LE MOUVEMENT ADOPTE LA PISTE DÉRIVÉE DÉJÀ MONTÉE, au lieu d'en créer une seconde
     * (décision métier du 2026-10-13). La piste et sa proposition existent : elles ont été
     * négociées. Le plan n'écrit donc que l'AVENANT, sur la proposition adoptée, et garde
     * les effets du mouvement : le statut de la police de base, les échéances qu'une
     * annulation ou une résiliation rend sans objet, la tâche de suivi du paiement.
     *
     * Ni prime ni échéancier reconduits : ceux de la proposition FONT FOI. Sa durée aussi
     * (periode()). L'avenant porte la précondition `premierAvenant` : deux adoptions
     * concurrentes de la même proposition n'en souscrivent qu'une.
     *
     * @param array<int, string> $ecarts
     * @param array<int, string> $avertissements
     * @param array<string, mixed> $source
     *
     * @return array<string, mixed>
     */
    private function adoption(
        MouvementAvenant $mouvement,
        Avenant $base,
        Piste $pisteBase,
        Cotation $proposition,
        \DateTimeImmutable $debut,
        \DateTimeImmutable $fin,
        int $jours,
        array $args,
        array $ecarts,
        array $avertissements,
        array $source,
        AiScope $scope,
    ): array {
        $piste = $proposition->getPiste();
        $etape = sprintf('%s de la police', $mouvement->libelle());

        $operations = [];
        // Le lien vers la police, posé du côté de la piste s'il ne l'était que de l'autre :
        // c'est en le posant que la piste retient le statut d'avant (restitué à l'abandon).
        if ($piste->getAvenantDeBase()?->getId() !== $base->getId()) {
            $operations[] = ['op' => 'edit', 'entite' => 'Piste', 'id' => $piste->getId(), 'etape' => $etape, 'champs' => ['avenantDeBase' => $base->getId()]];
        }

        $champsAvenant = $this->champsAvenant($mouvement, $base, $debut, $fin, $jours, $args, $ecarts, $avertissements);
        $champsAvenant['cotation'] = $proposition->getId();
        $operations[] = ['op' => 'create', 'entite' => 'Avenant', 'etape' => $etape, 'premierAvenant' => true, 'champs' => $champsAvenant];

        $operations[] = [
            'op'     => 'edit',
            'entite' => 'Avenant',
            'id'     => $base->getId(),
            'etape'  => $etape,
            'champs' => ['pisteDeRenouvellement' => $piste->getId(), 'renewalStatus' => (string) $mouvement->statutDeLaBase()],
        ];

        [$opsRegularisation, $regularisation] = $mouvement->annuleLaPolice()
            ? $this->regularisationDesEcheances($base, $debut, $etape, $avertissements, $piste->getId())
            : [[], null];
        array_push($operations, ...$opsRegularisation);

        // La tâche de suivi, rattachée à la proposition adoptée — à l'échéance de sa
        // première tranche, comme pour un mouvement qui en crée une.
        $tache = null;
        if ($mouvement->poursuitLeCycle()) {
            $tranches = [];
            foreach ($proposition->getTranches() as $tranche) {
                $tranches[] = ['champs' => ['payableAt' => $tranche->getPayableAt()?->format(self::FORMAT_DATE)]];
            }
            $tache = $this->tacheDeSuivi($base, $pisteBase, $tranches, $debut, $scope);
            $tache['entite'] = 'Tache';
            $tache['champs']['cotation'] = $proposition->getId();
            $operations[] = $tache;
        }

        return [
            'operations'     => $operations,
            'source'         => $source,
            'defauts'        => array_merge(
                $this->defautsDAdoption($mouvement, $proposition, $debut, $fin, $jours),
                $regularisation['defaut'] ?? [],
            ),
            'regularisation' => $regularisation,
            'ecarts'         => $ecarts,
            'reconduit'      => [
                'chargements' => 0, 'tranches' => 0, 'revenus' => 0, 'partenaire' => 0, 'conditions' => 0,
                'conditionsAgent' => 0, 'tacheDeSuivi' => $tache === null ? 0 : 1,
            ],
            'adoptee'        => ['cotationId' => $proposition->getId(), 'nom' => $proposition->getNom(), 'pisteId' => $piste->getId(), 'piste' => $piste->getNom()],
            'avertissements' => $avertissements,
        ];
    }

    /** @return array<int, string> Ce que l'adoption a décidé à la place de l'utilisateur. */
    private function defautsDAdoption(MouvementAvenant $m, Cotation $proposition, \DateTimeImmutable $debut, \DateTimeImmutable $fin, int $jours): array
    {
        $defauts = [sprintf(
            'La proposition « %s » déjà montée est adoptée : %s.',
            (string) $proposition->getNom(),
            $m->porteUnePrime()
                ? 'sa prime, son échéancier et sa rémunération font foi, rien n’est reconduit de la police de base'
                : 'aucune nouvelle piste ni proposition n’est créée',
        )];
        $defauts[] = match (true) {
            $m->annuleLaPolice()               => sprintf('%s à effet du %s.', $m->libelle(), $debut->format('d/m/Y')),
            $m === MouvementAvenant::Prorogation => sprintf('Prorogation de %d jour%s, du %s au %s.', $jours, $jours > 1 ? 's' : '', $debut->format('d/m/Y'), $fin->format('d/m/Y')),
            default                            => sprintf('Nouvelle période du %s au %s%s.', $debut->format('d/m/Y'), $fin->format('d/m/Y'),
                (int) $proposition->getDuree() > 0 ? sprintf(' (durée de %d mois portée par la proposition)', (int) $proposition->getDuree()) : ' (même durée que la police de base)'),
        };
        $defauts[] = $m->annuleLaPolice()
            ? 'La police de base passe au statut « Annulé / résilié » et sort des polices actives.'
            : sprintf('La police de base passe au statut « %s » : son successeur prend le relais parmi les polices actives.', AvenantType::RENEWAL_STATUS_LABELS[$m->statutDeLaBase()]);
        if ($m->poursuitLeCycle()) {
            $defauts[] = 'Une tâche de suivi du paiement est ajoutée.';
        }

        return $defauts;
    }

    /**
     * IDENTITÉ de la police à faire évoluer — indépendante des arguments, donc
     * disponible dès l'ouverture d'une boîte de mouvement, avant toute date saisie.
     *
     * @return array<string, mixed>
     */
    private function source(
        Avenant $base,
        Piste $pisteBase,
        Cotation $cotationBase,
        \DateTimeImmutable $debutBase,
        \DateTimeImmutable $finBase,
    ): array {
        return [
            'avenantId'   => $base->getId(),
            'police'      => $base->getReferencePolice(),
            'client'      => $pisteBase->getClient()?->getNom(),
            'risque'      => $pisteBase->getRisque()?->getNomComplet(),
            'assureur'    => $cotationBase->getAssureur()?->getNom(),
            'periodeBase' => $debutBase->format('d/m/Y') . ' → ' . $finBase->format('d/m/Y'),
            'joursBase'   => $this->joursInclus($debutBase, $finBase),
        ];
    }

    // ------------------------------------------------------------------ période

    /**
     * Période du nouvel avenant. Le renouvellement se déduit de la police de base
     * (sauf période par défaut déjà échue, cf. MouvementAvenant::exigeDate) ; les
     * trois autres mouvements exigent une date de l'utilisateur — c'est la seule
     * information qu'il ait à fournir. Une prise d'effet choisie qui chevaucherait la
     * police de base est refusée (« bloquant »).
     *
     * @return array{debut?: \DateTimeImmutable, fin?: \DateTimeImmutable, jours?: int,
     *               ecarts?: array<int,string>, bloquant?: string, avertissements?: array<int,string>,
     *               aDemander?: array{champ: string, question: string, dateSuggeree?: string}}
     */
    private function periode(MouvementAvenant $m, \DateTimeImmutable $debutBase, \DateTimeImmutable $finBase, array $args, ?Cotation $adoptee = null): array
    {
        $ecarts    = [];
        $lendemain = $this->dateEffetParDefautDepuis($finBase);

        if ($m->annuleLaPolice()) {
            $effet = $this->date($args['dateEffet'] ?? null) ?? $this->date($args['dateDebut'] ?? null);
            if ($effet === null) {
                return ['aDemander' => [
                    'champ'    => 'dateEffet',
                    'question' => sprintf('À quelle date cette %s prend-elle effet ?', mb_strtolower($m->libelle())),
                ]];
            }

            return ['debut' => $effet, 'fin' => $effet, 'jours' => 0, 'ecarts' => $ecarts];
        }

        // `dateEffet` est le nom du champ de la boîte de mouvement, `dateDebut` celui de
        // l'outil de l'assistante : deux noms, une seule information.
        $debut = $this->date($args['dateDebut'] ?? null) ?? $this->date($args['dateEffet'] ?? null);
        $dateFournie = $debut !== null || $this->date($args['dateFin'] ?? null) !== null;
        if ($debut !== null) {
            $controle = $this->controlerPriseDEffet($debut, $finBase, $lendemain);
            if (isset($controle['bloquant'])) {
                return $controle;
            }
            $ecarts = array_merge($ecarts, $controle['ecarts']);
        }
        $debut ??= $lendemain;

        if ($m === MouvementAvenant::Prorogation) {
            $fin   = $this->date($args['dateFin'] ?? null);
            $jours = isset($args['dureeJours']) ? max(1, (int) $args['dureeJours']) : null;
            // CE QUI A ÉTÉ NÉGOCIÉ FAIT FOI : la durée de la proposition adoptée, à défaut
            // d'une durée dictée.
            if ($fin === null && $jours === null && $adoptee !== null) {
                $jours = $this->joursDeLaProposition($adoptee, $debut);
            }
            if ($fin === null && $jours === null) {
                return ['aDemander' => [
                    'champ'    => 'duree',
                    'question' => 'De combien de jours souhaitez-vous proroger cette police (ou jusqu’à quelle date) ?',
                ]];
            }
            $fin ??= $debut->modify(sprintf('+%d days', $jours - 1));
            $jours ??= $this->joursInclus($debut, $fin);

            return ['debut' => $debut, 'fin' => $fin, 'jours' => $jours, 'ecarts' => $ecarts];
        }

        // Renouvellement : même durée que la police de base — sauf proposition adoptée qui
        // porte la sienne : ce qui a été négocié fait foi.
        $fin = $this->date($args['dateFin'] ?? null);
        if ($fin !== null) {
            $ecarts[] = sprintf('Échéance fixée au %s (au lieu d’une durée identique à la police de base).', $fin->format('d/m/Y'));
        }
        $joursAdoptes = $adoptee !== null ? $this->joursDeLaProposition($adoptee, $debut) : null;
        $fin ??= $debut->modify(sprintf('+%d days', ($joursAdoptes ?? $this->joursInclus($debutBase, $finBase)) - 1));

        // LA SEULE QUESTION D'UN RENOUVELLEMENT. Sans date fournie, la période par défaut
        // part du lendemain de l'échéance ; si elle est déjà ÉCHUE, l'avenant naîtrait
        // expiré. Plutôt que de le créer en silence, on demande la date d'effet — en
        // rappelant que le lendemain reste la bonne réponse pour une régularisation.
        if (!$dateFournie && $m->exigeDate(periodeParDefautEchue: $fin < $this->aujourdhui())) {
            return [
                'aDemander' => [
                    'champ'        => 'dateEffet',
                    'question'     => sprintf(
                        'Cette police a expiré le %s : renouvelée au lendemain de son échéance, la nouvelle '
                        . 'période (%s → %s) serait déjà échue. À quelle date le renouvellement prend-il effet ? '
                        . '(Répondez « le %s » s’il s’agit de régulariser une reconduction déjà accordée par '
                        . 'l’assureur.)',
                        $finBase->format('d/m/Y'),
                        $debut->format('d/m/Y'),
                        $fin->format('d/m/Y'),
                        $lendemain->format('d/m/Y'),
                    ),
                    'dateSuggeree' => $lendemain->format(self::FORMAT_DATE),
                ],
                'avertissements' => [$this->avertissementPeriodeEchue($debut, $fin)],
            ];
        }

        return ['debut' => $debut, 'fin' => $fin, 'jours' => $this->joursInclus($debut, $fin), 'ecarts' => $ecarts];
    }

    /**
     * Une prise d'effet choisie par l'utilisateur, confrontée à la fin de la police de base.
     *
     *  - AVANT le lendemain de l'échéance : refusée. Deux polices couvriraient le même
     *    risque sur la même période — une prime payée deux fois, et une commission
     *    réclamée deux fois à l'assureur.
     *  - LE LENDEMAIN : continuité de couverture, aucun écart à signaler.
     *  - APRÈS : acceptée, mais annoncée comme une INTERRUPTION DE COUVERTURE, avec ses
     *    dates : c'est un trou pendant lequel l'assuré n'était pas couvert.
     *
     * @return array{bloquant?: string, ecarts: array<int, string>}
     */
    private function controlerPriseDEffet(\DateTimeImmutable $debut, \DateTimeImmutable $finBase, \DateTimeImmutable $lendemain): array
    {
        $jourDebut     = $debut->setTime(0, 0);
        $jourLendemain = $lendemain->setTime(0, 0);

        if ($jourDebut < $jourLendemain) {
            return ['bloquant' => sprintf(
                'Prise d’effet au %s impossible : la police de base couvre déjà jusqu’au %s inclus. Les deux '
                . 'polices se chevaucheraient du %s au %s. Choisissez une prise d’effet au %s ou plus tard.',
                $jourDebut->format('d/m/Y'),
                $finBase->format('d/m/Y'),
                $jourDebut->format('d/m/Y'),
                $finBase->format('d/m/Y'),
                $jourLendemain->format('d/m/Y'),
            ), 'ecarts' => []];
        }

        if ($jourDebut > $jourLendemain) {
            $trou = $this->joursEntre($jourLendemain, $jourDebut);

            return ['ecarts' => [sprintf(
                'Interruption de couverture de %d jour%s : l’assuré n’est pas couvert du %s au %s (la police de '
                . 'base a expiré le %s, la nouvelle prend effet le %s).',
                $trou,
                $trou > 1 ? 's' : '',
                $jourLendemain->format('d/m/Y'),
                $jourDebut->modify('-1 day')->format('d/m/Y'),
                $finBase->format('d/m/Y'),
                $jourDebut->format('d/m/Y'),
            )]];
        }

        return ['ecarts' => []];
    }

    private function avertissementPeriodeEchue(\DateTimeImmutable $debut, \DateTimeImmutable $fin): string
    {
        return sprintf(
            'La nouvelle période (%s → %s) est déjà échue : cet avenant apparaîtra à son tour dans « Échus ». '
            . 'Si la police a été reprise après une interruption, choisissez une date d’effet plus récente.',
            $debut->format('d/m/Y'),
            $fin->format('d/m/Y'),
        );
    }

    // ------------------------------------------------------------------ champs

    /** @return array<string, mixed> */
    private function champsPiste(
        MouvementAvenant $m,
        Avenant $base,
        Piste $pisteBase,
        Cotation $cotationBase,
        \DateTimeImmutable $debut,
        float $facteur,
    ): array {
        $nom = NomDePisteDerivee::nommer($m->libelle(), $pisteBase->getNom());

        // descriptionDuRisque est NOT NULL : on la reprend, à défaut celle du
        // risque, à défaut le nom de la piste — jamais une question à l'utilisateur.
        $description = $pisteBase->getDescriptionDuRisque()
            ?: $pisteBase->getRisque()?->getDescription()
            ?: $nom;

        $champs = [
            'nom'                 => $nom,
            'descriptionDuRisque' => $description,
            'typeAvenant'         => (string) $m->typeAvenant(),
            'renewalCondition'    => (string) ($pisteBase->getRenewalCondition() ?? Piste::RENEWAL_CONDITION_RENEWABLE),
            'exercice'            => (int) $debut->format('Y'),
            // Lien vers la police que ce mouvement fait évoluer : c'est lui que
            // Constante::Avenant_getRenewalStatus() lit pour afficher « Renouvelé »,
            // « Prorogé » ou « Résilié » sur la police de base.
            'avenantDeBase'       => $base->getId(),
        ];

        if ($pisteBase->getClient()?->getId()) {
            $champs['client'] = $pisteBase->getClient()->getId();
        }
        if ($pisteBase->getRisque()?->getId()) {
            $champs['risque'] = $pisteBase->getRisque()->getId();
        }

        // UN SEUL INTERMÉDIAIRE. Le champ portait une liste d'identifiants ; l'affaire n'en
        // désigne plus qu'un, et le moteur de mutation pose une relation to-one comme un
        // scalaire. Ket doit écrire ce que l'écran écrit — même champ, même forme.
        if ($pisteBase->getPartenaire()?->getId()) {
            $champs['partenaire'] = $pisteBase->getPartenaire()->getId();
        }

        // Conditions de partage au profit d'AGENTS INTERNES : la MÊME condition suit
        // l'affaire, posée par liste d'identifiants — le moteur de mutation sait déjà
        // écrire un ManyToMany de cette façon (c'est ainsi que `partenaires` ci-dessus
        // voyage). La règle vient du service de reconduction, unique pour l'écran et pour
        // Ket : ce qui est reconduit ne peut pas différer d'un chemin à l'autre.
        $conditionsAgent = $this->reconductionPartage->idsConditionsRattachees($pisteBase);
        if ($conditionsAgent !== []) {
            $champs['conditionsPartageAgent'] = $conditionsAgent;
        }

        // Prime / commission potentielles : mêmes sources que le pré-remplissage
        // du formulaire de piste dérivée (PisteController), au facteur près.
        $prime = $this->indicatorHelper->getCotationMontantPrimePayableParClient($cotationBase)
            ?: (float) ($pisteBase->getPrimePotentielle() ?? 0.0);
        $commission = $this->indicatorHelper->getCotationMontantCommissionTtc($cotationBase, -1, false)
            ?: (float) ($pisteBase->getCommissionPotentielle() ?? 0.0);

        $champs['primePotentielle']      = round($prime * $facteur, 2);
        $champs['commissionPotentielle'] = round($commission * $facteur, 2);

        return $champs;
    }

    /** @return array<string, mixed> */
    private function champsCotation(Cotation $cotationBase, float $facteur, MouvementAvenant $m, array $args, array &$ecarts): array
    {
        $dureeBase = (int) ($cotationBase->getDuree() ?? 0);
        // Le prorata est SANS UNITÉ : le facteur s'applique que la durée stockée
        // se compte en mois ou en jours (incohérence préexistante du modèle, que
        // ce calcul n'aggrave pas).
        $duree = $m === MouvementAvenant::Prorogation && $dureeBase > 0
            ? max(1, (int) round($dureeBase * $facteur))
            : $dureeBase;

        $champs = [
            'nom'   => mb_substr((string) $cotationBase->getNom(), 0, 255),
            'duree' => $duree,
            'piste' => '@' . self::REF_PISTE,
        ];

        $assureurId = isset($args['assureurId']) ? (int) $args['assureurId'] : 0;
        if ($assureurId > 0) {
            $champs['assureur'] = $assureurId;
            if ($assureurId !== $cotationBase->getAssureur()?->getId()) {
                $ecarts[] = 'Assureur différent de celui de la police de base.';
            }
        } elseif ($cotationBase->getAssureur()?->getId()) {
            $champs['assureur'] = $cotationBase->getAssureur()->getId();
        }

        return $champs;
    }

    /** @return array<string, mixed> */
    private function champsAvenant(
        MouvementAvenant $m,
        Avenant $base,
        \DateTimeImmutable $debut,
        \DateTimeImmutable $fin,
        int $jours,
        array $args,
        array &$ecarts,
        array &$avertissements,
    ): array {
        $reference = trim((string) ($args['referencePolice'] ?? '')) ?: (string) $base->getReferencePolice();
        if ($reference !== (string) $base->getReferencePolice()) {
            $ecarts[] = sprintf('Référence de police « %s » (au lieu de « %s »).', $reference, (string) $base->getReferencePolice());
        }

        $numero = trim((string) ($args['numero'] ?? ''));
        if ($numero === '') {
            $numero = $this->numeroSuivant((string) $base->getNumero(), $avertissements);
        }

        $description = match ($m) {
            MouvementAvenant::Renouvellement => (string) $base->getDescription(),
            MouvementAvenant::Prorogation    => sprintf('Prorogation de %d jour%s', $jours, $jours > 1 ? 's' : ''),
            default                          => sprintf('%s au %s', $m->libelle(), $debut->format('d/m/Y')),
        };

        $champs = [
            'cotation'        => '@' . self::REF_COTATION,
            'referencePolice' => mb_substr($reference, 0, 255),
            'numero'          => mb_substr($numero, 0, 255),
            'description'     => mb_substr($description ?: $m->libelle(), 0, 255),
            'startingAt'      => $debut->format(self::FORMAT_DATETIME),
            'endingAt'        => $fin->format(self::FORMAT_DATETIME),
        ];

        // L'ACTE D'UNE ANNULATION OU D'UNE RÉSILIATION n'est pas une police qui couvre :
        // né « En cours », il gonflait les polices actives, puis entrait dans « Échus » au
        // lendemain de sa date d'effet en réclamant un renouvellement.
        if ($m->annuleLaPolice()) {
            $champs['renewalStatus'] = (string) Avenant::RENEWAL_STATUS_CANCELLED;
        }

        return $champs;
    }

    /**
     * Numéro d'avenant suivant : incrémenté s'il est purement numérique, sinon
     * reconduit tel quel — avec un avertissement, car un doublon apparent de
     * numéro se voit et doit être annoncé plutôt que subi.
     */
    private function numeroSuivant(string $numeroBase, array &$avertissements): string
    {
        $numeroBase = trim($numeroBase);
        if ($numeroBase !== '' && ctype_digit($numeroBase)) {
            return (string) ((int) $numeroBase + 1);
        }

        if ($numeroBase === '') {
            return '1';
        }

        $avertissements[] = sprintf(
            'Le numéro d’avenant « %s » n’est pas numérique : il est reconduit tel quel. Corrigez-le si votre '
            . 'assureur en attribue un nouveau.',
            $numeroBase,
        );

        return $numeroBase;
    }

    // ------------------------------------------------------------------ collections

    /**
     * TOUTES les conditions de partage de la piste de base, reconduites — une
     * rétrocommission promise à un partenaire ne doit jamais disparaître au
     * passage d'un exercice à l'autre. La règle (quel critère de risque porte le
     * clone) est celle de ReconductionPartageService, partagée avec le formulaire.
     *
     * Chaque condition est un élément de collection de l'opération Piste : elle
     * figure donc dans le plan présenté ET dans le budget en tokens (facturée comme
     * une écriture, via facturablesArbre).
     *
     * @param array<int, string> $avertissements enrichi par référence
     *
     * @return array<int, array<string, mixed>>
     */
    private function conditionsReconduites(Piste $pisteBase, string $etape, array &$avertissements): array
    {
        $elements = [];
        $neutralisees = [];

        foreach ($this->reconductionPartage->champsReconductibles($pisteBase) as $condition) {
            $champs = [
                'nom'           => $condition['nom'],
                'formule'       => (string) $condition['formule'],
                'seuil'         => $condition['seuil'],
                'critereRisque' => (string) $condition['critereRisque'],
            ];
            if ($condition['taux'] !== null) {
                $champs['taux'] = $condition['taux']; // POINTS, recopié brut.
            }
            if ($condition['uniteMesure'] !== null) {
                $champs['uniteMesure'] = (string) $condition['uniteMesure'];
            }
            if ($condition['partenaire']?->getId()) {
                $champs['partenaire'] = $condition['partenaire']->getId();
            }
            // ⚠ LES RISQUES VISÉS NE PEUVENT PAS ENTRER DANS CE PLAN, et il faut dire
            // pourquoi : `ConditionPartage::produits` est déclaré `mapped: false` dans son
            // FormType. Le ciblage ne s'écrit PAS par le formulaire — il passe par deux
            // routes dédiées (`api.attach_risque` / `api.detach_risque`), parce qu'un
            // risque appartient au CATALOGUE de l'entreprise et se vise, ne se crée pas.
            //
            // Les poser ici comme un champ ordinaire faisait refuser tout le plan par la
            // validation (« Cette valeur n'est pas valide »), donc échouer un
            // renouvellement entier pour un ciblage. Le plan reconduit ce qu'un formulaire
            // sait écrire ; le ciblage suit par le chemin qui lui est propre.
            //
            // ET IL SUIT VRAIMENT : une fois le plan écrit, l'abonné de reconduction
            // ({@see \App\EventListener\ReconductionPartageListener}) reconnaît les
            // conditions qui annoncent un ciblage sans en porter aucun, et va chercher
            // leurs risques sur la police de base. Sans lui, la condition arrivait inerte
            // quand elle incluait, et universelle quand elle excluait — l'écran et Ket
            // auraient dit deux choses différentes.

            if (!$condition['applicable']) {
                $neutralisees[] = $condition['nom'];
            }

            $elements[] = ['op' => 'create', 'etape' => $etape, 'champs' => $champs];
        }

        if ($neutralisees !== []) {
            $avertissements[] = sprintf(
                'Condition(s) de partage reconduite(s) mais INACTIVE(S) — elles ne s’appliquaient pas au risque de '
                . 'la police de base : %s. Elles gardent leur ciblage d’origine et restent modifiables sur '
                . 'l’opportunité dérivée.',
                implode(', ', $neutralisees),
            );
        }

        return $elements;
    }

    /** @return array<int, array<string, mixed>> */
    private function chargementsReconduits(
        Cotation $cotationBase,
        float $facteur,
        string $etape,
        array $args,
        array &$ecarts,
        array &$avertissements,
    ): array {
        // Index des types par nom de composante : permet à un écart dicté par
        // l'utilisateur (« la prime nette passe à 12 000 ») de conserver le type
        // de chargement d'origine — sans type, la commission retomberait à 0.
        $typeParNom = [];
        foreach ($cotationBase->getChargements() as $chargement) {
            $cle = mb_strtolower(trim((string) $chargement->getNom()));
            if ($cle !== '' && $chargement->getType()?->getId()) {
                $typeParNom[$cle] = $chargement->getType()->getId();
            }
        }

        $composantes = $args['composantes'] ?? null;
        if (is_array($composantes) && $composantes !== []) {
            $elements = [];
            foreach ($composantes as $composante) {
                if (!is_array($composante) || !isset($composante['nom']) || !array_key_exists('montant', $composante)) {
                    continue;
                }
                $nom    = trim((string) $composante['nom']);
                $champs = ['nom' => $nom, 'montantFlatExceptionel' => round((float) $composante['montant'], 2)];
                $typeId = $typeParNom[mb_strtolower($nom)] ?? null;
                if ($typeId !== null) {
                    $champs['type'] = $typeId;
                } else {
                    $avertissements[] = sprintf(
                        'La composante « %s » n’existe pas sur la police de base : aucun type de chargement ne lui a '
                        . 'été associé, la commission ne se calculera pas dessus.',
                        $nom,
                    );
                }
                $elements[] = ['op' => 'create', 'etape' => $etape, 'champs' => $champs];
            }
            if ($elements !== []) {
                $ecarts[] = 'Composition de la prime dictée par vos soins, en remplacement de celle de la police de base.';

                return $elements;
            }
        }

        $elements = [];
        foreach ($cotationBase->getChargements() as $chargement) {
            $nom    = (string) $chargement->getNom();
            $champs = [
                'nom'                    => $nom !== '' ? $nom : 'Composante',
                'montantFlatExceptionel' => round((float) ($chargement->getMontantFlatExceptionel() ?? 0.0) * $facteur, 2),
            ];
            if ($chargement->getType()?->getId()) {
                $champs['type'] = $chargement->getType()->getId();
            } else {
                $avertissements[] = sprintf(
                    'La composante « %s » de la police de base n’a pas de type de chargement : la commission ne '
                    . 'pourra pas se calculer dessus.',
                    $nom !== '' ? $nom : 'sans nom',
                );
            }
            $elements[] = ['op' => 'create', 'etape' => $etape, 'champs' => $champs];
        }

        return $elements;
    }

    /** @return array<int, array<string, mixed>> */
    private function tranchesReconduites(
        MouvementAvenant $m,
        Cotation $cotationBase,
        \DateTimeImmutable $debutBase,
        \DateTimeImmutable $debut,
        string $etape,
    ): array {
        if ($m === MouvementAvenant::Prorogation) {
            // Une prorogation courte n'a pas d'échéancier : la prime
            // complémentaire est exigible à la prise d'effet, en une fois.
            return [[
                'op'     => 'create',
                'etape'  => $etape,
                'champs' => [
                    'nom'         => 'Prime de prorogation',
                    'pourcentage' => 100, // POINTS : 100 = 100 %.
                    'payableAt'   => $debut->format(self::FORMAT_DATETIME),
                ],
            ]];
        }

        // Renouvellement : l'échéancier suit, décalé du même écart que la période.
        $decalage = $this->joursEntre($debutBase, $debut);
        $elements = [];
        foreach ($cotationBase->getTranches() as $tranche) {
            $champs = ['nom' => (string) ($tranche->getNom() ?? 'Tranche')];
            if ($tranche->getMontantFlat() !== null) {
                $champs['montantFlat'] = $tranche->getMontantFlat();
            }
            if ($tranche->getPourcentage() !== null) {
                $champs['pourcentage'] = $tranche->getPourcentage(); // POINTS, brut.
            }
            if ($tranche->getPayableAt() !== null) {
                $champs['payableAt'] = $tranche->getPayableAt()->modify(sprintf('+%d days', $decalage))->format(self::FORMAT_DATETIME);
            }
            if ($tranche->getEcheanceAt() !== null) {
                $champs['echeanceAt'] = $tranche->getEcheanceAt()->modify(sprintf('+%d days', $decalage))->format(self::FORMAT_DATETIME);
            }
            $elements[] = ['op' => 'create', 'etape' => $etape, 'champs' => $champs];
        }

        return $elements;
    }

    /** @return array<int, array<string, mixed>> */
    private function revenusReconduits(Cotation $cotationBase, float $facteur, string $etape): array
    {
        $elements = [];
        foreach ($cotationBase->getRevenus() as $revenu) {
            $champs = ['nom' => (string) ($revenu->getNom() ?? 'Revenu du courtier')];
            if ($revenu->getTypeRevenu()?->getId()) {
                $champs['typeRevenu'] = $revenu->getTypeRevenu()->getId();
            }
            if ($revenu->getTauxExceptionel() !== null) {
                // Un TAUX ne se proratise pas : il s'applique à une assiette déjà réduite.
                $champs['tauxExceptionel'] = $revenu->getTauxExceptionel();
            }
            if ($revenu->getMontantFlatExceptionel() !== null) {
                // Un FORFAIT, lui, suit la durée couverte.
                $champs['montantFlatExceptionel'] = round((float) $revenu->getMontantFlatExceptionel() * $facteur, 2);
            }
            $elements[] = ['op' => 'create', 'etape' => $etape, 'champs' => $champs];
        }

        return $elements;
    }

    /**
     * Tâche de suivi du paiement de la prime : la boussole du cabinet veut que
     * la commission devienne EXIGIBLE, et elle ne le devient qu'une fois la
     * prime payée par l'assuré. Échéance = exigibilité de la première tranche,
     * à défaut la prise d'effet.
     *
     * @param array<int, array<string, mixed>> $tranches
     *
     * @return array<string, mixed>
     */
    private function tacheDeSuivi(Avenant $base, Piste $pisteBase, array $tranches, \DateTimeImmutable $debut, AiScope $scope): array
    {
        $echeance = null;
        foreach ($tranches as $tranche) {
            $payableAt = $this->date($tranche['champs']['payableAt'] ?? null);
            if ($payableAt !== null && ($echeance === null || $payableAt < $echeance)) {
                $echeance = $payableAt;
            }
        }
        $echeance ??= $debut;

        $client = $pisteBase->getClient()?->getNom();
        $champs = [
            'description' => mb_substr(sprintf(
                'Suivre le paiement de la prime de la police %s%s — c’est ce paiement qui rend la commission exigible.',
                (string) $base->getReferencePolice(),
                $client !== null && $client !== '' ? ' auprès de ' . $client : '',
            ), 0, 255),
            'toBeEndedAt' => $echeance->format(self::FORMAT_DATE),
            'closed'      => false,
        ];
        if ($scope->invite->getId()) {
            $champs['executor'] = $scope->invite->getId();
        }

        return ['op' => 'create', 'etape' => self::ETAPE_TACHE, 'champs' => $champs];
    }

    // ------------------------------------------------------------------ restitution

    /** @return array<int, string> Défauts appliqués, à ANNONCER par l'assistant (jamais à demander). */
    private function defauts(MouvementAvenant $m, \DateTimeImmutable $debut, \DateTimeImmutable $fin, int $jours, float $facteur): array
    {
        if ($m->annuleLaPolice()) {
            return [
                sprintf('%s à effet du %s.', $m->libelle(), $debut->format('d/m/Y')),
                'Aucune prime portée par cet acte : une éventuelle ristourne se traite séparément.',
                'La police de base passe au statut « Annulé / résilié » et sort des polices actives.',
                'Partenaires et conditions de partage reconduits sur l’opportunité dérivée.',
            ];
        }

        $statut = sprintf(
            'La police de base passe au statut « %s » : son successeur prend le relais parmi les polices actives.',
            AvenantType::RENEWAL_STATUS_LABELS[$m->statutDeLaBase()],
        );

        if ($m === MouvementAvenant::Prorogation) {
            return [
                sprintf('Prorogation de %d jour%s, du %s au %s.', $jours, $jours > 1 ? 's' : '', $debut->format('d/m/Y'), $fin->format('d/m/Y')),
                $statut,
                sprintf('Prime recalculée au prorata des jours prorogés (facteur %s).', number_format($facteur, 4, ',', ' ')),
                'Échéancier réduit à une tranche unique, exigible à la prise d’effet.',
                'Même assureur, même référence de police, numéro d’avenant incrémenté.',
                'Partenaires et conditions de partage reconduits ; une tâche de suivi du paiement est ajoutée.',
            ];
        }

        return [
            sprintf('Nouvelle période du %s au %s (même durée que la police de base).', $debut->format('d/m/Y'), $fin->format('d/m/Y')),
            $statut,
            'Même assureur, même référence de police, numéro d’avenant incrémenté.',
            'Prime, composition, échéancier (dates décalées d’autant) et rémunération du courtier reconduits à l’identique.',
            'Partenaires et conditions de partage reconduits ; une tâche de suivi du paiement est ajoutée.',
            'Les tâches et comptes-rendus de la police de base ne sont pas repris.',
        ];
    }

    // ------------------------------------------------------------------ utilitaires

    /** Nombre de jours d'une période, bornes INCLUSES (une police du 1er au 31/12 = 365 j). */
    private function joursInclus(\DateTimeImmutable $debut, \DateTimeImmutable $fin): int
    {
        return $this->joursEntre($debut, $fin) + 1;
    }

    private function joursEntre(\DateTimeImmutable $debut, \DateTimeImmutable $fin): int
    {
        return (int) $debut->diff($fin)->days;
    }

    /** Date tolérante (objet, « 2026-06-15 », « 15/06/2026 »…) ; null si illisible. */
    private function date(mixed $valeur): ?\DateTimeImmutable
    {
        if ($valeur instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($valeur);
        }
        $texte = trim((string) (is_scalar($valeur) ? $valeur : ''));
        if ($texte === '') {
            return null;
        }
        // Format français courant : le convertir avant de laisser PHP interpréter
        // « 15/06/2026 » comme une date américaine.
        if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $texte, $m) === 1) {
            $texte = sprintf('%s-%s-%s', $m[3], $m[2], $m[1]);
        }

        try {
            return new \DateTimeImmutable($texte);
        } catch (\Throwable) {
            return null;
        }
    }
}
