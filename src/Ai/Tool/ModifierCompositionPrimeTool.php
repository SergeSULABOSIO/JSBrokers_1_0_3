<?php

namespace App\Ai\Tool;

use App\Ai\Scope\AiScope;
use App\Entity\Chargement;
use App\Entity\Cotation;
use App\Services\JSBDynamicSearchService;

/**
 * Outil DÉDIÉ à la composition de la prime d'une cotation (prime nette, frais
 * accessoires, taxes/TVA, frais ARCA…), c.-à-d. les éléments de la collection
 * « chargements ». Sa raison d'être : un schéma PLAT et TYPÉ (composantes = liste
 * requise d'objets {nom, montant, type}) que même un modèle faible en sorties
 * structurées (ex. Gemini flash) remplit de façon fiable — là où le champ
 * générique « collections » de preparer_operations, optionnel et imbriqué, était
 * systématiquement omis (la ventilation restait en prose, la prime à 0).
 *
 * Il n'introduit AUCUNE logique d'écriture : il TRADUIT ses arguments typés en
 * une opération générique (edit Cotation + sous-opérations sur « chargements »)
 * et DÉLÈGUE à preparer_operations (donc au même WorkspaceMutationService :
 * validation, budget, exécution, journal). DRY strict.
 *
 * Idempotence : une composante dont le « nom » existe déjà sur la cotation est
 * MODIFIÉE (pas de doublon, pas de suppression, donc pas de mot de passe requis) ;
 * les nouvelles sont créées. Avec remplacer=true, les chargements existants non
 * repris sont supprimés (là, mot de passe requis, comme toute suppression).
 */
final class ModifierCompositionPrimeTool implements AiToolInterface
{
    public function __construct(
        private readonly PreparerOperationsTool $preparer,
        private readonly JSBDynamicSearchService $searchService,
    ) {
    }

    public function name(): string
    {
        return 'modifier_composition_prime';
    }

    public function description(): string
    {
        return "Enregistre ou modifie la COMPOSITION (ventilation) de la prime d'une cotation : prime "
            . 'nette, frais accessoires, taxes/TVA, frais ARCA, etc. À appeler dès que l\'utilisateur '
            . 'donne ou corrige les montants de la prime d\'une cotation (« la prime nette est 9000, la '
            . 'TVA 1600… », « fixe la composition de la prime »). Fournis cotationId et composantes '
            . '(chaque composante = nom + montant, et éventuellement le type de chargement). L\'outil '
            . 'prépare un PLAN + BUDGET à valider (comme preparer_operations) ; après validation, c\'est '
            . 'TOI qui enregistres. NE tente PAS de mettre ces montants dans les champs de la cotation : '
            . 'ils y seraient ignorés. Récupère l\'id de la cotation via rechercher_entites/lire_fiche.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'cotationId' => [
                    'type' => 'integer',
                    'description' => 'Identifiant de la cotation dont on fixe la composition de prime.',
                ],
                'composantes' => [
                    'type' => 'array',
                    'description' => 'Composantes de la prime (au moins une). Ex. Prime nette 9000, Frais '
                        . 'accessoires 500, TVA 1600, Frais ARCA 200.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'nom' => ['type' => 'string', 'description' => 'Libellé de la composante (ex. "Prime nette", "TVA", "Frais ARCA").'],
                            'montant' => ['type' => 'number', 'description' => 'Montant de la composante, dans la devise de la cotation.'],
                            'type' => ['type' => 'string', 'description' => 'Nom du type de chargement (optionnel), résolu vers un Chargement existant.'],
                        ],
                        'required' => ['nom', 'montant'],
                    ],
                ],
                'remplacer' => [
                    'type' => 'boolean',
                    'description' => 'true = la liste fournie REMPLACE toute la composition (les composantes '
                        . 'existantes non reprises sont supprimées — demandera le mot de passe). false '
                        . '(défaut) = met à jour les composantes de même nom et ajoute les nouvelles.',
                ],
            ],
            'required' => ['cotationId', 'composantes'],
        ];
    }

    /** Chemin simulé neutralisé : la composition relève du LLM réel (comme preparer_operations). */
    public function match(string $question, AiScope $scope): ?array
    {
        return null;
    }

    public function execute(array $args, AiScope $scope): AiToolResult
    {
        $cotationId = (int) ($args['cotationId'] ?? 0);
        $composantes = $args['composantes'] ?? null;
        if ($cotationId <= 0 || !is_array($composantes) || $composantes === []) {
            return AiToolResult::introuvable('composition de prime');
        }

        // Cotation résolue STRICTEMENT dans l'entreprise du scope (fail-closed).
        $cotation = $this->trouverCotation($cotationId, $scope);
        if ($cotation === null) {
            return AiToolResult::introuvable(sprintf('Cotation #%d', $cotationId));
        }

        // Index des chargements existants par nom (pour mise à jour idempotente).
        $existantsParNom = [];
        foreach ($cotation->getChargements() as $ch) {
            $nom = mb_strtolower(trim((string) $ch->getNom()));
            if ($nom !== '' && !isset($existantsParNom[$nom])) {
                $existantsParNom[$nom] = $ch->getId();
            }
        }

        $remplacer = (bool) ($args['remplacer'] ?? false);
        $nomsFournis = [];
        $elements = [];
        $cacheTypes = [];

        foreach ($composantes as $composante) {
            if (!is_array($composante)) {
                continue;
            }
            $nom = trim((string) ($composante['nom'] ?? ''));
            if ($nom === '' || !array_key_exists('montant', $composante)) {
                continue;
            }
            $montant = (float) $composante['montant'];
            $cle = mb_strtolower($nom);
            $nomsFournis[$cle] = true;

            $champs = ['montantFlatExceptionel' => $montant];
            // Type de chargement optionnel : résolu par son nom vers un Chargement existant.
            $typeNom = trim((string) ($composante['type'] ?? ''));
            if ($typeNom !== '') {
                $typeId = $cacheTypes[$typeNom] ??= $this->resoudreType($typeNom, $scope);
                if ($typeId !== null) {
                    $champs['type'] = $typeId;
                }
            }

            if (isset($existantsParNom[$cle])) {
                $elements[] = ['op' => 'edit', 'id' => $existantsParNom[$cle], 'champs' => $champs];
            } else {
                $elements[] = ['op' => 'create', 'champs' => ['nom' => $nom] + $champs];
            }
        }

        if ($elements === []) {
            return AiToolResult::introuvable('composition de prime');
        }

        // Remplacement : supprimer les chargements existants non repris.
        if ($remplacer) {
            foreach ($cotation->getChargements() as $ch) {
                $cle = mb_strtolower(trim((string) $ch->getNom()));
                if (!isset($nomsFournis[$cle])) {
                    $elements[] = ['op' => 'delete', 'id' => $ch->getId()];
                }
            }
        }

        // Traduction en opération générique + délégation au moteur unique.
        $operations = [[
            'op'     => 'edit',
            'entite' => 'Cotation',
            'id'     => $cotationId,
            'collections' => [[
                'collection' => 'chargements',
                'elements'   => $elements,
            ]],
        ]];

        return $this->preparer->execute(['operations' => $operations], $scope);
    }

    /** Cotation de l'entreprise du scope, ou null (fail-closed via le scoping du search). */
    private function trouverCotation(int $id, AiScope $scope): ?Cotation
    {
        $result = $this->searchService->search(Cotation::class, ['id' => $id], $scope->entreprise, null, 1, 1);
        if (($result['status']['code'] ?? 500) !== 200) {
            return null;
        }
        $cotation = $result['data'][0] ?? null;

        return $cotation instanceof Cotation ? $cotation : null;
    }

    /** Résout un type de chargement par son nom (dans l'entreprise), ou null si introuvable/ambigu. */
    private function resoudreType(string $nom, AiScope $scope): ?int
    {
        $result = $this->searchService->search(
            Chargement::class,
            ['nom' => ['operator' => 'LIKE', 'value' => $nom, 'mode' => 'contains']],
            $scope->entreprise,
            null,
            1,
            1,
        );
        if (($result['status']['code'] ?? 500) !== 200) {
            return null;
        }
        $type = $result['data'][0] ?? null;

        return $type instanceof Chargement ? $type->getId() : null;
    }
}
