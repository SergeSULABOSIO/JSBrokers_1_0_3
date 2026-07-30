<?php

namespace App\Ai\Export;

/**
 * @file Fichier produit par l'export d'un message de l'assistant.
 * @description Transporte les octets, le nom et le type MIME, que le document
 * parte en téléchargement ou en pièce jointe d'e-mail. Généré à la volée,
 * jamais stocké — comme la facture PDF (TokenInvoicePdfService).
 *
 * `pieceJointe()` est la couture DRY du lot : c'est LE seul endroit qui connaît
 * la forme attendue par CorporateMailer::send($attachments). Sans elle, le
 * triplet content/filename/mime serait réécrit à la main dans le notifier et
 * dériverait tôt ou tard de la route de téléchargement.
 */
final readonly class MessageExportFichier
{
    public function __construct(
        public string $contenu,
        public string $nomFichier,
        public string $mime,
    ) {
    }

    /**
     * Forme EXACTE attendue par CorporateMailer::send().
     *
     * @return array{content: string, filename: string, mime: string}
     */
    public function pieceJointe(): array
    {
        return [
            'content' => $this->contenu,
            'filename' => $this->nomFichier,
            'mime' => $this->mime,
        ];
    }
}
