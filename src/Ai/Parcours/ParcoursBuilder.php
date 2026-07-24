<?php

namespace App\Ai\Parcours;

use App\Ai\Mutation\MutationAllowlist;
use App\Ai\Scope\AiScope;
use App\Entity\Invite;
use App\Service\Workspace\FormTreeInspector;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Service\Workspace\WorkspaceMutationService;
use App\Services\JSBDynamicSearchService;

/**
 * Construit le PARCOURS DE SAISIE d'un objet métier : la liste ORDONNÉE des
 * étapes que Ket présente à l'utilisateur pour qu'il choisisse, EN UNE FOIS,
 * jusqu'où il veut aller — puis le plan unique qui en découle.
 *
 * Rien n'est réinventé ici : la narration vient de ParcoursCatalogue, tout le
 * reste est DÉRIVÉ des sources de vérité déjà en place —
 *  - FormTreeInspector           : quelles collections sont réellement éditables,
 *  - inventaireChamps()          : champs obligatoires / facultatifs / auto réels,
 *  - WorkspaceAccessResolver     : droits de l'invité (fail-closed),
 *  - MutationAllowlist           : périmètre mutable de Ket,
 *  - JSBDynamicSearchService     : valeurs des référentiels, scopées entreprise.
 *
 * Une entité sans trame rédigée reçoit un parcours GÉNÉRIQUE : l'entité elle-même,
 * puis une étape optionnelle par collection éditable de son formulaire.
 *
 * FAIL-CLOSED : une étape que l'invité n'a pas le droit d'écrire n'est pas
 * proposée ; un sujet hors allowlist ne produit aucun parcours.
 */
class ParcoursBuilder
{
    /** Nombre maximal de valeurs de référentiel restituées par étape. */
    private const MAX_VALEURS_REFERENTIEL = 30;

    public function __construct(
        private readonly WorkspaceMutationService $mutationService,
        private readonly WorkspaceAccessResolver $accessResolver,
        private readonly FormTreeInspector $formTreeInspector,
        private readonly JSBDynamicSearchService $searchService,
    ) {
    }

    /**
     * @return array{
     *     sujet: string, libelle: string, resume: string, socle: string,
     *     etapes: array<int, array>, indisponibles: array<int, string>
     * }|null null si le sujet est hors périmètre mutable.
     */
    public function construire(string $sujet, AiScope $scope): ?array
    {
        $trame = ParcoursCatalogue::trame($sujet);
        $socle = $trame['socle'] ?? $sujet;

        if (!MutationAllowlist::autorise($socle) || !class_exists('App\\Entity\\' . $socle)) {
            return null;
        }
        // L'étape socle est indispensable : sans droit d'écriture dessus, aucun parcours.
        if (!$this->accessResolver->can($scope->invite, $socle, Invite::ACCESS_ECRITURE)) {
            return null;
        }

        $labels = $this->accessResolver->libellesEntites();
        $trameEtapes = $trame['etapes'] ?? $this->etapesGeneriques($socle, $labels);

        $etapes = [];
        $indisponibles = [];
        foreach ($trameEtapes as $etape) {
            $construite = $this->construireEtape($etape, $socle, $scope, $labels);
            if ($construite === null) {
                $indisponibles[] = (string) ($etape['libelle'] ?? $etape['cle'] ?? '');
                continue;
            }
            $etapes[] = $construite;
        }

        return [
            'sujet'   => $sujet,
            'libelle' => $trame['libelle'] ?? sprintf('Enregistrer : %s', $labels[$socle] ?? $socle),
            'resume'  => $trame['resume'] ?? sprintf(
                'L’enregistrement de « %s » et, à votre choix, les éléments que son formulaire permet d’y '
                . 'rattacher directement.',
                $labels[$socle] ?? $socle,
            ),
            'socle'         => $socle,
            'etapes'        => $etapes,
            'indisponibles' => array_values(array_filter($indisponibles)),
        ];
    }

    /**
     * Parcours GÉNÉRIQUE d'une entité sans trame rédigée : l'entité, puis une
     * étape optionnelle par collection éditable de son formulaire (exactement ce
     * que l'écran permet d'y rattacher).
     *
     * @param array<string, string> $labels
     *
     * @return array<int, array>
     */
    private function etapesGeneriques(string $socle, array $labels): array
    {
        $etapes = [[
            'cle'       => mb_strtolower($socle),
            'libelle'   => $labels[$socle] ?? $socle,
            'entite'    => $socle,
            'via'       => 'socle',
            'role'      => ParcoursCatalogue::ROLE_SOCLE,
            'questions' => [],
        ]];

        foreach ($this->formTreeInspector->collectionsEditables($socle) as $nom => $ce) {
            $etapes[] = [
                'cle'       => $nom,
                'libelle'   => $labels[$ce->childShortName] ?? $nom,
                'entite'    => $ce->childShortName,
                'via'       => 'collection:' . $nom,
                'role'      => ParcoursCatalogue::ROLE_OPTIONNEL,
                'questions' => [],
            ];
        }

        return $etapes;
    }

    /**
     * Enrichit une étape de trame par le réel : droits, champs, gabarit à recopier
     * dans preparer_operations, valeurs de référentiel. null = étape à écarter
     * (droit manquant, collection non éditable, entité hors périmètre).
     *
     * @param array<string, string> $labels
     */
    private function construireEtape(array $etape, string $socle, AiScope $scope, array $labels): ?array
    {
        $entite = (string) ($etape['entite'] ?? '');
        $via = (string) ($etape['via'] ?? 'socle');
        $cle = (string) ($etape['cle'] ?? mb_strtolower($entite));

        if ($entite === '') {
            return null;
        }

        // Rattachement : une collection doit être RÉELLEMENT éditable sur le socle.
        $collection = null;
        if (str_starts_with($via, 'collection:')) {
            $collection = substr($via, strlen('collection:'));
            $ce = $this->formTreeInspector->collectionEditable($socle, $collection);
            if ($ce === null || !$ce->allowAdd) {
                return null;
            }
            $entite = $ce->childShortName; // le nom court vient du formulaire, jamais de la trame.
        }

        // Gouvernance IDENTIQUE à celle de l'écriture (DRY avec analyserCollections) :
        // l'allowlist ne gouverne que les opérations de TÊTE ; un élément de
        // collection est gouverné par can() (une sous-entité structurelle hors carte
        // suit son parent, déjà contrôlé).
        if ($collection === null && !MutationAllowlist::autorise($entite)) {
            return null;
        }
        if (!$this->accessResolver->can($scope->invite, $entite, Invite::ACCESS_ECRITURE)) {
            return null;
        }

        $inventaire = $this->mutationService->inventaireChamps($entite, $scope);

        $construite = [
            'cle'          => $cle,
            'libelle'      => (string) ($etape['libelle'] ?? $labels[$entite] ?? $entite),
            'role'         => (string) ($etape['role'] ?? ParcoursCatalogue::ROLE_OPTIONNEL),
            'entite'       => $entite,
            'rattachement' => $via,
            'informations' => array_values((array) ($etape['questions'] ?? [])),
            'obligatoires' => $inventaire['obligatoires'],
            'facultatifs'  => $inventaire['facultatifs'],
            'auto'         => $inventaire['auto'],
            'gabarit'      => $this->gabarit($etape, $entite, $socle, $collection, $cle),
        ];

        if (isset($etape['note'])) {
            $construite['note'] = (string) $etape['note'];
        }
        if (isset($etape['referentiel'])) {
            $valeurs = $this->valeursReferentiel((string) $etape['referentiel'], $scope);
            if ($valeurs !== []) {
                $construite['valeursReferentiel'] = ['entite' => (string) $etape['referentiel'], 'valeurs' => $valeurs];
            }
        }

        return $construite;
    }

    /**
     * GABARIT de l'étape : le fragment EXACT à recopier dans l'appel
     * preparer_operations. C'est la pièce qui manquait aux modèles peu à l'aise
     * avec les structures imbriquées — ils omettaient « collections », la
     * ventilation restait en prose et la prime à 0.
     */
    private function gabarit(array $etape, string $entite, string $socle, ?string $collection, string $cle): array
    {
        $libelleEtape = (string) ($etape['libelle'] ?? $cle);

        if ($collection !== null) {
            return [
                'ou'      => sprintf('dans « collections » de l’opération « %s »', $socle),
                'fragment' => [
                    'collection' => $collection,
                    'elements'   => [[
                        'op'     => 'create',
                        'etape'  => $libelleEtape,
                        'champs' => new \stdClass(),
                    ]],
                ],
            ];
        }

        if (str_starts_with((string) ($etape['via'] ?? ''), 'reference:')) {
            $champ = substr((string) $etape['via'], strlen('reference:'));

            return [
                'ou'       => 'opération de tête SUPPLÉMENTAIRE du même plan',
                'fragment' => [
                    'op'     => 'create',
                    'entite' => $entite,
                    'etape'  => $libelleEtape,
                    'champs' => [$champ => '@socle'],
                ],
                'note' => sprintf(
                    'Le champ « %s » vaut « @socle » : c’est le renvoi vers l’enregistrement créé par '
                    . 'l’étape socle du plan, dont l’identifiant n’existe pas encore. Pose « ref: "socle" » '
                    . 'sur l’opération socle.',
                    $champ,
                ),
            ];
        }

        return [
            'ou'       => 'opération de TÊTE du plan',
            'fragment' => [
                'op'     => 'create',
                'entite' => $entite,
                'ref'    => 'socle',
                'etape'  => $libelleEtape,
                'champs' => new \stdClass(),
            ],
        ];
    }

    /**
     * Valeurs d'un référentiel (id + nom), scopées à l'entreprise : évite à Ket un
     * aller-retour de recherche pour résoudre un « type » par son nom — et évite
     * surtout de l'omettre (chargement sans type => commission à 0).
     *
     * @return array<int, array{id: int, nom: string}>
     */
    private function valeursReferentiel(string $entite, AiScope $scope): array
    {
        $fqcn = 'App\\Entity\\' . $entite;
        if (!class_exists($fqcn) || !$this->accessResolver->can($scope->invite, $entite, Invite::ACCESS_LECTURE)) {
            return [];
        }

        $result = $this->searchService->search($fqcn, [], $scope->entreprise, null, 1, self::MAX_VALEURS_REFERENTIEL);
        if (($result['status']['code'] ?? 500) !== 200) {
            return [];
        }

        $valeurs = [];
        foreach ($result['data'] ?? [] as $item) {
            if (!is_object($item) || !method_exists($item, 'getId')) {
                continue;
            }
            $nom = method_exists($item, 'getNom') ? (string) $item->getNom() : '';
            if (trim($nom) === '') {
                continue;
            }
            $valeurs[] = ['id' => (int) $item->getId(), 'nom' => trim(strip_tags($nom))];
        }

        return $valeurs;
    }
}
