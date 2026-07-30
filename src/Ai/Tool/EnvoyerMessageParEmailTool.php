<?php

namespace App\Ai\Tool;

use App\Ai\AiText;
use App\Ai\Scope\AiScope;
use App\Ai\Export\MessageMailNotifier;
use App\Ai\Export\MessageExporter;
use App\Entity\AssistantMessage;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Outil d'ACTION UI : envoie une réponse du fil PAR E-MAIL aux adresses données
 * directement dans la conversation — raccourci du picker de destinataires,
 * quand l'utilisateur connaît déjà l'adresse (« envoie ce message à
 * infos@js-brokers.com »).
 *
 * ── Pourquoi cet outil n'envoie pas lui-même ────────────────────────────────
 * Le format par DÉFAUT est l'IMAGE, pour que le destinataire reçoive le message
 * tel qu'il s'affiche — graphiques compris. Or un graphique Chart.js vit dans un
 * <canvas> : seul le navigateur sait le rasteriser. L'outil émet donc une
 * `uiAction` que le chat exécute (capture de la bulle, puis POST vers la route
 * d'envoi standard). Ce détour n'est pas une faiblesse : il fait passer l'envoi
 * par le circuit qui re-valide TOUT côté serveur — format des adresses, plafond
 * par message, marquage « hors carnet », journalisation, un e-mail par
 * destinataire.
 *
 * FAIL-CLOSED : l'outil ne lit aucune donnée métier (il ne consulte ni le carnet
 * d'adresses ni une entité), il ne fait que désigner un message DÉJÀ affiché à
 * l'utilisateur dans SA conversation. Il n'y a donc rien à autoriser en lecture ;
 * le contrôle d'accès réel (module IA, premium, appartenance du message) vit sur
 * la route d'envoi, qui reste seule à décider.
 */
final class EnvoyerMessageParEmailTool implements AiToolInterface
{
    /** Bornes : au-delà, c'est une diffusion de masse, pas un envoi ciblé. */
    private const MAX_DESTINATAIRES = 10;

    /** Formats acceptés. L'image est le défaut : elle préserve le rendu. */
    private const FORMATS = [
        MessageMailNotifier::FORMAT_IMAGE,
        MessageExporter::FORMAT_PDF,
        MessageExporter::FORMAT_WORD,
        MessageExporter::FORMAT_MARKDOWN,
    ];

    public function __construct(
        private readonly ValidatorInterface $validator,
    ) {
    }

    public function name(): string
    {
        return 'envoyer_message_par_email';
    }

    public function description(): string
    {
        return 'Envoie par e-mail TA réponse précédente (ou le message cité) aux adresses '
            . 'indiquées par l\'utilisateur dans la conversation. À appeler quand il demande '
            . 'd\'envoyer / transmettre / faire suivre un message à une ou plusieurs adresses '
            . 'e-mail qu\'il fournit lui-même. Le destinataire reçoit le message en PIÈCE JOINTE '
            . 'IMAGE par défaut, ce qui préserve exactement la mise en forme et les graphiques ; '
            . 'ne précise un autre format (pdf, word, markdown) que si l\'utilisateur le demande. '
            . 'Un e-mail distinct part vers chaque destinataire. Si l\'utilisateur ne donne AUCUNE '
            . 'adresse, n\'appelle pas cet outil : propose-lui plutôt le carnet de contacts.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'destinataires' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Adresses e-mail données par l\'utilisateur (1 à ' . self::MAX_DESTINATAIRES . ').',
                ],
                'format' => [
                    'type' => 'string',
                    'enum' => self::FORMATS,
                    'description' => 'Format de la pièce jointe. Par défaut « image » : rendu fidèle du message.',
                ],
                'message' => [
                    'type' => 'string',
                    'description' => 'Mot d\'accompagnement facultatif, placé en tête de l\'e-mail.',
                ],
            ],
            'required' => ['destinataires'],
        ];
    }

    /** Chemin simulé : « envoie ce message à x@y.z (et a@b.c) ». */
    public function match(string $question, AiScope $scope): ?array
    {
        $normalized = AiText::normalize($question);
        if (!preg_match('/\b(envoie[rsz]?|envoyer|transmet[st]?[a-z]*|fais suivre|partage[rz]?)\b/', $normalized)) {
            return null;
        }
        // Des adresses doivent être présentes DANS la demande : sans elles, c'est
        // le picker de contacts qu'il faut, pas cet outil.
        if (!preg_match_all('/[\w.+-]+@[\w-]+\.[\w.-]+/', $question, $m)) {
            return null;
        }

        $args = ['destinataires' => array_slice(array_unique($m[0]), 0, self::MAX_DESTINATAIRES)];
        foreach ([MessageExporter::FORMAT_PDF => 'pdf', MessageExporter::FORMAT_WORD => 'word', MessageExporter::FORMAT_MARKDOWN => 'markdown'] as $format => $motCle) {
            if (str_contains($normalized, $motCle)) {
                $args['format'] = $format;
                break;
            }
        }

        return $args;
    }

    public function execute(array $args, AiScope $scope): AiToolResult
    {
        $conversation = $scope->conversation;
        if ($conversation === null) {
            return AiToolResult::introuvable('Aucune conversation en cours.');
        }

        $cible = $this->messageCible($conversation->getMessages()->toArray());
        if ($cible === null) {
            return AiToolResult::introuvable(
                "Aucun message à envoyer : il faut d'abord une réponse dans le fil."
            );
        }

        [$adresses, $rejetees] = $this->adresses($args['destinataires'] ?? []);
        if ($adresses === []) {
            return AiToolResult::introuvable(
                $rejetees === []
                    ? "Aucune adresse e-mail fournie."
                    : sprintf('Adresse e-mail invalide : %s', implode(', ', $rejetees))
            );
        }

        $format = in_array($args['format'] ?? null, self::FORMATS, true)
            ? $args['format']
            : MessageMailNotifier::FORMAT_IMAGE;

        return AiToolResult::ok(
            [
                'destinataires' => $adresses,
                'format' => $format,
                'rejetees' => $rejetees,
                'idMessage' => $cible->getId(),
                // Restitué au modèle pour qu'il annonce l'envoi sans le réinventer.
                'description' => sprintf(
                    "L'envoi de ce message est lancé vers %d destinataire(s), en pièce jointe %s. "
                    . "Un e-mail distinct part vers chacun. Annonce-le brièvement ; ne prétends pas "
                    . "que l'envoi a déjà abouti, la confirmation s'affiche dans le fil.",
                    \count($adresses),
                    $format === MessageMailNotifier::FORMAT_IMAGE ? 'image (rendu fidèle)' : $format
                ),
            ],
            [
                'type' => 'assistant:message.envoyer-direct',
                'idMessage' => $cible->getId(),
                'destinataires' => $adresses,
                'format' => $format,
                'message' => trim((string) ($args['message'] ?? '')),
            ]
        );
    }

    /**
     * Message à envoyer : celui que l'utilisateur CITE s'il a utilisé
     * « Répondre » (intention explicite), sinon la dernière réponse de
     * l'assistant — c'est ce que « ce message » désigne dans le fil.
     *
     * Le message utilisateur en cours n'est pas encore enregistré : son id est
     * nul, d'où le filtrage sur les messages réellement persistés.
     *
     * @param array<int, AssistantMessage> $messages
     */
    private function messageCible(array $messages): ?AssistantMessage
    {
        $courant = end($messages) ?: null;
        $cite = $courant?->getRepondA();
        if ($cite !== null && $cite->getId() !== null) {
            return $cite;
        }

        for ($i = \count($messages) - 1; $i >= 0; --$i) {
            $message = $messages[$i];
            if ($message->getRole() === AssistantMessage::ROLE_ASSISTANT && $message->getId() !== null) {
                return $message;
            }
        }

        return null;
    }

    /**
     * Adresses retenues et adresses rejetées. La validation de FORME est faite
     * ici pour que Ket puisse le dire tout de suite ; la route d'envoi la refait
     * de son côté — c'est elle qui fait autorité.
     *
     * @param mixed $brutes
     * @return array{0: array<int, string>, 1: array<int, string>}
     */
    private function adresses(mixed $brutes): array
    {
        if (is_string($brutes)) {
            $brutes = [$brutes];
        }
        if (!is_array($brutes)) {
            return [[], []];
        }

        $retenues = [];
        $rejetees = [];
        $vues = [];
        foreach ($brutes as $brute) {
            if (!is_scalar($brute)) {
                continue;
            }
            $email = trim((string) $brute);
            $cle = mb_strtolower($email);
            if ($email === '' || isset($vues[$cle])) {
                continue;
            }
            $vues[$cle] = true;

            $erreurs = $this->validator->validate($email, [new Assert\Email(mode: Assert\Email::VALIDATION_MODE_STRICT)]);
            if (\count($erreurs) > 0) {
                $rejetees[] = $email;
                continue;
            }
            if (\count($retenues) < self::MAX_DESTINATAIRES) {
                $retenues[] = $email;
            }
        }

        return [$retenues, $rejetees];
    }
}
