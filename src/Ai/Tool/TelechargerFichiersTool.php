<?php

namespace App\Ai\Tool;

use App\Ai\AiText;
use App\Ai\Scope\AiScope;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Outil d'ACTION UI : propose à l'utilisateur des boutons de TÉLÉCHARGEMENT des
 * pièces jointes de la conversation, directement dans le chat. Émet une directive
 * d'intention (uiAction 'files-download') que le chat rend en boutons ; chaque
 * bouton pointe vers la route de téléchargement FAIL-CLOSED (scopée entreprise +
 * conversation), le contrôle d'accès restant assuré par cette route — l'outil ne
 * fait que SURFACER le lien sécurisé, il ne diffuse aucun binaire.
 *
 * FAIL-CLOSED : ne liste que les fichiers RÉELLEMENT attachés à la conversation
 * de l'invité (scope). Hors conversation (test / exécution différée) : rien.
 */
final class TelechargerFichiersTool implements AiToolInterface
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function name(): string
    {
        return 'telecharger_fichiers';
    }

    public function description(): string
    {
        return "Propose à l'utilisateur des boutons de téléchargement des FICHIERS attachés à la "
            . 'conversation (les pièces jointes listées dans PIÈCES JOINTES). À appeler quand '
            . "l'utilisateur demande de télécharger, récupérer, obtenir ou avoir le(s) fichier(s) "
            . "joint(s), ou un lien de téléchargement. Par défaut, propose TOUS les fichiers "
            . 'attachés ; renseigne « ids » pour ne proposer que certains d\'entre eux (ids de la '
            . 'section PIÈCES JOINTES). Ne prétends JAMAIS que tu ne peux pas fournir de '
            . 'téléchargement : cet outil affiche des boutons sécurisés à l\'utilisateur.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'ids' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                    'description' => 'Identifiants des pièces jointes à proposer au téléchargement '
                        . '(ids de la section PIÈCES JOINTES). Omets ce champ pour proposer TOUS les '
                        . 'fichiers attachés à la conversation.',
                ],
            ],
        ];
    }

    /** Chemin simulé : verbes de téléchargement + mention d'un fichier / pièce jointe. */
    public function match(string $question, AiScope $scope): ?array
    {
        $normalized = AiText::normalize($question);

        if (!preg_match('/\b(telecharge[rz]?|telechargement|recupere[rz]?|download|obtenir|avoir le fichier|avoir les fichiers|lien[s]? de telechargement)\b/', $normalized)) {
            return null;
        }
        if (!preg_match('/\b(fichier[s]?|piece[s]? jointe[s]?|document[s]?|joint[s]?)\b/', $normalized)) {
            return null;
        }

        return []; // aucun id => tous les fichiers attachés.
    }

    public function execute(array $args, AiScope $scope): AiToolResult
    {
        $conversation = $scope->conversation;
        if ($conversation === null) {
            return AiToolResult::introuvable('aucune conversation active');
        }

        $ids = array_map('intval', (array) ($args['ids'] ?? []));
        $idEntreprise = $scope->entreprise->getId();
        $idConversation = $conversation->getId();

        $pourModele = [];
        $pourUi = [];
        foreach ($conversation->getFichiers() as $fichier) {
            if ($fichier->getId() === null) {
                continue;
            }
            if ($ids !== [] && !in_array($fichier->getId(), $ids, true)) {
                continue;
            }
            $pourModele[] = [
                'id'     => $fichier->getId(),
                'nom'    => $fichier->getNomOriginal(),
                'taille' => $fichier->getTaille(),
            ];
            $pourUi[] = [
                'id'     => $fichier->getId(),
                'nom'    => $fichier->getNomOriginal(),
                'taille' => $fichier->getTaille(),
                'url'    => $this->urlGenerator->generate('admin.assistantia.api.fichier.download', [
                    'idEntreprise'   => $idEntreprise,
                    'idConversation' => $idConversation,
                    'idFichier'      => $fichier->getId(),
                ]),
            ];
        }

        if ($pourUi === []) {
            return AiToolResult::introuvable('aucun fichier attaché à télécharger');
        }

        return AiToolResult::ok(
            [
                'fichiers' => $pourModele,
                'note'     => 'Des boutons de téléchargement sécurisés sont affichés à l\'utilisateur '
                    . 'sous ta réponse. Invite-le simplement à cliquer sur celui qu\'il veut.',
            ],
            uiAction: ['type' => 'files-download', 'fichiers' => $pourUi],
        );
    }
}
