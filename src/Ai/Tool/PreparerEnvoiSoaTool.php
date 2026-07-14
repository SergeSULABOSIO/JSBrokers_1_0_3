<?php

namespace App\Ai\Tool;

use App\Ai\AiText;
use App\Ai\Scope\AiScope;
use App\Entity\Client;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Services\JSBDynamicSearchService;

/**
 * Outil d'ACTION UI : PRÉPARE l'envoi du relevé de compte (SOA) d'un client —
 * l'assistant ouvre la boîte d'envoi existante (picker de destinataires)
 * pré-ciblée sur le client, et n'envoie JAMAIS lui-même : l'utilisateur
 * choisit le destinataire et confirme, l'envoi réel passe par la route POST
 * standard qui re-valide tout (périmètre + destinataires whitelistés).
 *
 * FAIL-CLOSED : même garde que le picker lui-même (lecture Clients), client
 * résolu STRICTEMENT dans l'entreprise du scope (patron VisualiserFicheTool).
 */
final class PreparerEnvoiSoaTool implements AiToolInterface
{
    /** Nombre maximal de candidats restitués sur un nom ambigu. */
    private const MAX_CANDIDATS = 6;

    public function __construct(
        private readonly WorkspaceAccessResolver $accessResolver,
        private readonly JSBDynamicSearchService $searchService,
        private readonly EntiteLibelle $libelleur,
    ) {
    }

    public function name(): string
    {
        return 'preparer_envoi_soa';
    }

    public function description(): string
    {
        return 'Prépare l\'envoi du relevé de compte (SOA) d\'un client : ouvre la boîte d\'envoi '
            . 'de l\'espace de travail pré-ciblée sur le client (par id — fourni par '
            . 'rechercher_entites — ou par nom). Cet outil n\'envoie RIEN : l\'utilisateur choisit '
            . 'le destinataire et confirme lui-même l\'envoi. À appeler quand l\'utilisateur veut '
            . 'envoyer / transmettre le SOA ou le relevé de compte d\'un client.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => [
                    'type' => 'integer',
                    'description' => 'Identifiant du client (prioritaire sur nom).',
                ],
                'nom' => [
                    'type' => 'string',
                    'description' => 'Nom (ou partie du nom) du client, si l\'id est inconnu.',
                ],
            ],
        ];
    }

    /** Chemin simulé : « envoie le SOA (du client) X ». */
    public function match(string $question, AiScope $scope): ?array
    {
        $normalized = AiText::normalize($question);
        if (!preg_match('/\b(envoie[rsz]?|envoyer|transmet[st]?[a-z]*|prepare[rsz]?)\b/', $normalized)) {
            return null;
        }
        if (!preg_match('/\b(soa|releve de compte)\b/', $normalized)) {
            return null;
        }

        if (preg_match('/\b(?:soa|releve de compte)\s+(?:du client |au client |du |de la |de |d )?(.{2,60}?)(?:\s*\?|$)/', $normalized, $m)) {
            $nom = trim($m[1]);
            if ($nom !== '') {
                return ['nom' => $nom];
            }
        }

        return null;
    }

    public function execute(array $args, AiScope $scope): AiToolResult
    {
        // FAIL-CLOSED : même garde que SoaController::envoiPicker (lecture Clients).
        $labels = $this->accessResolver->libellesEntites();
        if (!$this->accessResolver->canRead($scope->invite, 'Client')) {
            return AiToolResult::horsPerimetre($labels['Client'] ?? 'Clients');
        }

        $id = (int) ($args['id'] ?? 0);
        $nom = trim((string) ($args['nom'] ?? ''));
        $displayField = $this->libelleur->displayField(Client::class);

        if ($id > 0) {
            $criteria = ['id' => $id];
        } elseif ($nom !== '' && $displayField !== null) {
            $criteria = [$displayField => ['operator' => 'LIKE', 'value' => $nom, 'mode' => 'contains']];
        } else {
            return AiToolResult::introuvable($labels['Client'] ?? 'Clients');
        }

        // Scoping : le client doit exister DANS l'entreprise du scope.
        $result = $this->searchService->search(Client::class, $criteria, $scope->entreprise, null, 1, self::MAX_CANDIDATS);
        $clients = ($result['status']['code'] ?? 500) === 200 ? $result['data'] : [];
        if ($clients === []) {
            return AiToolResult::introuvable(sprintf('%s « %s »', $labels['Client'] ?? 'Client', $nom !== '' ? $nom : '#' . $id));
        }
        if (count($clients) > 1) {
            return AiToolResult::ok([
                'entite'    => 'Client',
                'libelle'   => $labels['Client'] ?? 'Clients',
                'ambigu'    => true,
                'candidats' => array_map(
                    fn (object $c) => ['id' => $c->getId(), 'libelle' => $this->libelleur->libelle($c, $displayField)],
                    $clients,
                ),
            ]);
        }

        $client = $clients[0];

        return AiToolResult::ok(
            [
                'client' => $this->libelleur->libelle($client, $displayField),
                'note'   => 'La boîte d\'envoi du relevé de compte s\'ouvre : l\'utilisateur choisit '
                    . 'le destinataire et confirme LUI-MÊME l\'envoi.',
            ],
            uiAction: ['type' => 'open-soa-envoi', 'clientId' => $client->getId()],
        );
    }
}
