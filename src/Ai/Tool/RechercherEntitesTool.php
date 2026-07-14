<?php

namespace App\Ai\Tool;

use App\Ai\AiText;
use App\Ai\Scope\AiScope;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Services\JSBDynamicSearchService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Liste (ou recherche par texte) les enregistrements d'une rubrique du
 * workspace pour l'entreprise active, avec pagination. Complément naturel de
 * CompterEntitesTool : là où celui-ci répond « combien », celui-ci répond
 * « lesquels ». Peut se RESTREINDRE aux enregistrements liés à une fiche
 * précise (paramètre lieA — ex. les tâches d'une piste, les avenants d'un
 * client), à PLUSIEURS niveaux de relation : le plus court chemin de
 * relations Doctrine entre les deux entités est détecté par métadonnées
 * (BFS), générique pour tout couple d'entités du workspace. Recherche
 * déléguée à JSBDynamicSearchService (scoping entreprise systématique) ;
 * restitution volontairement compacte (id + libellé) pour maîtriser les
 * tokens — les détails d'un enregistrement relèvent d'outils dédiés (ex.
 * indicateur_calcule).
 */
final class RechercherEntitesTool implements AiToolInterface
{
    /** Taille de page fixe côté serveur : maîtrise des tokens restitués au modèle. */
    private const PAGE_SIZE = 20;

    /** Profondeur maximale du chemin de relations exploré pour lieA (père → fils → petit-fils). */
    private const MAX_PROFONDEUR_LIEN = 3;

    public function __construct(
        private readonly WorkspaceAccessResolver $accessResolver,
        private readonly JSBDynamicSearchService $searchService,
        private readonly EntiteLexique $lexique,
        private readonly EntiteLibelle $libelleur,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function name(): string
    {
        return 'rechercher_entites';
    }

    public function description(): string
    {
        return "Liste ou recherche les enregistrements d'une catégorie de données de l'entreprise "
            . '(clients, avenants, pistes, notes, sinistres…), avec filtre texte optionnel et '
            . 'pagination (' . self::PAGE_SIZE . ' par page). À appeler quand l’utilisateur demande '
            . '« liste », « affiche », « montre-moi », « quels sont »… Le paramètre lieA restreint '
            . 'aux enregistrements LIÉS à une fiche précise, même à plusieurs niveaux de relation '
            . '(ex. les tâches d’une piste, les tâches ou avenants d’un CLIENT via ses pistes) — '
            . 'SEUL moyen fiable de connaître les éléments liés : une fiche ne les contient jamais. '
            . 'Renvoie l’identifiant et le libellé de chaque enregistrement.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'entite' => [
                    'type' => 'string',
                    'description' => "Nom court de l'entité à lister (ex. Client, Avenant, Piste).",
                    'enum' => $this->lexique->nomsCourts(),
                ],
                'filtre' => [
                    'type' => 'string',
                    'description' => 'Texte recherché dans le libellé des enregistrements (optionnel).',
                ],
                'page' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'Numéro de page à restituer (défaut : 1).',
                ],
                'lieA' => [
                    'type' => 'object',
                    'description' => 'Restreint aux enregistrements LIÉS à un enregistrement précis, '
                        . 'même indirectement (le chemin de relations est résolu automatiquement) : '
                        . 'les tâches de la piste 42 → entite=Tache et lieA={entite: "Piste", id: 42} ; '
                        . 'les tâches du client 82 → entite=Tache et lieA={entite: "Client", id: 82}. '
                        . "L'id s'obtient d'une fiche attachée ou d'une première recherche.",
                    'properties' => [
                        'entite' => [
                            'type' => 'string',
                            'enum' => $this->lexique->nomsCourts(),
                            'description' => "Nom court de l'enregistrement de rattachement.",
                        ],
                        'id' => [
                            'type' => 'integer',
                            'description' => "Identifiant de l'enregistrement de rattachement.",
                        ],
                    ],
                    'required' => ['entite', 'id'],
                ],
            ],
            'required' => ['entite'],
        ];
    }

    public function match(string $question, AiScope $scope): ?array
    {
        $normalized = AiText::normalize($question);
        if (!preg_match('/\b(liste[rsz]?|affiche[rsz]?|montre[rsz]?|enumere[rsz]?|quel(?:le)?s sont)\b/', $normalized)) {
            return null;
        }

        $shortName = $this->lexique->matchEntite($normalized);

        return $shortName === null ? null : ['entite' => $shortName];
    }

    public function execute(array $args, AiScope $scope): AiToolResult
    {
        $shortName = (string) ($args['entite'] ?? '');
        $labels = $this->accessResolver->libellesEntites();
        if (!isset($labels[$shortName])) {
            return AiToolResult::introuvable($shortName);
        }

        // FAIL-CLOSED : sans droit de lecture explicite, les données n'existent
        // pas pour l'assistant.
        if (!$this->accessResolver->canRead($scope->invite, $shortName)) {
            return AiToolResult::horsPerimetre($labels[$shortName]);
        }

        $fqcn = 'App\\Entity\\' . $shortName;
        if (!class_exists($fqcn)) {
            return AiToolResult::introuvable($shortName);
        }

        $filtre = trim((string) ($args['filtre'] ?? ''));
        $page = max(1, (int) ($args['page'] ?? 1));
        $displayField = $this->libelleur->displayField($fqcn);

        // Restriction aux enregistrements LIÉS à une fiche (lieA) : le plus
        // court chemin de relations Doctrine vers l'entité de rattachement est
        // détecté par métadonnées, à plusieurs niveaux (ex. Tache → piste →
        // client pour « les tâches du client X ») — le service de recherche
        // joint chaque segment et filtre par identité. FAIL-CLOSED sur
        // l'entité liée aussi (référencer une fiche = la lire). Sans chemin,
        // on liste sans lien et on le signale au modèle (lienIgnore).
        $lien = null;
        $lienIgnore = null;
        $lienCriteria = [];
        $lieA = $args['lieA'] ?? null;
        if (\is_array($lieA) && $lieA !== []) {
            $lienType = (string) ($lieA['entite'] ?? '');
            $lienId = (int) ($lieA['id'] ?? 0);
            $lienFqcn = 'App\\Entity\\' . $lienType;
            if (!isset($labels[$lienType]) || !class_exists($lienFqcn) || $lienId <= 0) {
                $lienIgnore = true;
            } elseif (!$this->accessResolver->canRead($scope->invite, $lienType)) {
                return AiToolResult::horsPerimetre($labels[$lienType]);
            } elseif (($chemin = $this->cheminVers($fqcn, $lienFqcn)) === null) {
                $lienIgnore = true;
            } else {
                $lienCriteria[$chemin] = ['operator' => '=', 'value' => $lienId];
                $lien = ['entite' => $lienType, 'id' => $lienId];
            }
        }

        // Le filtre texte exige un champ de libellé persisté ; sans lui, on
        // liste sans filtrer et on le signale au modèle (filtreIgnore).
        $criteria = ($filtre !== '' && $displayField !== null)
            ? [$displayField => ['operator' => 'LIKE', 'value' => $filtre, 'mode' => 'contains']]
            : [];

        $result = $this->searchService->search($fqcn, $criteria + $lienCriteria, $scope->entreprise, null, $page, self::PAGE_SIZE);
        if (($result['status']['code'] ?? 500) !== 200) {
            return AiToolResult::introuvable($labels[$shortName]);
        }

        $items = [];
        foreach ($result['data'] as $entity) {
            $items[] = [
                'id'      => $entity->getId(),
                'libelle' => $this->libelleur->libelle($entity, $displayField),
            ];
        }

        return AiToolResult::ok(array_filter([
            'entite'       => $shortName,
            'libelle'      => $labels[$shortName],
            'filtre'       => $filtre !== '' ? $filtre : null,
            'filtreIgnore' => ($filtre !== '' && $displayField === null) ? true : null,
            'lien'         => $lien,
            'lienIgnore'   => $lienIgnore,
            'page'         => (int) $result['currentPage'],
            'totalPages'   => (int) $result['totalPages'],
            'totalItems'   => (int) $result['totalItems'],
            'items'        => $items,
        ], static fn ($v) => $v !== null));
    }

    /**
     * PLUS COURT chemin de relations *-vers-un reliant $fqcn à $cibleFqcn
     * (BFS sur les métadonnées Doctrine, profondeur max MAX_PROFONDEUR_LIEN) :
     * « piste » (direct), « piste.client » (petit-fils → grand-père),
     * « cotation.piste »… Générique pour TOUT couple d'entités du workspace —
     * chaque enfant pointant vers son parent en *-vers-un, le chemin remonte
     * naturellement la généalogie père → fils → petit-fils, quel que soit
     * l'objet attaché au contexte. Seuls les segments *-vers-un sont traversés
     * (un segment collection dupliquerait les lignes paginées). Null si aucun
     * chemin dans la profondeur permise.
     */
    private function cheminVers(string $fqcn, string $cibleFqcn): ?string
    {
        $queue = [[$fqcn, []]];
        $visites = [$fqcn => true];

        while ($queue !== []) {
            [$classe, $chemin] = array_shift($queue);
            if (\count($chemin) >= self::MAX_PROFONDEUR_LIEN) {
                continue;
            }
            $metadata = $this->em->getClassMetadata($classe);
            foreach ($metadata->getAssociationNames() as $name) {
                if (!$metadata->isSingleValuedAssociation($name)) {
                    continue;
                }
                $target = $metadata->getAssociationTargetClass($name);
                if ($target === $cibleFqcn) {
                    return implode('.', [...$chemin, $name]);
                }
                if (!isset($visites[$target])) {
                    $visites[$target] = true;
                    $queue[] = [$target, [...$chemin, $name]];
                }
            }
        }

        return null;
    }
}
