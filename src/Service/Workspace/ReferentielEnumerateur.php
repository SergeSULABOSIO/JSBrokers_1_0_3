<?php

namespace App\Service\Workspace;

use App\Ai\FicheNormaliseur;
use App\Ai\Scope\AiScope;
use App\Ai\Tool\EntiteLibelle;
use App\Entity\Invite;
use App\Services\JSBDynamicSearchService;

/**
 * ÉNUMÈRE les valeurs d'un référentiel, scopées à l'entreprise de l'espace de travail.
 *
 * Une relation obligatoire ne se remplit pas si l'on ignore ce qu'elle peut contenir.
 * L'inventaire des champs annonçait « risque : Risque / Couverture » sans jamais dire
 * quels risques existent — l'assistant devait le deviner, et laissait donc le champ vide.
 * Ce service fournit la liste quand elle est COURTE ASSEZ pour être lue, et se tait
 * quand elle ne l'est pas : sur une entreprise à 300 assureurs, énumérer noierait le
 * contexte, et un renvoi vers la recherche est plus utile qu'une liste tronquée.
 *
 * Deux niveaux de détail, une seule source :
 *  - {@see codes()}   : `id => libellé`, pour l'inventaire des champs (compact) ;
 *  - {@see enrichi()} : chaque valeur AVEC ses attributs, pour un parcours de saisie
 *    où l'étape est indécidable sans eux (choisir un type de revenu suppose de voir son
 *    taux, son mode de calcul et son redevable, pas seulement son nom).
 *
 * FAIL-CLOSED : hors droit de LECTURE de l'invité sur l'entité cible, rien n'est
 * énuméré. Le scoping entreprise est délégué à {@see JSBDynamicSearchService} — jamais
 * un agrégat global.
 */
class ReferentielEnumerateur
{
    /**
     * Au-delà de ce nombre d'entrées, un référentiel n'est plus une liste de choix :
     * on renvoie l'appelant vers la recherche plutôt que d'en montrer une part
     * arbitraire, qui se lirait comme l'ensemble.
     */
    public const PLAFOND_ENUMERATION = 25;

    /** Nombre maximal de valeurs restituées avec leurs attributs (payload plus lourd). */
    public const MAX_VALEURS_ENRICHIES = 30;

    /**
     * Cache de requête, par entité ET par entreprise (le scoping fait partie de la clé :
     * deux espaces de travail n'ont pas le même référentiel).
     *
     * Nécessaire, pas cosmétique : l'inventaire des champs interroge le référentiel de
     * CHAQUE relation d'une entité, et un parcours de saisie appelle l'inventaire une
     * fois par étape. Sans cache, une seule question de l'utilisateur déclenchait
     * plusieurs dizaines de recherches identiques.
     *
     * Portée : la REQUÊTE (le service est reconstruit à chaque appel HTTP). Une entrée
     * créée puis énumérée dans la même requête pourrait donc manquer — cas qui ne se
     * présente pas aujourd'hui, l'énumération servant les outils de LECTURE et
     * l'exécution d'un plan se faisant dans une requête distincte.
     *
     * @var array<string, array<int, string>|null>
     */
    private array $cache = [];

    public function __construct(
        private readonly WorkspaceAccessResolver $accessResolver,
        private readonly JSBDynamicSearchService $searchService,
        private readonly FicheNormaliseur $ficheNormaliseur,
        private readonly EntiteLibelle $entiteLibelle,
    ) {
    }

    /**
     * Valeurs d'un référentiel en `id => libellé`, ou `null` si l'énumération n'aboutit
     * pas (l'appelant doit alors renvoyer vers `rechercher_entites`).
     *
     * Le `null` et le tableau vide sont deux réponses DISTINCTES :
     *  - `[]`   : la recherche a RÉUSSI et ce référentiel n'a aucune entrée dans cette
     *             entreprise — il faut en créer une ;
     *  - `null` : on ne peut pas conclure — trop d'entrées pour les lister, entité hors
     *             périmètre de lecture, ou recherche en erreur.
     *
     * Les confondre ferait dire « aucun assureur » là où il y en a trois cents, ou là où
     * on n'a simplement pas eu le droit de regarder.
     *
     * @return array<int, string>|null
     */
    public function codes(string $entite, AiScope $scope, int $plafond = self::PLAFOND_ENUMERATION): ?array
    {
        $cle = sprintf('%s|%d|%d', $entite, $scope->entreprise->getId() ?? 0, $plafond);
        if (array_key_exists($cle, $this->cache)) {
            return $this->cache[$cle];
        }

        return $this->cache[$cle] = $this->chercherCodes($entite, $scope, $plafond);
    }

    /** @return array<int, string>|null */
    private function chercherCodes(string $entite, AiScope $scope, int $plafond): ?array
    {
        // Pas de classe, ou pas le droit de LIRE : on ne peut pas énumérer. On répond
        // « indéterminé » (null) et non « vide » — affirmer qu'un référentiel est vide
        // alors qu'on n'a pas pu le regarder est un mensonge, et l'assistant en tirerait
        // « il n'y a aucun assureur » là où il y en a peut-être trois cents.
        $fqcn = $this->fqcnLisible($entite, $scope);
        if ($fqcn === null) {
            return null;
        }

        // On demande UNE entrée de plus que le plafond : si elle revient, c'est qu'il y
        // en a trop — sans avoir à faire confiance au totalItems de la recherche.
        $result = $this->searchService->search($fqcn, [], $scope->entreprise, null, 1, $plafond + 1);
        // Recherche refusée (entité hors de son allowlist) ou en erreur : indéterminé
        // aussi, pour la même raison. Seule une recherche RÉUSSIE peut conclure « vide ».
        if (($result['status']['code'] ?? 500) !== 200) {
            return null;
        }
        $data = $result['data'] ?? [];
        if (count($data) > $plafond || (int) ($result['totalItems'] ?? 0) > $plafond) {
            return null;
        }

        $displayField = $this->displayField($fqcn);
        $codes = [];
        foreach ($data as $item) {
            if (!is_object($item) || !method_exists($item, 'getId')) {
                continue;
            }
            $libelle = trim(strip_tags($this->entiteLibelle->libelle($item, $displayField)));
            if ($libelle !== '') {
                $codes[(int) $item->getId()] = $libelle;
            }
        }

        return $codes;
    }

    /**
     * Valeurs d'un référentiel AVEC leurs attributs (stockés et calculés) : ce qui
     * permet de CHOISIR, et pas seulement de nommer. « Le revenu selon le taux relatif
     * au risque » ne se résout pas sur une liste de libellés — il faut voir le taux, le
     * mode de calcul, le chargement de base et le redevable de chaque type. Avec un nom
     * seul, l'étape est indécidable, et une étape indécidable finit abandonnée.
     *
     * @return array<int, array{id: int, nom: string, attributs: array}>
     */
    public function enrichi(string $entite, AiScope $scope, int $max = self::MAX_VALEURS_ENRICHIES): array
    {
        $fqcn = $this->fqcnLisible($entite, $scope);
        if ($fqcn === null) {
            return [];
        }

        $result = $this->searchService->search($fqcn, [], $scope->entreprise, null, 1, $max);
        if (($result['status']['code'] ?? 500) !== 200) {
            return [];
        }

        $displayField = $this->displayField($fqcn);
        $valeurs = [];
        foreach ($result['data'] ?? [] as $item) {
            if (!is_object($item) || !method_exists($item, 'getId')) {
                continue;
            }
            $nom = trim(strip_tags($this->entiteLibelle->libelle($item, $displayField)));
            if ($nom === '') {
                continue;
            }
            $attributs = $this->ficheNormaliseur->ficheEnrichie($item);
            unset($attributs['id'], $attributs['nom']); // déjà portés par l'entrée.

            $valeurs[] = ['id' => (int) $item->getId(), 'nom' => $nom, 'attributs' => $attributs];
        }

        return $valeurs;
    }

    /** FQCN de l'entité si elle existe ET que l'invité a le droit de la LIRE, null sinon. */
    private function fqcnLisible(string $entite, AiScope $scope): ?string
    {
        $fqcn = 'App\\Entity\\' . $entite;

        return class_exists($fqcn) && $this->accessResolver->can($scope->invite, $entite, Invite::ACCESS_LECTURE)
            ? $fqcn
            : null;
    }

    private function displayField(string $fqcn): ?string
    {
        try {
            return $this->entiteLibelle->displayField($fqcn);
        } catch (\Throwable) {
            return null;
        }
    }
}
