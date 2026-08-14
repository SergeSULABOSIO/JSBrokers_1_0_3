<?php

namespace App\Ai\Parcours;

use App\Ai\Fichier\PreuveDeContrat;
use App\Ai\Mutation\MutationAllowlist;
use App\Ai\Scope\AiScope;
use App\Entity\Invite;
use App\Service\Workspace\FormTreeInspector;
use App\Service\Workspace\ReferentielEnumerateur;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Service\Workspace\WorkspaceMutationService;

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
 *  - ReferentielEnumerateur      : valeurs des référentiels, scopées entreprise.
 *
 * Une entité sans trame rédigée reçoit un parcours GÉNÉRIQUE : l'entité elle-même,
 * puis une étape optionnelle par collection éditable de son formulaire.
 *
 * FAIL-CLOSED : une étape que l'invité n'a pas le droit d'écrire n'est pas
 * proposée ; un sujet hors allowlist ne produit aucun parcours.
 */
class ParcoursBuilder
{
    public function __construct(
        private readonly WorkspaceMutationService $mutationService,
        private readonly WorkspaceAccessResolver $accessResolver,
        private readonly FormTreeInspector $formTreeInspector,
        private readonly ReferentielEnumerateur $referentiels,
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
     * Le rôle EFFECTIF d'une étape, et ce qui le justifie quand il a été promu.
     *
     * Une étape n'est optionnelle que tant que le fait qu'elle décrit reste une
     * hypothèse. Le 2026-08-14, le courtier a joint le contrat signé et Ket lui a
     * demandé s'il existait un contrat : l'étape « Le contrat (avenant) » était rangée
     * parmi les optionnelles, aux côtés des tâches de suivi, et le plan l'a donc
     * omise — laissant une cotation sans police, c'est-à-dire un dossier dont les
     * primes et les commissions ne comptent nulle part.
     *
     * La promotion est décidée ICI, par le serveur, à partir du fil : le modèle ne la
     * devine pas et ne peut pas l'inventer. Et elle est JUSTIFIÉE — l'utilisateur doit
     * lire pourquoi une étape qu'il n'a pas demandée entre dans son plan.
     *
     * @param array<string, mixed> $etape
     *
     * @return array{0: string, 1: string|null}
     */
    private function role(array $etape, AiScope $scope): array
    {
        $role = (string) ($etape['role'] ?? ParcoursCatalogue::ROLE_OPTIONNEL);

        if (($etape['promuSi'] ?? null) !== ParcoursCatalogue::PROMU_SI_CONTRAT_JOINT) {
            return [$role, null];
        }

        $piece = PreuveDeContrat::piece($scope->conversation?->getFichiers() ?? []);
        if ($piece === null) {
            return [$role, null];
        }

        return [
            ParcoursCatalogue::ROLE_RECOMMANDE,
            sprintf(
                'Cette étape n’est PAS optionnelle ici : « %s » est joint au dossier, ce qui établit '
                . 'que la police existe. Inclus-la dans le plan sans demander si un contrat est à '
                . 'enregistrer — la question est déjà tranchée par la pièce. Lis-y la référence de '
                . 'police et la période de couverture ; ne demande que ce qui n’y figure pas.',
                (string) $piece->getNomOriginal(),
            ),
        ];
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

        [$role, $justification] = $this->role($etape, $scope);

        $construite = [
            'cle'          => $cle,
            'libelle'      => (string) ($etape['libelle'] ?? $labels[$entite] ?? $entite),
            'role'         => $role,
            'entite'       => $entite,
            'rattachement' => $via,
            'informations' => array_values((array) ($etape['questions'] ?? [])),
            'obligatoires' => $inventaire['obligatoires'],
            'facultatifs'  => $inventaire['facultatifs'],
            'auto'         => $inventaire['auto'],
            'gabarit'      => $this->gabarit($etape, $entite, $socle, $collection, $cle),
        ];

        if ($justification !== null) {
            $construite['justification'] = $justification;
        }
        if (isset($etape['note'])) {
            $construite['note'] = (string) $etape['note'];
        }
        // La trame FORCE l'énumération enrichie d'un référentiel dont l'étape ne peut pas
        // se décider sans ses attributs. L'inventaire des champs énumère de son côté les
        // référentiels courts de façon systématique, en `id => libellé`.
        if (isset($etape['referentiel'])) {
            $valeurs = $this->referentiels->enrichi((string) $etape['referentiel'], $scope);
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

}
