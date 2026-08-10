<?php

namespace App\Ai\Proposition;

use App\Ai\Resolution\ResolveurDeReferences;
use App\Ai\Scope\AiScope;
use App\Entity\Invite;
use App\Entity\Piste;
use App\Entity\Risque;
use App\Entity\TypeRevenu;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Services\JSBDynamicSearchService;
use App\Util\Pourcentage;

/**
 * LA RÉMUNÉRATION DU COURTIER, DÉRIVÉE DE LA CONFIGURATION — jamais demandée, jamais
 * inventée.
 *
 * POURQUOI CE SERVICE EXISTE. Il n'existe pas de proposition d'assurance sans
 * commission de courtage : c'est la raison d'être de l'affaire. Or SaisirPropositionTool
 * ne créait le revenu que si l'entreprise n'avait qu'UN SEUL type de revenu — condition
 * jamais remplie, puisque ServiceInitialisationEntreprise en installe QUATRE à la
 * création de chaque entreprise. Conséquence observée sur les données réelles : toute
 * proposition partait sans revenu, et l'écran l'annonçait franchement (« Rien ne sera
 * enregistré pour : Revenus »). Une cotation sans revenu, c'est un chiffre d'affaires,
 * une part partenaire et des taxes sur commission à zéro.
 *
 * CE QU'IL NE STOCKE PAS, ET C'EST L'ESSENTIEL. Le taux n'est PAS recopié dans le
 * revenu. La cascade de {@see \App\Constantes\Constante::Revenu_getMontant_ht()} le
 * résout à la LECTURE : un type portant `appliquerPourcentageDuRisque` va chercher le
 * pourcentage prescrit par le risque de l'opportunité. Créer le revenu avec son seul
 * `typeRevenu` suffit donc — et la commission suivra le risque si son taux change
 * demain. Un `tauxExceptionel` n'est écrit que si l'utilisateur DÉROGE explicitement.
 *
 * CE QUI EST AJOUTÉ D'OFFICE : les types dont le REDEVABLE est l'assureur et dont le
 * chargement cible figure dans la composition dictée. Deux conditions, deux raisons :
 *  - l'assiette doit exister, sinon la commission vaut 0 par construction (« Commission
 *    sur Fronting » n'a de sens que si une ligne Fronting a été dictée) ;
 *  - ce que l'assureur doit au courtier est ACQUIS avec l'affaire, alors que les frais
 *    payés par le CLIENT (consultance, honoraires de gestion) se négocient au cas par
 *    cas — les présumer facturerait le client à son insu.
 *
 * ON NE DEVINE JAMAIS UN TAUX. Un risque sans pourcentage prescrit, ou un type sans
 * taux ni forfait, devient une QUESTION nommant précisément ce qui manque — pas une
 * ligne écrite à 0, qui se lirait plus tard comme une affaire sans commission.
 *
 * FAIL-CLOSED : hors droit de LECTURE de l'invité sur les types de revenus, rien n'est
 * dérivé — et l'omission est dite, jamais silencieuse.
 */
final class RevenuCourtierPrescrit
{
    /**
     * Un référentiel de types de revenus est une liste de configuration, pas un
     * gisement : au-delà, l'entreprise a un problème de paramétrage, pas nous.
     */
    private const MAX_TYPES = 50;

    public function __construct(
        private readonly JSBDynamicSearchService $searchService,
        private readonly WorkspaceAccessResolver $accessResolver,
        // Pour sa NORMALISATION, source unique du projet : les noms en base ne
        // correspondent pas au caractère près à ceux que le code déclare.
        private readonly ResolveurDeReferences $resolveur,
    ) {
    }

    /**
     * Les revenus à créer avec la proposition, prêts à entrer dans la collection
     * `revenus` du plan.
     *
     * @param int|null           $pisteId      opportunité de la proposition (porte le risque)
     * @param array<int, float>  $assiettes    chargementId => montant dicté
     * @param int|null           $idPrimeNette chargement servant de base de commission
     * @param float|null         $tauxDicte    taux exceptionnel dicté, en POINTS
     * @param string             $etape        libellé d'étape de la trame
     *
     * @return array{
     *     elements: array<int, array<string, mixed>>,
     *     defauts: array<int, string>,
     *     aDemander: array<int, array<string, mixed>>,
     *     avertissements: array<int, string>
     * }
     */
    public function deriver(
        ?int $pisteId,
        array $assiettes,
        ?int $idPrimeNette,
        ?float $tauxDicte,
        string $etape,
        AiScope $scope,
    ): array {
        $vide = ['elements' => [], 'defauts' => [], 'aDemander' => [], 'avertissements' => []];

        // FAIL-CLOSED, et DIT. Sans droit de lecture sur les types de revenus, on ne
        // peut pas dériver la rémunération — mais se taire laisserait croire que
        // l'entreprise n'en a pas configuré.
        if (!$this->accessResolver->can($scope->invite, 'TypeRevenu', Invite::ACCESS_LECTURE)) {
            return ['avertissements' => [
                'La rémunération du courtier n’a pas pu être ajoutée : les types de revenus sont hors de '
                . 'votre périmètre de lecture. Demandez ce droit, ou dictez la commission explicitement.',
            ]] + $vide;
        }

        if ($assiettes === []) {
            return $vide;
        }

        $types = $this->typesCandidats($assiettes, $scope);
        if ($types === []) {
            return ['avertissements' => [$this->messageAucunType($scope)]] + $vide;
        }

        $risque = $this->risqueDeLaPiste($pisteId, $scope);

        $elements = [];
        $defauts = [];
        $aDemander = [];

        foreach ($types as $type) {
            $chargementId = (int) $type->getTypeChargement()?->getId();
            $assiette = (float) ($assiettes[$chargementId] ?? 0.0);
            $baseLibelle = (string) $type->getTypeChargement()?->getNom();

            // Le taux dicté ne s'applique qu'à la BASE DE COMMISSION (la prime nette) :
            // l'appliquer aussi au fronting reviendrait à étendre une dérogation que
            // l'utilisateur n'a pas prononcée. Quand un seul type est retenu, il est
            // cette base par défaut.
            $porteLeTauxDicte = $tauxDicte !== null
                && ($chargementId === $idPrimeNette || count($types) === 1);

            if ($porteLeTauxDicte) {
                $elements[] = $this->element($type, $etape, $tauxDicte);
                $defauts[] = sprintf(
                    'Rémunération du courtier : « %s » — taux exceptionnel dicté de %s%s.',
                    (string) $type->getNom(),
                    Pourcentage::fromPourcent($tauxDicte)->format(2),
                    $this->suffixeMontant(Pourcentage::fromPourcent($tauxDicte), $assiette, $baseLibelle),
                );
                continue;
            }

            $prescription = $this->prescription($type, $risque);
            if ($prescription === null) {
                $aDemander[] = $this->questionTaux($type, $risque);
                continue;
            }

            $elements[] = $this->element($type, $etape, null);
            $defauts[] = sprintf(
                'Rémunération du courtier : « %s » — %s%s.',
                (string) $type->getNom(),
                $prescription['libelle'],
                $prescription['taux'] instanceof Pourcentage
                    ? $this->suffixeMontant($prescription['taux'], $assiette, $baseLibelle)
                    : '',
            );
        }

        // Un taux dicté qui ne se place sur aucun type n'est pas un détail : on le dit
        // plutôt que de l'appliquer au hasard ou de le perdre en silence, comme le
        // faisait le code d'avant.
        if ($tauxDicte !== null && $elements !== [] && !$this->contientTauxExceptionnel($elements)) {
            $aDemander[] = [
                'champ'    => 'tauxCommissionPercent',
                'libelle'  => 'Taux de commission exceptionnel',
                'probleme' => 'destinataire_indetermine',
                'question' => sprintf(
                    'Vous avez dicté un taux de commission de %s, mais plusieurs revenus s’appliquent à '
                    . 'cette proposition (%s) et la prime nette n’a pas été identifiée dans la composition. '
                    . 'À quel revenu ce taux s’applique-t-il ?',
                    Pourcentage::fromPourcent($tauxDicte)->format(2),
                    implode(', ', array_map(static fn (TypeRevenu $t) => (string) $t->getNom(), $types)),
                ),
            ];
        }

        return [
            'elements'       => $aDemander === [] ? $elements : [],
            'defauts'        => $aDemander === [] ? $defauts : [],
            'aDemander'      => $aDemander,
            'avertissements' => [],
        ];
    }

    /**
     * Les types de revenus ajoutables d'office : payés par l'ASSUREUR, et assis sur un
     * chargement réellement présent dans la composition.
     *
     * @param array<int, float> $assiettes
     *
     * @return array<int, TypeRevenu>
     */
    private function typesCandidats(array $assiettes, AiScope $scope): array
    {
        $candidats = [];
        foreach ($this->tousLesTypes($scope) as $type) {
            if ($this->estTypeSysteme($type)) {
                continue;
            }
            $chargementId = $type->getTypeChargement()?->getId();
            if ($chargementId === null || !array_key_exists((int) $chargementId, $assiettes)) {
                continue;
            }
            if ($type->getRedevable() !== TypeRevenu::REDEVABLE_ASSUREUR) {
                continue;
            }
            $candidats[] = $type;
        }

        return $candidats;
    }

    /**
     * Le type d'AJUSTEMENT est un artefact de la mécanique d'écart de commission : il ne
     * se pose jamais sur une proposition neuve.
     *
     * COMPARAISON NORMALISÉE, et ce n'est pas de la prudence gratuite. Sur les données
     * réelles, la ligne s'appelle « [Ajustement système - écart commission] » quand la
     * constante déclare « … - Écart commission » : une majuscule d'écart, et le type
     * système passait le filtre. Comme il est dû par l'assureur, assis sur la prime nette
     * et sans taux, il déclenchait la question « quel taux appliquer ? » — l'outil
     * réclamait un taux pour un type qui n'aurait jamais dû être candidat.
     */
    private function estTypeSysteme(TypeRevenu $type): bool
    {
        return $this->resolveur->normaliser((string) $type->getNom())
            === $this->resolveur->normaliser(TypeRevenu::SYSTEM_ADJUSTMENT_REVENU_NAME);
    }

    /** @return array<int, TypeRevenu> tous les types de l'entreprise, scopés */
    private function tousLesTypes(AiScope $scope): array
    {
        $resultat = $this->searchService->search(
            TypeRevenu::class,
            [],
            $scope->entreprise,
            null,
            1,
            self::MAX_TYPES,
        );
        if (($resultat['status']['code'] ?? 500) !== 200) {
            return [];
        }

        return array_values(array_filter(
            $resultat['data'] ?? [],
            static fn ($t) => $t instanceof TypeRevenu,
        ));
    }

    /**
     * Le risque de l'opportunité — c'est lui qui PRESCRIT le taux de commission.
     * Chargement scopé à l'entreprise, comme partout ailleurs.
     */
    private function risqueDeLaPiste(?int $pisteId, AiScope $scope): ?Risque
    {
        if ($pisteId === null || $pisteId <= 0) {
            return null;
        }

        $resultat = $this->searchService->search(Piste::class, ['id' => $pisteId], $scope->entreprise, null, 1, 1);
        if (($resultat['status']['code'] ?? 500) !== 200) {
            return null;
        }

        foreach ($resultat['data'] ?? [] as $piste) {
            if ($piste instanceof Piste) {
                return $piste->getRisque();
            }
        }

        return null;
    }

    /**
     * Ce que la configuration PRESCRIT pour ce type, dans l'ordre exact de la cascade
     * de Constante::Revenu_getMontant_ht() — source unique du calcul réel. Renvoie
     * null quand rien n'est prescrit : c'est alors une question, pas un défaut.
     *
     * @return array{libelle: string, taux: ?Pourcentage}|null
     */
    private function prescription(TypeRevenu $type, ?Risque $risque): ?array
    {
        if ($type->isAppliquerPourcentageDuRisque() === true) {
            $taux = Pourcentage::fromPourcent($risque?->getPourcentageCommissionSpecifiqueHT());
            if ($risque === null || $taux->estNul()) {
                return null;
            }

            return [
                'libelle' => sprintf(
                    '%s (taux prescrit par le risque « %s »)',
                    $taux->format(2),
                    (string) $risque->getNomComplet(),
                ),
                'taux' => $taux,
            ];
        }

        $taux = Pourcentage::fromPourcent($type->getPourcentage());
        if (!$taux->estNul()) {
            return ['libelle' => sprintf('%s (taux habituel du type)', $taux->format(2)), 'taux' => $taux];
        }

        $forfait = (float) ($type->getMontantflat() ?? 0.0);
        if ($forfait !== 0.0) {
            return ['libelle' => sprintf('montant forfaitaire de %s', $this->nombre($forfait)), 'taux' => null];
        }

        return null;
    }

    /**
     * La question à poser quand rien n'est prescrit. Elle NOMME le type, le risque et
     * l'endroit où le taux se configure — « il manque une information » ne se corrige
     * pas, une phrase précise si.
     *
     * @return array<string, mixed>
     */
    private function questionTaux(TypeRevenu $type, ?Risque $risque): array
    {
        $question = $type->isAppliquerPourcentageDuRisque() === true
            ? ($risque === null
                ? sprintf(
                    'Le revenu « %s » se calcule sur le taux du risque, mais l’opportunité de cette '
                    . 'proposition n’a pas de risque renseigné. Quel taux de commission appliquer (en %%) ?',
                    (string) $type->getNom(),
                )
                : sprintf(
                    'Le risque « %s » ne prescrit aucun taux de commission, et le revenu « %s » s’y adosse. '
                    . 'Quel taux appliquer à cette proposition (en %%) ? Il peut aussi être renseigné une '
                    . 'fois pour toutes sur la fiche du risque (« %% commission spécifique HT »).',
                    (string) $risque->getNomComplet(),
                    (string) $type->getNom(),
                ))
            : sprintf(
                'Le type de revenu « %s » ne porte ni taux ni montant forfaitaire : la commission du '
                . 'courtier resterait à 0. Quel taux appliquer à cette proposition (en %%) ?',
                (string) $type->getNom(),
            );

        return [
            'champ'    => 'tauxCommissionPercent',
            'libelle'  => 'Taux de commission du courtier',
            'probleme' => 'non_prescrit',
            'question' => $question,
        ];
    }

    /**
     * Le revenu tel qu'il entrera dans la collection `revenus` du plan : le NOM et le
     * TYPE, rien de plus. Champs conformes à RevenuPourCourtierType — le plan traverse
     * ce formulaire, c'est la parité avec l'écran.
     *
     * @return array<string, mixed>
     */
    private function element(TypeRevenu $type, string $etape, ?float $tauxDicte): array
    {
        $champs = ['nom' => (string) $type->getNom(), 'typeRevenu' => (int) $type->getId()];
        if ($tauxDicte !== null) {
            // Convention unique de la plateforme : les taux sont des POINTS (16 = 16 %).
            $champs['tauxExceptionel'] = $tauxDicte;
        }

        return ['op' => 'create', 'etape' => $etape, 'champs' => $champs];
    }

    /** @param array<int, array<string, mixed>> $elements */
    private function contientTauxExceptionnel(array $elements): bool
    {
        foreach ($elements as $element) {
            if (isset($element['champs']['tauxExceptionel'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * « … sur la prime nette de 1 000, soit 150 » : le montant attendu, calculé par le
     * VO Pourcentage (jamais un ×taux/100 à la main). Rien n'est annoncé quand
     * l'assiette est absente — un chiffre faux vaut moins que pas de chiffre.
     */
    private function suffixeMontant(Pourcentage $taux, float $assiette, string $baseLibelle): string
    {
        if ($assiette <= 0.0) {
            return '';
        }

        return sprintf(
            ' sur %s de %s, soit %s',
            $baseLibelle !== '' ? sprintf('« %s »', $baseLibelle) : 'l’assiette',
            $this->nombre($assiette),
            $this->nombre($taux->appliquerA($assiette)),
        );
    }

    private function nombre(float $valeur): string
    {
        return number_format($valeur, 2, ',', ' ');
    }

    /** Aucun type ajoutable : on nomme ce qui existe, pour que l'utilisateur puisse trancher. */
    private function messageAucunType(AiScope $scope): string
    {
        $noms = [];
        foreach ($this->tousLesTypes($scope) as $type) {
            if (!$this->estTypeSysteme($type)) {
                $noms[] = (string) $type->getNom();
            }
        }

        if ($noms === []) {
            return 'Aucune rémunération du courtier n’a été ajoutée : cette entreprise n’a encore aucun type '
                . 'de revenu configuré. Créez-en un (rubrique « Types Revenus ») pour que les commissions '
                . 'se calculent.';
        }

        return sprintf(
            'Aucune rémunération du courtier n’a été ajoutée d’office : aucun type de revenu payé par '
            . 'l’assureur ne s’adosse aux composantes de prime dictées. Les types configurés sont : %s. '
            . 'Précisez lequel s’applique, s’il y en a un.',
            implode(', ', $noms),
        );
    }
}
