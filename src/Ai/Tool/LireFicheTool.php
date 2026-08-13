<?php

namespace App\Ai\Tool;

use App\Ai\AiText;
use App\Ai\FicheNormaliseur;
use App\Ai\Mutation\MutationAllowlist;
use App\Ai\Scope\AiScope;
use App\Service\Workspace\ChampsObligatoiresInspector;
use App\Service\Workspace\FormTreeInspector;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Services\JSBDynamicSearchService;
use App\Token\TokenAccountService;

/**
 * Lit la FICHE COMPLÈTE d'un enregistrement (attributs stockés) : là où
 * rechercher_entites ne rend que l'identifiant et le libellé, cet outil répond
 * à « quelle est l'adresse du client X ? », « quel est le statut de l'avenant
 * Y ? »… Sérialisation `list:read` (le même contrat que les listes et le
 * contexte de dialogue), élaguée des valeurs vides pour maîtriser les tokens.
 * Les valeurs CALCULÉES (prime totale…) restent du ressort d'indicateur_calcule.
 *
 * Résolution par id ou par nom ; un nom ambigu renvoie les candidats (id +
 * libellé) pour que le modèle demande précision. FAIL-CLOSED : canRead par
 * entité, scoping entreprise via JSBDynamicSearchService.
 */
final class LireFicheTool implements AiToolInterface
{
    /** Nombre maximal de candidats restitués sur un nom ambigu. */
    private const MAX_CANDIDATS = 6;

    public function __construct(
        private readonly WorkspaceAccessResolver $accessResolver,
        private readonly JSBDynamicSearchService $searchService,
        private readonly EntiteLexique $lexique,
        private readonly EntiteLibelle $libelleur,
        private readonly FicheNormaliseur $ficheNormaliseur,
        private readonly FormTreeInspector $formTreeInspector,
        private readonly TokenAccountService $tokenAccountService,
        private readonly ChampsObligatoiresInspector $champsInspector,
    ) {
    }

    public function name(): string
    {
        return 'lire_fiche';
    }

    public function description(): string
    {
        return "Lit la fiche complète d'un enregistrement précis : ses attributs enregistrés ET ses "
            . 'attributs dérivés lisibles (libellés des champs codés — « Redevable : Assureur » plutôt '
            . 'que « 1 » —, mode de calcul, taux en clair…), plus le bloc « unites » quand un champ se '
            . 'saisit en pourcentage mais se stocke en fraction. Cible par id (fourni par '
            . 'rechercher_entites) ou par nom. À appeler quand l’utilisateur demande le détail ou un '
            . 'attribut d’une fiche précise, OU avant d’écrire pour comprendre un champ codé. Pour un '
            . 'total CALCULÉ (prime, commission agrégée…), utiliser indicateur_calcule.';
    }

    public function aiguillage(): string
    {
        return 'Le détail ou un attribut d\'UNE fiche précise, quand tu connais l\'enregistrement visé.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'entite' => [
                    'type' => 'string',
                    'description' => "Nom court de l'entité (ex. Client, Avenant, Assureur).",
                    'enum' => $this->lexique->nomsCourts(),
                ],
                'id' => [
                    'type' => 'integer',
                    'description' => "Identifiant de l'enregistrement (prioritaire sur nom).",
                ],
                'nom' => [
                    'type' => 'string',
                    'description' => "Nom (ou partie du nom) de l'enregistrement, si l'id est inconnu.",
                ],
            ],
            'required' => ['entite'],
        ];
    }

    /**
     * Chemin simulé : « détails / fiche / adresse… du <entité> <nom> ». Le nom
     * est capturé après le mot-clé d'entité (le LLM réel, lui, passe id ou nom
     * en argument structuré).
     */
    public function match(string $question, AiScope $scope): ?array
    {
        $normalized = AiText::normalize($question);
        if (!preg_match('/\b(details?|fiche|coordonnees?|adresse|telephones?|e?mails?|informations?)\b/', $normalized)) {
            return null;
        }
        // Le paiement d'une PRIME a son outil dédié : sans cette garde, « les informations
        // du paiement de prime… » lisait une fiche de la rubrique Paiements (trésorerie).
        if (PaiementPrimeIntent::concerne($normalized)) {
            return null;
        }

        $shortName = $this->lexique->matchEntite($normalized);
        if ($shortName === null) {
            return null;
        }

        foreach ($this->lexique->lexique()[$shortName] as $keyword) {
            if (preg_match('/\b' . preg_quote($keyword, '/') . '\s+(?:de |du |de la |d )?(.{2,60}?)(?:\s*\?|$)/', $normalized, $m)) {
                return ['entite' => $shortName, 'nom' => trim($m[1])];
            }
        }

        return null;
    }

    public function execute(array $args, AiScope $scope): AiToolResult
    {
        $shortName = (string) ($args['entite'] ?? '');
        $labels = $this->accessResolver->libellesEntites();
        $fqcn = 'App\\Entity\\' . $shortName;
        if (!isset($labels[$shortName]) || !class_exists($fqcn)) {
            return AiToolResult::introuvable($shortName);
        }

        // FAIL-CLOSED : la fiche complète est une lecture comme une autre.
        if (!$this->accessResolver->canRead($scope->invite, $shortName)) {
            return AiToolResult::horsPerimetre($labels[$shortName]);
        }

        $id = (int) ($args['id'] ?? 0);
        $nom = trim((string) ($args['nom'] ?? ''));
        $displayField = $this->libelleur->displayField($fqcn);

        if ($id > 0) {
            $criteria = ['id' => $id];
        } elseif ($nom !== '' && $displayField !== null) {
            $criteria = [$displayField => ['operator' => 'LIKE', 'value' => $nom, 'mode' => 'contains']];
        } else {
            return AiToolResult::introuvable($labels[$shortName]);
        }

        $result = $this->searchService->search($fqcn, $criteria, $scope->entreprise, null, 1, self::MAX_CANDIDATS);
        if (($result['status']['code'] ?? 500) !== 200) {
            return AiToolResult::introuvable($labels[$shortName]);
        }

        $entities = $result['data'];
        if ($entities === []) {
            return AiToolResult::introuvable(sprintf('%s « %s »', $labels[$shortName], $nom !== '' ? $nom : '#' . $id));
        }

        // Nom ambigu : rendre les candidats, le modèle demandera précision.
        if (count($entities) > 1) {
            return AiToolResult::ok([
                'entite'    => $shortName,
                'libelle'   => $labels[$shortName],
                'ambigu'    => true,
                'candidats' => array_map(
                    fn (object $e) => ['id' => $e->getId(), 'libelle' => $this->libelleur->libelle($e, $displayField)],
                    $entities,
                ),
            ]);
        }

        $entity = $entities[0];

        $data = [
            'entite'  => $shortName,
            'libelle' => $labels[$shortName],
            'id'      => $entity->getId(),
            'nom'     => $this->libelleur->libelle($entity, $displayField),
            // Lecture CIBLÉE d'un seul enregistrement : on y joint les attributs
            // CALCULÉS de l'entité. C'est le seul endroit où Ket peut comprendre
            // un champ codé (« redevable: 1 » => « Redevable : Assureur ») ou un
            // taux (« 0.15 » => « 15 % »). Sans cela, elle lit sans comprendre —
            // et une entité incomprise est une étape qu'elle finit par ignorer.
            'fiche'   => $this->ficheNormaliseur->ficheEnrichie($entity),
        ];

        // Unités trompeuses : un taux stocké en FRACTION (0.15) s'écrit en
        // POURCENTAGE (15). Le dire explicitement au moment de la LECTURE, c'est
        // empêcher la recopie à l'identique — qui diviserait le taux par 100.
        $unites = $this->unitesPourcentage($shortName, $data['fiche']);
        if ($unites !== []) {
            $data['unites'] = $unites;
        }

        // LES NOMS EXACTS DES CHAMPS, PARCE QUE LA FICHE NE LES DONNE PAS TOUS.
        //
        // L'INCIDENT DU 2026-08-13, dans son fond. La fiche est sérialisée puis
        // ÉLAGUÉE DE SES VALEURS VIDES, pour ne pas payer des tokens à énumérer des
        // nuls. Conséquence mécanique : le champ qu'on veut RENSEIGNER est justement
        // celui qui est vide, donc celui dont le nom n'apparaît nulle part. Ket lisait
        // la fiche du risque, n'y voyait aucun taux, et devait inventer un nom pour
        // l'écrire — « tauxCommission », puis « tauxCommissionPercent », quand le champ
        // s'appelle « pourcentageCommissionSpecifiqueHT ». Chaque tentative était
        // écartée, et le plan partait vide.
        //
        // On donne donc les noms AVANT qu'elle ait à les deviner, et seulement pour ce
        // qu'elle peut réellement écrire. Les libellés viennent du FORMULAIRE de
        // l'entité (source unique, mise en cache) : c'est la même parité que partout
        // ailleurs — ce que l'écran expose, Ket l'écrit, sous le même nom.
        $modifiables = $this->champsModifiables($shortName);
        if ($modifiables !== []) {
            $data['champsModifiables'] = $modifiables;
        }

        // Membres des collections ÉDITABLES (mêmes que la surface d'écriture de Ket) :
        // exposés avec leur id pour cibler edit/delete. Facturés en LECTURE.
        $collections = $this->collectionsEditablesLisibles($entity, $shortName, $scope);
        if ($collections !== []) {
            $data['collectionsEditables'] = $collections;
        }

        return AiToolResult::ok($data);
    }

    /**
     * Pour les champs de la fiche saisis en POURCENTAGE mais stockés en FRACTION,
     * restitue la valeur telle qu'elle devrait être ÉCRITE. Le modèle voit ainsi
     * les deux formes côte à côte au lieu d'avoir à deviner l'unité.
     *
     * @param array<string, mixed> $fiche
     *
     * @return array<string, string>
     */
    /**
     * Les champs que Ket peut ÉCRIRE sur cette entité, sous leur NOM EXACT et avec
     * le libellé de l'écran — « pourcentageCommissionSpecifiqueHT » et « Taux de
     * commission » sont le même champ, et Ket a besoin des deux : le libellé pour
     * parler à l'utilisateur, le nom pour écrire.
     *
     * Rien n'est exposé pour une entité que Ket ne peut pas écrire : ce seraient des
     * tokens dépensés à décrire une surface interdite. Les libellés sont ceux du
     * FormType, mis en cache par nom court (une seule inspection par entité).
     *
     * @return array<string, string> nom du champ => libellé
     */
    private function champsModifiables(string $shortName): array
    {
        if (!MutationAllowlist::autorise($shortName)) {
            return [];
        }

        $fqcn = 'App\\Entity\\' . $shortName;
        if (!class_exists($fqcn)) {
            return [];
        }

        try {
            return $this->champsInspector->libellesFormulaire($shortName, $fqcn);
        } catch (\Throwable) {
            // Une entité sans FormType exploitable ne doit pas faire échouer une
            // LECTURE : on rend la fiche, simplement sans cette aide.
            return [];
        }
    }

    private function unitesPourcentage(string $shortName, array $fiche): array
    {
        $fqcn = 'App\\Entity\\' . $shortName;
        if (!class_exists($fqcn)) {
            return [];
        }

        $unites = [];
        foreach ($this->champsInspector->champsPourcentage($shortName, $fqcn) as $champ) {
            if (!is_numeric($fiche[$champ] ?? null)) {
                continue;
            }
            $fraction = (float) $fiche[$champ];
            $pourcent = rtrim(rtrim(number_format($fraction * 100, 3, '.', ''), '0'), '.');
            // Message VERROUILLÉ contre les deux erreurs opposées : lire la fraction
            // comme un pourcentage (1 → « 1 % »), et « corriger » une valeur déjà
            // juste (réécrire 1 → stocke 0.01). Le nombre brut EST une fraction ;
            // il vaut déjà (brut × 100) %.
            $unites[$champ] = sprintf(
                'le nombre stocké %s est une FRACTION : il vaut %s %% (= %s × 100). Ce n\'est PAS %s %%. '
                . 'Pour l\'AFFICHER, dis « %s %% ». Cette valeur est DÉJÀ correcte : ne la « corrige » '
                . 'pas. Ne la modifie QUE si l\'utilisateur donne un NOUVEAU pourcentage — et fournis alors '
                . 'ce pourcentage (ex. %s), jamais la fraction %s.',
                $fiche[$champ],
                $pourcent,
                $fiche[$champ],
                $fiche[$champ],
                $pourcent,
                $pourcent,
                $fiche[$champ],
            );
        }

        return $unites;
    }

    /**
     * Pour chaque collection éditable déclarée par le formulaire de l'entité,
     * restitue ses membres (id + libellé + attributs stockés) afin que Ket puisse
     * cibler une édition/suppression par id — puis MÈTRE cette lecture (barème en
     * vigueur, comme toute lecture d'entité).
     *
     * @return array<string, array{entite:string, membres:array<int,array>}>
     */
    private function collectionsEditablesLisibles(object $entity, string $shortName, AiScope $scope): array
    {
        $acteur = $scope->invite->getUtilisateur();
        $out = [];
        foreach ($this->formTreeInspector->collectionsEditables($shortName) as $nom => $ce) {
            if (!method_exists($entity, $ce->getter)) {
                continue;
            }
            $membres = [];
            foreach ($entity->{$ce->getter}() as $membre) {
                if (!is_object($membre) || !method_exists($membre, 'getId')) {
                    continue;
                }
                $membres[] = [
                    'id'      => $membre->getId(),
                    'libelle' => $this->libelleMembre($membre),
                    'champs'  => $this->ficheNormaliseur->fiche($membre),
                ];
            }
            if ($membres === []) {
                continue;
            }
            $this->tokenAccountService->meterRead($ce->childFqcn, count($membres), $scope->entreprise, $acteur);
            $out[$nom] = ['entite' => $ce->childShortName, 'membres' => $membres];
        }

        return $out;
    }

    /**
     * Libellé sûr d'un membre de collection : best-effort par getters usuels, SANS
     * cast (string) — toutes les entités n'ont pas de __toString (ex.
     * RevenuPourCourtier), et le cast planterait tout l'appel de l'outil.
     */
    private function libelleMembre(object $membre): string
    {
        foreach (['getNom', 'getLibelle', 'getTitre', 'getReference', 'getCode'] as $getter) {
            if (method_exists($membre, $getter)) {
                $val = $membre->{$getter}();
                if (is_string($val) && trim(strip_tags($val)) !== '') {
                    return trim(strip_tags($val));
                }
            }
        }
        $id = method_exists($membre, 'getId') ? $membre->getId() : null;

        return $id !== null ? ('#' . $id) : '(sans libellé)';
    }
}
