<?php

namespace App\Ai\Tool;

use App\Ai\Action\TypeAction;

use App\Ai\Scope\AiScope;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Services\JSBDynamicSearchService;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Outil d'ACTION UI : propose des boutons de TÉLÉCHARGEMENT de DOCUMENTS
 * enregistrés en base (entité Document) directement dans le chat, à partir de
 * leurs identifiants (obtenus via rechercher_entites / lire_fiche entite=Document).
 * COMPLÉMENT de telecharger_fichiers, qui ne couvre QUE les pièces jointes de la
 * conversation : ici, ce sont les documents du portefeuille/de la base.
 *
 * Émet la même directive 'files-download' que telecharger_fichiers (le chat rend
 * des boutons) ; chaque bouton pointe vers la route de téléchargement FAIL-CLOSED
 * (module IA + droit de lecture Document + scoping entreprise re-vérifiés au clic).
 *
 * FAIL-CLOSED : refuse si l'invité n'a pas la lecture sur Document ; n'expose que
 * les documents de SON entreprise portant réellement un fichier.
 */
final class TelechargerDocumentsTool implements AiToolInterface
{
    public function __construct(
        private readonly WorkspaceAccessResolver $accessResolver,
        private readonly JSBDynamicSearchService $searchService,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function name(): string
    {
        return 'telecharger_documents';
    }

    public function description(): string
    {
        return "Propose à l'utilisateur des boutons de téléchargement de DOCUMENTS enregistrés en base "
            . "(entité Document, ex. les documents d'un avenant, d'un client, d'un sinistre). À utiliser "
            . "quand l'utilisateur veut télécharger/récupérer un ou des documents de la base par leurs "
            . 'identifiants (obtiens-les d\'abord via rechercher_entites ou lire_fiche entite=Document). '
            . 'Différent de telecharger_fichiers, qui ne concerne QUE les pièces jointes de la conversation. '
            . 'Ne prétends jamais ne pas pouvoir fournir le téléchargement d\'un document dont tu as l\'id.';
    }

    public function aiguillage(): string
    {
        return 'Le téléchargement d\'un DOCUMENT enregistré en base (entité Document : documents d\'un avenant, '
            . 'd\'un client, d\'un sinistre). Récupère d\'abord les id via rechercher_entites ou lire_fiche '
            . '(entite=Document). J\'affiche des boutons de téléchargement sécurisés sous ta réponse.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'ids' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer', 'minimum' => 1],
                    'description' => 'Identifiants des Documents (entité Document) à proposer au téléchargement.',
                ],
            ],
            'required' => ['ids'],
        ];
    }

    /** LLM-only : l'extraction d'identifiants depuis le langage naturel n'est pas simulée. */
    public function match(string $question, AiScope $scope): ?array
    {
        return null;
    }

    public function execute(array $args, AiScope $scope): AiToolResult
    {
        // FAIL-CLOSED : lecture requise sur Document (même contrat que la rubrique).
        if (!$this->accessResolver->canRead($scope->invite, 'Document')) {
            return AiToolResult::horsPerimetre('Documents');
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($args['ids'] ?? [])), static fn (int $id) => $id > 0)));
        if ($ids === []) {
            return AiToolResult::introuvable('aucun identifiant de document fourni');
        }

        $pourModele = [];
        $pourUi = [];
        foreach ($ids as $id) {
            // Scoping : le document doit exister DANS l'entreprise de l'invité.
            $result = $this->searchService->search('App\\Entity\\Document', ['id' => $id], $scope->entreprise, null, 1, 1);
            $document = $result['data'][0] ?? null;
            if (($result['status']['code'] ?? 500) !== 200 || $document === null || $document->getNomFichierStocke() === null) {
                continue; // introuvable, hors entreprise ou sans fichier physique : ignoré.
            }
            $pourModele[] = ['id' => $id, 'nom' => $document->getNom()];
            $pourUi[] = [
                'id'  => $id,
                'nom' => $document->getNom(),
                'url' => $this->urlGenerator->generate('admin.assistantia.api.document.download', [
                    'idEntreprise' => $scope->entreprise->getId(),
                    'idDocument'   => $id,
                ]),
            ];
        }

        if ($pourUi === []) {
            return AiToolResult::introuvable('aucun document téléchargeable pour ces identifiants');
        }

        return AiToolResult::ok(
            [
                'documents' => $pourModele,
                'note'      => 'Des boutons de téléchargement sécurisés sont affichés à l\'utilisateur '
                    . 'sous ta réponse. Invite-le à cliquer sur celui qu\'il veut.',
            ],
            uiAction: ['type' => TypeAction::TELECHARGER_FICHIERS->value, 'fichiers' => $pourUi],
        );
    }
}
