<?php

namespace App\Ai\Tool;

use App\Ai\Fichier\ConversationFichierResolver;
use App\Ai\Fichier\FichierAttachePolicy;
use App\Ai\Fichier\PieceSourceRattachement;
use App\Ai\Mutation\ConversationFichierRef;
use App\Ai\Mutation\MutationAllowlist;
use App\Ai\Parcours\ParcoursBuilder;
use App\Ai\Scope\AiScope;
use App\Entity\Invite;
use App\Service\Workspace\ChampsObligatoiresInspector;
use App\Service\Workspace\FormTreeInspector;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Service\Workspace\WorkspaceMutationService;
use App\Services\Bordereau\BordereauLigneNormaliseur;
use App\Services\JSBDynamicSearchService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * ÉTAT DES LIEUX d'une saisie à partir d'une PIÈCE JOINTE : le maillon qui
 * manquait entre « j'ai lu le fichier » et « voici le plan d'enregistrement ».
 *
 * Le circuit d'écriture existe déjà de bout en bout (PreparerOperationsTool →
 * WorkspaceMutationService). Ce qui manquait : rien n'exposait, DEPUIS LE
 * SERVEUR, ce qui a été trouvé dans le fichier, d'où ça vient, ce qui manque et
 * ce qui est ambigu — et rien n'obligeait à demander l'autorisation avant de
 * planifier une écriture tirée d'un document.
 *
 * Il N'IMPLÉMENTE PAS AiToolProduisantUnPlan et n'émet AUCUNE uiAction : il ne
 * chiffre aucun budget et ne fait apparaître aucun bouton. C'est le cas
 * d'exclusion décrit par ce marqueur (même famille que parcours_saisie) — sinon
 * le modèle déduirait qu'un « Valider et exécuter » existe alors qu'il n'y en a
 * pas. Il n'appelle jamais PreparerOperationsTool : il produit le GABARIT que le
 * modèle lui passera au tour suivant, après accord de l'utilisateur.
 *
 * Schéma volontairement PLAT (« valeurs » = liste de triplets) : les modèles
 * omettent les paramètres complexes optionnels — c'est la raison d'être de
 * ModifierCompositionPrimeTool et des paires plates de preparer_programme. Le
 * regroupement en sous-opérations de collection est fait ICI, côté serveur.
 *
 * FAIL-CLOSED : mêmes gardes que l'écriture (allowlist + droit Écriture), et le
 * fichier doit appartenir à la conversation du scope.
 */
final class AnalyserFichierPourSaisieTool implements AiToolInterface
{
    /** Étiquette de l'opération de tête dans le gabarit (renvoi « @socle »). */
    private const REF_SOCLE = 'socle';

    /** Plafond de candidats remontés pour lever une ambiguïté de nom. */
    private const MAX_CANDIDATS = 8;

    public function __construct(
        private readonly WorkspaceMutationService $mutationService,
        private readonly WorkspaceAccessResolver $accessResolver,
        private readonly ConversationFichierResolver $fichierResolver,
        private readonly PieceSourceRattachement $pieceSource,
        private readonly ParcoursBuilder $parcoursBuilder,
        private readonly FormTreeInspector $formTree,
        private readonly ChampsObligatoiresInspector $champsInspector,
        private readonly JSBDynamicSearchService $searchService,
        private readonly EntiteLibelle $entiteLibelle,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function name(): string
    {
        return 'analyser_fichier_pour_saisie';
    }

    public function description(): string
    {
        return 'SAISIR un enregistrement À PARTIR D\'UNE PIÈCE JOINTE de la conversation '
            . '(« enregistre cette proposition », « crée le client de ce document »). Donne-lui '
            . 'l\'identifiant du fichier, l\'entité visée (' . implode(', ', MutationAllowlist::membres()) . ') '
            . 'et TOUTES les valeurs que tu as lues dans le fichier, chacune avec la citation exacte '
            . 'qui la justifie. Il te renvoie un ÉTAT DES LIEUX calculé par le serveur (ce qui est '
            . 'résolu, ce qui est ambigu, ce qui manque, ce qui sera créé, le sort du fichier source) '
            . 'et le GABARIT exact du plan. MODE D\'EMPLOI IMPÉRATIF : (1) présente l\'état des lieux '
            . 'en tableau, avec la source de chaque valeur ; (2) restitue mot pour mot l\'éventuel '
            . '« avertissement » ; (3) DEMANDE L\'AUTORISATION de préparer le plan et ARRÊTE-TOI. '
            . 'N\'appelle preparer_operations qu\'au tour suivant, une fois l\'accord donné. '
            . 'N\'invente jamais une valeur absente du fichier. N\'écrit rien, ne chiffre aucun budget.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'fichierId' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'Identifiant de la pièce jointe, tel qu\'indiqué dans la section '
                        . 'PIÈCES JOINTES (le nombre de « @fichier:<id> »). Ne l\'invente jamais.',
                ],
                'entite' => [
                    'type' => 'string',
                    'enum' => MutationAllowlist::membres(),
                    'description' => 'Nom court de l\'entité à créer à partir du fichier '
                        . '(ex. Cotation pour une proposition d\'assurance).',
                ],
                'valeurs' => [
                    'type' => 'array',
                    'description' => 'TOUTES les valeurs lues dans le fichier, à plat. Une entrée par '
                        . 'valeur. Pour une ligne de collection (composante de prime, tranche, revenu…), '
                        . 'renseigne « collection » et « ligne » : le serveur regroupe lui-même. '
                        . 'Ex. [{"champ":"nom","valeur":"Flotte 2026","source":"Objet : Flotte automobile 2026"},'
                        . '{"champ":"nom","valeur":"Prime nette","source":"Prime nette 9 000","collection":"chargements","ligne":0},'
                        . '{"champ":"montantFlatExceptionel","valeur":"9000","source":"Prime nette 9 000","collection":"chargements","ligne":0}]',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'champ' => [
                                'type' => 'string',
                                'description' => 'Nom technique du champ (donné par inventaire_champs / parcours_saisie).',
                            ],
                            'valeur' => [
                                'type' => 'string',
                                'description' => 'Valeur lue. Pour une relation (client, assureur, risque, type…), '
                                    . 'donne le NOM tel qu\'il figure dans le fichier : le serveur le résout. '
                                    . 'Un taux se donne en POINTS (10 pour 10 %).',
                            ],
                            'source' => [
                                'type' => 'string',
                                'description' => 'CITATION exacte du fichier qui justifie cette valeur. '
                                    . 'Obligatoire : c\'est ce que l\'utilisateur vérifiera avant d\'autoriser.',
                            ],
                            'collection' => [
                                'type' => 'string',
                                'description' => 'Nom de la collection si la valeur appartient à une ligne '
                                    . '(ex. "chargements", "tranches", "revenus"). Absent = champ de l\'entité de tête.',
                            ],
                            'ligne' => [
                                'type' => 'integer',
                                'minimum' => 0,
                                'description' => 'Index de la ligne dans la collection (0, 1, 2…). '
                                    . 'Les valeurs de même collection et même ligne forment un seul élément.',
                            ],
                        ],
                        'required' => ['champ', 'valeur', 'source'],
                    ],
                ],
            ],
            'required' => ['fichierId', 'entite'],
        ];
    }

    /** Réservé au LLM réel (aucun routage par mots-clés). */
    public function match(string $question, AiScope $scope): ?array
    {
        return null;
    }

    public function execute(array $args, AiScope $scope): AiToolResult
    {
        $shortName = (string) ($args['entite'] ?? '');
        $libelle = $this->accessResolver->libellesEntites()[$shortName] ?? $shortName;

        // FAIL-CLOSED : périmètre d'écriture, puis droit d'écriture sur l'entité.
        if (!MutationAllowlist::autorise($shortName)) {
            return AiToolResult::introuvable($shortName);
        }
        if (!$this->accessResolver->can($scope->invite, $shortName, Invite::ACCESS_ECRITURE)) {
            return AiToolResult::horsPerimetre($libelle);
        }

        // FAIL-CLOSED : le fichier doit être une pièce de CETTE conversation.
        $fichierId = (int) ($args['fichierId'] ?? 0);
        $fichier = $fichierId > 0 ? $this->fichierResolver->trouver($fichierId, $scope) : null;
        if ($fichier === null) {
            return AiToolResult::ok([
                'pret'     => false,
                'bloquant' => 'fichier_introuvable',
                'note'     => 'Aucune pièce jointe ne porte cet identifiant dans cette conversation. Reprends '
                    . 'EXACTEMENT un « @fichier:<id> » de la section PIÈCES JOINTES, ou demande à '
                    . 'l\'utilisateur d\'attacher le document.',
            ]);
        }

        $extrait = trim((string) $fichier->getTexteExtrait());
        $lisibleEnVision = FichierAttachePolicy::lisibleNativement($fichier->getMimeType());
        if ($extrait === '' && !$lisibleEnVision) {
            return AiToolResult::ok([
                'pret'     => false,
                'bloquant' => 'format_non_lisible',
                'fichier'  => ['id' => $fichierId, 'nom' => (string) $fichier->getNomOriginal()],
                'note'     => sprintf(
                    'Le fichier « %s » n\'a pas de contenu texte exploitable et son format ne peut pas être lu '
                    . 'visuellement. Dis-le franchement, n\'invente aucune donnée, et propose soit de le classer '
                    . 'tel quel comme document, soit une saisie manuelle.',
                    (string) $fichier->getNomOriginal(),
                ),
            ]);
        }

        $fqcn = 'App\\Entity\\' . $shortName;
        if (!class_exists($fqcn)) {
            return AiToolResult::introuvable($libelle);
        }

        $inventaire = $this->mutationService->inventaireChamps($shortName, $scope);
        $collections = $this->formTree->collectionsEditables($shortName);
        $pourcentages = $this->champsInspector->champsPourcentage($shortName, $fqcn);

        // Regroupement des triplets plats : tête d'un côté, lignes de collection
        // de l'autre. Le modèle n'a jamais eu à produire de structure imbriquée.
        $groupes = $this->grouper($args['valeurs'] ?? [], $collections);

        $tete = $this->analyserNoeud($groupes['tete'], $shortName, $fqcn, $scope, $pourcentages);

        $lignes = [];
        foreach ($groupes['collections'] as $nomCollection => $elements) {
            $ce = $collections[$nomCollection];
            $enfantFqcn = $ce->childFqcn;
            $enfantPourcentages = $this->champsInspector->champsPourcentage($ce->childShortName, $enfantFqcn);
            foreach ($elements as $index => $valeurs) {
                $lignes[] = [
                    'collection' => $nomCollection,
                    'ligne'      => $index,
                    'entite'     => $ce->childShortName,
                    'analyse'    => $this->analyserNoeud($valeurs, $ce->childShortName, $enfantFqcn, $scope, $enfantPourcentages),
                ];
            }
        }

        // Sort de la pièce source : règle DÉRIVÉE (collection du formulaire →
        // relation Document → rien), avertissement rédigé par le serveur.
        $piece = $this->pieceSource->resoudre($shortName, $libelle);
        $piece['fichier'] = ['id' => $fichierId, 'nom' => (string) $fichier->getNomOriginal()];

        $parcours = $this->parcoursBuilder->construire($shortName, $scope);
        $etapesNonCouvertes = $this->etapesNonCouvertes($parcours, $groupes['collections']);

        $gabarit = $this->gabaritPlan($shortName, $tete, $lignes, $piece, $fichierId, $fichier->getNomOriginal(), $parcours);

        return AiToolResult::ok([
            'pret'                 => true,
            'entite'               => $shortName,
            'libelle'              => $libelle,
            'fichier'              => [
                'id'         => $fichierId,
                'nom'        => (string) $fichier->getNomOriginal(),
                'lecture'    => $extrait !== '' ? 'texte extrait' : 'lecture visuelle',
            ],
            'trouve'               => $tete['trouve'],
            'aResoudre'            => $tete['aResoudre'],
            'aCreer'               => $tete['aCreer'],
            'manquants'            => $this->manquants($inventaire, $tete),
            'relationsNonResolues' => $this->relationsNonResolues($shortName, $fqcn, $tete),
            'lignes'               => $lignes,
            'etapesNonCouvertes'   => $etapesNonCouvertes,
            'pieceSource'          => $piece,
            'gabaritPlan'          => $gabarit,
            'note'                 => $this->note($piece, $tete, $etapesNonCouvertes),
        ]);
    }

    /**
     * Éclate la liste plate en un nœud de tête et des lignes de collection.
     * Une collection inconnue du formulaire est IGNORÉE (fail-closed : la surface
     * éditable de Ket est strictement celle de l'écran) et signalée en retour.
     *
     * @param array<string, \App\Service\Workspace\CollectionEditable> $collections
     *
     * @return array{tete: array<int, array>, collections: array<string, array<int, array<int, array>>>}
     */
    private function grouper(mixed $valeurs, array $collections): array
    {
        $tete = [];
        $parCollection = [];
        if (!is_array($valeurs)) {
            return ['tete' => $tete, 'collections' => $parCollection];
        }

        foreach ($valeurs as $entree) {
            if (!is_array($entree)) {
                continue;
            }
            $champ = trim((string) ($entree['champ'] ?? ''));
            if ($champ === '') {
                continue;
            }
            $item = [
                'champ'  => $champ,
                'valeur' => $entree['valeur'] ?? null,
                'source' => trim((string) ($entree['source'] ?? '')),
            ];

            $collection = trim((string) ($entree['collection'] ?? ''));
            if ($collection === '') {
                $tete[] = $item;
                continue;
            }
            $ce = $collections[$collection] ?? null;
            if ($ce === null || !$ce->allowAdd) {
                continue; // hors surface du formulaire : jamais écrit.
            }
            $ligne = max(0, (int) ($entree['ligne'] ?? 0));
            $parCollection[$collection][$ligne][] = $item;
        }

        foreach ($parCollection as $nom => $lignes) {
            ksort($lignes);
            $parCollection[$nom] = array_values($lignes);
        }

        return ['tete' => $tete, 'collections' => $parCollection];
    }

    /**
     * Analyse un nœud (tête ou ligne de collection) : normalise les scalaires,
     * résout les relations par leur NOM dans le périmètre de l'entreprise.
     *
     * @param array<int, array{champ:string, valeur:mixed, source:string}> $valeurs
     * @param string[] $pourcentages
     *
     * @return array{trouve: array, aResoudre: array, aCreer: array, champs: array<string, mixed>}
     */
    private function analyserNoeud(array $valeurs, string $shortName, string $fqcn, AiScope $scope, array $pourcentages): array
    {
        $trouve = [];
        $aResoudre = [];
        $aCreer = [];
        $champs = [];

        try {
            $meta = $this->em->getClassMetadata($fqcn);
        } catch (\Throwable) {
            return ['trouve' => [], 'aResoudre' => [], 'aCreer' => [], 'champs' => []];
        }
        $labels = $this->champsInspector->libellesFormulaire($shortName, $fqcn);

        foreach ($valeurs as $item) {
            $champ = $item['champ'];
            $brut = $item['valeur'];
            $libelleChamp = $labels[$champ] ?? $this->champsInspector->humaniser($champ);

            // Relation : résolue par son nom, dans l'entreprise du scope.
            if ($meta->hasAssociation($champ)) {
                $resolution = $this->resoudreRelation($meta, $champ, (string) $brut, $scope);
                $ligne = [
                    'champ'   => $champ,
                    'libelle' => $libelleChamp,
                    'lu'      => (string) $brut,
                    'source'  => $item['source'],
                    'entite'  => $resolution['entite'],
                ];
                if ($resolution['statut'] === 'resolu') {
                    $champs[$champ] = $resolution['id'];
                    $trouve[] = $ligne + ['valeur' => $resolution['libelle'], 'id' => $resolution['id']];
                } elseif ($resolution['statut'] === 'ambigu') {
                    $aResoudre[] = $ligne + ['candidats' => $resolution['candidats']];
                } else {
                    $aCreer[] = $ligne + ['motif' => $resolution['motif']];
                }
                continue;
            }

            if (!$meta->hasField($champ)) {
                continue; // champ inconnu de l'entité : jamais soumis.
            }

            $valeur = $this->normaliser($brut, (string) $meta->getTypeOfField($champ));
            $champs[$champ] = $valeur;
            $ligne = [
                'champ'   => $champ,
                'libelle' => $libelleChamp,
                'valeur'  => $valeur,
                'source'  => $item['source'],
            ];
            if (in_array($champ, $pourcentages, true)) {
                $ligne['unite'] = 'pourcentage';
                $ligne['aide'] = 'Valeur attendue en pourcentage (15 pour 15 %), jamais en fraction.';
            }
            $trouve[] = $ligne;
        }

        return ['trouve' => $trouve, 'aResoudre' => $aResoudre, 'aCreer' => $aCreer, 'champs' => $champs];
    }

    /**
     * Résout une relation par le NOM lu dans le fichier : un seul candidat =>
     * résolu ; plusieurs => l'utilisateur tranche ; aucun => proposition de
     * création. Jamais de choix silencieux.
     *
     * @return array{statut:string, entite:string, id:?int, libelle:?string, candidats:array, motif:?string}
     */
    private function resoudreRelation(object $meta, string $champ, string $recherche, AiScope $scope): array
    {
        $mapping = $meta->getAssociationMapping($champ);
        $cibleFqcn = ltrim((string) ($mapping->targetEntity ?? ''), '\\');
        $cibleCourt = str_contains($cibleFqcn, '\\') ? substr($cibleFqcn, strrpos($cibleFqcn, '\\') + 1) : $cibleFqcn;
        $vide = ['statut' => 'absent', 'entite' => $cibleCourt, 'id' => null, 'libelle' => null, 'candidats' => [], 'motif' => null];

        $recherche = trim($recherche);
        if ($recherche === '' || !class_exists($cibleFqcn)) {
            return $vide + ['motif' => 'valeur illisible'];
        }
        // Fail-closed : sans droit de lecture sur la cible, on ne cherche pas.
        if (!$this->accessResolver->can($scope->invite, $cibleCourt, Invite::ACCESS_LECTURE)) {
            return array_merge($vide, ['motif' => 'hors de votre périmètre de lecture']);
        }

        $displayField = $this->entiteLibelle->displayField($cibleFqcn);
        if ($displayField === null) {
            return array_merge($vide, ['motif' => 'rubrique sans champ de libellé']);
        }

        $result = $this->searchService->search(
            $cibleFqcn,
            [$displayField => ['operator' => 'LIKE', 'value' => $recherche, 'mode' => 'contains']],
            $scope->entreprise,
            null,
            1,
            self::MAX_CANDIDATS,
        );
        if (($result['status']['code'] ?? 500) !== 200) {
            return array_merge($vide, ['motif' => 'recherche impossible']);
        }

        $candidats = [];
        foreach ($result['data'] ?? [] as $item) {
            if (!is_object($item) || !method_exists($item, 'getId')) {
                continue;
            }
            $candidats[] = [
                'id'      => (int) $item->getId(),
                'libelle' => $this->entiteLibelle->libelle($item, $displayField),
            ];
        }

        if ($candidats === []) {
            return array_merge($vide, ['motif' => 'aucun enregistrement de ce nom']);
        }
        if (count($candidats) === 1) {
            return [
                'statut'    => 'resolu',
                'entite'    => $cibleCourt,
                'id'        => $candidats[0]['id'],
                'libelle'   => $candidats[0]['libelle'],
                'candidats' => [],
                'motif'     => null,
            ];
        }

        return [
            'statut'    => 'ambigu',
            'entite'    => $cibleCourt,
            'id'        => null,
            'libelle'   => null,
            'candidats' => $candidats,
            'motif'     => null,
        ];
    }

    /**
     * Normalise une valeur lue dans un document vers le type Doctrine du champ.
     * Le nettoyage des nombres est délégué à BordereauLigneNormaliseur (source
     * unique du traitement des séparateurs de milliers, déjà éprouvée sur les
     * bordereaux Excel).
     */
    private function normaliser(mixed $brut, string $type): mixed
    {
        if ($brut === null) {
            return null;
        }
        $texte = is_scalar($brut) ? trim((string) $brut) : '';
        if ($texte === '') {
            return null;
        }

        return match ($type) {
            'integer', 'smallint', 'bigint' => (int) round(BordereauLigneNormaliseur::nettoyerNombre($texte)),
            'float', 'decimal' => BordereauLigneNormaliseur::nettoyerNombre($texte),
            'boolean' => in_array(mb_strtolower($texte), ['1', 'true', 'oui', 'vrai', 'yes', 'x'], true),
            'date', 'date_immutable', 'datetime', 'datetime_immutable' => $this->normaliserDate($texte),
            default => $texte,
        };
    }

    /** Date d'un document vers le format attendu par les formulaires (Y-m-d). */
    private function normaliserDate(string $texte): ?string
    {
        // Format francophone d'abord : 12/03/2026 n'est pas le 3 décembre.
        foreach (['d/m/Y', 'd-m-Y', 'd.m.Y', 'Y-m-d', 'Y/m/d'] as $format) {
            $date = \DateTimeImmutable::createFromFormat('!' . $format, $texte);
            if ($date instanceof \DateTimeImmutable) {
                return $date->format('Y-m-d');
            }
        }
        try {
            return (new \DateTimeImmutable($texte))->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Champs OBLIGATOIRES de l'inventaire qu'aucune valeur lue ne couvre.
     *
     * @return array<int, array{champ:string, libelle:string}>
     */
    private function manquants(array $inventaire, array $tete): array
    {
        $couverts = array_keys($tete['champs']);
        foreach ($tete['aResoudre'] as $r) {
            $couverts[] = $r['champ'];
        }
        foreach ($tete['aCreer'] as $r) {
            $couverts[] = $r['champ'];
        }
        $manquants = [];
        foreach ($inventaire['obligatoires'] ?? [] as $item) {
            if (!in_array($item['champ'], $couverts, true)) {
                $manquants[] = ['champ' => $item['champ'], 'libelle' => $item['libelle']];
            }
        }

        return $manquants;
    }

    /**
     * Relations de tête restées VIDES, y compris celles que la base tolère à null.
     *
     * Une Cotation n'a que « nom » et « duree » de bloquants : « assureur » et
     * « piste » sont nullables en colonne. Sans ce garde-fou, une cotation
     * orpheline passerait la validation et serait annoncée comme un succès. On
     * les remonte donc TOUTES, sans se fier à la nullabilité — l'utilisateur
     * jugera. (entreprise/invite sont exclus : auto-scopés.)
     *
     * @return array<int, array{champ:string, libelle:string, entite:string}>
     */
    private function relationsNonResolues(string $shortName, string $fqcn, array $tete): array
    {
        $couverts = array_keys($tete['champs']);
        foreach (array_merge($tete['aResoudre'], $tete['aCreer']) as $r) {
            $couverts[] = $r['champ'];
        }

        try {
            $meta = $this->em->getClassMetadata($fqcn);
        } catch (\Throwable) {
            return [];
        }
        $labels = $this->champsInspector->libellesFormulaire($shortName, $fqcn);

        $nonResolues = [];
        foreach ($meta->getAssociationMappings() as $champ => $mapping) {
            if (!$mapping->isManyToOne() || !$mapping->isOwningSide()) {
                continue;
            }
            if (in_array($champ, ['entreprise', 'invite'], true) || in_array($champ, $couverts, true)) {
                continue;
            }
            $cible = ltrim((string) ($mapping->targetEntity ?? ''), '\\');
            $nonResolues[] = [
                'champ'   => $champ,
                'libelle' => $labels[$champ] ?? $this->champsInspector->humaniser($champ),
                'entite'  => str_contains($cible, '\\') ? substr($cible, strrpos($cible, '\\') + 1) : $cible,
            ];
        }

        return $nonResolues;
    }

    /**
     * Étapes du parcours métier qu'aucune donnée du fichier ne couvre, avec leur
     * conséquence : une composition de prime absente laisse la prime à 0.
     *
     * @return array<int, array{etape:string, consequence:string}>
     */
    private function etapesNonCouvertes(?array $parcours, array $collectionsCouvertes): array
    {
        if ($parcours === null) {
            return [];
        }
        $manquantes = [];
        foreach ($parcours['etapes'] ?? [] as $etape) {
            $rattachement = (string) ($etape['rattachement'] ?? '');
            if (!str_starts_with($rattachement, 'collection:')) {
                continue;
            }
            $nom = substr($rattachement, strlen('collection:'));
            if (array_key_exists($nom, $collectionsCouvertes)) {
                continue;
            }
            $manquantes[] = [
                'etape'       => (string) ($etape['libelle'] ?? $nom),
                'collection'  => $nom,
                'consequence' => (string) ($etape['note'] ?? 'Rien ne sera enregistré pour cette étape.'),
            ];
        }

        return $manquantes;
    }

    /**
     * Gabarit EXACT à recopier dans preparer_operations une fois l'autorisation
     * obtenue. Assemblé par le serveur : c'est ce qui évite au modèle de bâtir
     * une structure imbriquée — l'erreur qui laissait les collections en prose.
     */
    private function gabaritPlan(
        string $shortName,
        array $tete,
        array $lignes,
        array $piece,
        int $fichierId,
        ?string $nomFichier,
        ?array $parcours,
    ): array {
        $champs = $tete['champs'];
        $etapeSocle = (string) ($parcours['etapes'][0]['libelle'] ?? $shortName);

        $collections = [];
        foreach ($lignes as $ligne) {
            $collections[$ligne['collection']]['collection'] = $ligne['collection'];
            $collections[$ligne['collection']]['elements'][] = [
                'op'     => 'create',
                'etape'  => $this->libelleEtapeCollection($parcours, $ligne['collection']),
                'champs' => $ligne['analyse']['champs'],
            ];
        }

        $marqueur = ConversationFichierRef::marqueur($fichierId);
        $nomDocument = $this->nomDocument($nomFichier);
        $fragment = $this->pieceSource->fragmentGabarit($piece, $marqueur, $nomDocument, '@' . self::REF_SOCLE);

        $operationSocle = [
            'op'     => 'create',
            'entite' => $shortName,
            'ref'    => self::REF_SOCLE,
            'etape'  => $etapeSocle,
            'champs' => $champs,
        ];

        if ($fragment !== null && $fragment['cible'] === 'champs') {
            $operationSocle['champs'] = array_merge($operationSocle['champs'], $fragment['fragment']);
        }
        if ($fragment !== null && $fragment['cible'] === 'collections') {
            $nom = $fragment['fragment']['collection'];
            $collections[$nom]['collection'] = $nom;
            foreach ($fragment['fragment']['elements'] as $element) {
                $collections[$nom]['elements'][] = $element;
            }
        }
        if ($collections !== []) {
            $operationSocle['collections'] = array_values($collections);
        }

        $operations = [$operationSocle];
        if ($fragment !== null && $fragment['cible'] === 'operation') {
            $operations[] = $fragment['fragment'];
        }

        return $operations;
    }

    /** Libellé d'étape d'une collection, repris du parcours (décochable côté barre). */
    private function libelleEtapeCollection(?array $parcours, string $collection): string
    {
        foreach ($parcours['etapes'] ?? [] as $etape) {
            if ((string) ($etape['rattachement'] ?? '') === 'collection:' . $collection) {
                return (string) ($etape['libelle'] ?? $collection);
            }
        }

        return ucfirst($collection);
    }

    /** Nom du Document dérivé du nom d'origine de la pièce — jamais inventé. */
    private function nomDocument(?string $nomFichier): string
    {
        $nom = trim((string) $nomFichier);

        return $nom !== '' ? mb_substr($nom, 0, 255) : 'Pièce jointe';
    }

    /** Consigne de conduite : tout refus et tout succès porte la sienne. */
    private function note(array $piece, array $tete, array $etapesNonCouvertes): string
    {
        $note = 'Présente cet état des lieux en TABLEAU (champ · valeur retenue · source dans le fichier), '
            . 'puis énonce séparément : ce qui est AMBIGU (« aResoudre » — fais choisir l\'utilisateur), '
            . 'ce qui sera CRÉÉ au passage (« aCreer »), ce qui MANQUE (« manquants », « relationsNonResolues ») '
            . 'et ce qui ne sera PAS enregistré (« etapesNonCouvertes »). ';

        if (($piece['avertissement'] ?? null) !== null) {
            $note .= 'RESTITUE MOT POUR MOT, dans ce même message, la phrase suivante — ne la reformule pas, '
                . 'ne la reporte pas après la validation : « ' . $piece['avertissement'] . ' ». ';
        } else {
            $note .= 'Indique aussi le sort du fichier source : ' . $piece['explication'] . ' ';
        }

        $note .= 'TERMINE par UNE question : « Puis-je préparer le plan d\'enregistrement ? » et ARRÊTE-TOI LÀ. '
            . 'N\'appelle preparer_operations que lorsque l\'utilisateur aura répondu oui — en recopiant alors '
            . '« gabaritPlan » tel quel dans le paramètre « operations », complété des choix qu\'il aura faits. '
            . 'Aucune valeur ne s\'invente : ce qui n\'est pas dans le fichier se demande.';

        if ($tete['aResoudre'] !== [] || $etapesNonCouvertes !== []) {
            $note .= ' Ne présente pas ce plan comme complet tant que les points ci-dessus ne sont pas tranchés.';
        }

        return $note;
    }
}
