<?php

namespace App\Ai\Export;

use App\Entity\AssistantMessage;
use App\Entity\Entreprise;
use App\Entity\Utilisateur;
use App\Services\Mail\CorporateMailer;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Address;

/**
 * @file Envoi par e-mail d'un message du chat de l'assistant.
 * @description Calque de SoaClientNotifier : retourne un booléen, ne lève
 * jamais, et laisse l'appelant formuler le message d'interface.
 *
 * Deux partis pris qui protègent la marque et l'utilisateur :
 *  - `replyTo` porte l'adresse du COURTIER, jamais celle de la plateforme : le
 *    destinataire répond à la personne qui lui écrit, pas à JS Brokers ;
 *  - la pièce jointe est tolérante aux pannes (pattern
 *    MailingSubscriber::factureJointe) — un rendu qui échoue ne doit pas faire
 *    perdre l'envoi, il le prive seulement de son document.
 */
class MessageMailNotifier
{
    /** Format « image » : la pièce vient du navigateur, pas de MessageExporter. */
    public const FORMAT_IMAGE = 'image';

    /** Libellé de la pièce jointe affiché dans le corps de l'e-mail. */
    private const FORMAT_LABELS = [
        MessageExporter::FORMAT_PDF => 'PDF',
        MessageExporter::FORMAT_WORD => 'Word (.doc)',
        MessageExporter::FORMAT_MARKDOWN => 'Markdown (.md)',
        self::FORMAT_IMAGE => 'image (PNG)',
    ];

    public function __construct(
        private CorporateMailer $corporateMailer,
        private MessageExporter $exporter,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array{email: string, nom: string, detail: string, horsCarnet?: bool} $destinataire
     * @param string|null $format une valeur de MessageExporter::FORMATS, ou null pour n'envoyer que le corps
     * @param MessageExportFichier|null $pieceFournie pièce DÉJÀ produite (capture d'écran du
     *        navigateur, validée et ré-encodée côté serveur) : elle prime sur $format, car
     *        aucun rendu serveur ne sait reproduire un graphique Chart.js
     *
     * @return bool false = l'envoi a échoué (l'appelant décide du message d'interface)
     */
    public function envoyer(
        AssistantMessage $message,
        Entreprise $entreprise,
        string $assistantNom,
        array $destinataire,
        ?Utilisateur $acteur,
        ?string $format,
        ?string $accompagnement,
        ?MessageExportFichier $pieceFournie = null,
    ): bool {
        $pieces = $pieceFournie !== null
            ? [$pieceFournie->pieceJointe()]
            : $this->pieceJointe($message, $entreprise, $assistantNom, $format);

        try {
            $this->corporateMailer->send(
                $destinataire['email'],
                $this->corporateMailer->buildSubject('Message de ' . $assistantNom, (string) $entreprise->getNom()),
                'emails/assistant_message.html.twig',
                [
                    'assistantNom' => $assistantNom,
                    'entrepriseNom' => (string) $entreprise->getNom(),
                    'expediteurNom' => trim((string) $acteur?->getNom()) !== '' ? (string) $acteur?->getNom() : (string) $entreprise->getNom(),
                    'nomDestinataire' => $destinataire['nom'] ?? '',
                    'dateMessage' => $message->getCreatedAt()?->format('d/m/Y à H:i') ?? '',
                    'corpsHtml' => $this->exporter->corpsHtml($message),
                    'accompagnement' => trim((string) $accompagnement) !== '' ? trim((string) $accompagnement) : null,
                    'pieceJointeNom' => $pieces !== [] ? (self::FORMAT_LABELS[$format] ?? null) : null,
                ],
                // Le destinataire répond au COURTIER, pas à la plateforme.
                $this->replyTo($acteur),
                $pieces,
            );
        } catch (\Throwable $e) {
            $this->logger->error("Assistant IA : l'envoi d'un message par e-mail a échoué.", [
                'exception' => $e,
                'message' => $message->getId(),
                'destinataire' => $destinataire['email'] ?? '?',
            ]);

            return false;
        }

        return true;
    }

    /**
     * Document joint, ou aucun. Tolérant aux pannes : un rendu qui échoue prive
     * l'e-mail de sa pièce jointe, il ne l'annule pas.
     *
     * @return array<int, array{content: string, filename: string, mime: string}>
     */
    private function pieceJointe(AssistantMessage $message, Entreprise $entreprise, string $assistantNom, ?string $format): array
    {
        if ($format === null || !in_array($format, MessageExporter::FORMATS, true)) {
            return [];
        }

        try {
            return [$this->exporter->exporter($message, $format, $entreprise, $assistantNom)->pieceJointe()];
        } catch (\Throwable $e) {
            $this->logger->error("Assistant IA : génération de la pièce jointe d'un message échouée.", [
                'exception' => $e,
                'message' => $message->getId(),
                'format' => $format,
            ]);

            return [];
        }
    }

    private function replyTo(?Utilisateur $acteur): ?Address
    {
        $email = trim((string) $acteur?->getEmail());
        if ($email === '') {
            return null;
        }

        return new Address($email, trim((string) $acteur?->getNom()) !== '' ? (string) $acteur?->getNom() : $email);
    }
}
