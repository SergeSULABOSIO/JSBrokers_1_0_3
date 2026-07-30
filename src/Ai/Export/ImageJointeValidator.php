<?php

namespace App\Ai\Export;

/**
 * @file Validation d'une image de bulle produite par le NAVIGATEUR.
 * @description Le rendu fidèle d'un message — graphiques Chart.js compris —
 * n'existe que dans le navigateur : Chart.js peint dans un <canvas>, qu'aucun
 * rendu serveur ne sait reproduire. Pour joindre cette image à un e-mail, le
 * serveur doit donc accepter un binaire fabriqué par le client — ce qu'il ne
 * fait NULLE PART ailleurs.
 *
 * Cette classe est la contrepartie de cette exception. Elle ne « fait pas
 * confiance » : elle reconstruit. Le flux reçu est décodé, borné, reconnu comme
 * PNG, puis RÉ-ENCODÉ par GD — l'image envoyée n'est jamais celle reçue, mais
 * une image régénérée à partir de ses seuls pixels. Tout ce qui aurait pu être
 * ajouté autour (métadonnées, charge utile concaténée, chunk parasite) est
 * éliminé au passage, y compris ce qui n'aurait pas été anticipé.
 */
class ImageJointeValidator
{
    /** Plafond du flux décodé. Une capture de bulle nette pèse ~1 Mo. */
    public const MAX_OCTETS = 4 * 1024 * 1024;

    /** Bornes de dimensions : au-delà, c'est une image de décompression abusive. */
    public const MAX_LARGEUR = 4000;
    public const MAX_HAUTEUR = 12000;

    /**
     * @param string $donnees flux base64, avec ou sans préfixe `data:image/png;base64,`
     *
     * @throws \InvalidArgumentException message destiné à l'utilisateur
     */
    public function valider(string $donnees, int $idMessage): MessageExportFichier
    {
        $binaire = $this->decoder($donnees);

        if ($binaire === '' || strlen($binaire) > self::MAX_OCTETS) {
            throw new \InvalidArgumentException("L'image du message est absente ou trop volumineuse.");
        }

        // Reconnaissance par le CONTENU, jamais par un type annoncé.
        $infos = @getimagesizefromstring($binaire);
        if ($infos === false || ($infos[2] ?? null) !== IMAGETYPE_PNG) {
            throw new \InvalidArgumentException("L'image du message est illisible.");
        }
        if ($infos[0] > self::MAX_LARGEUR || $infos[1] > self::MAX_HAUTEUR) {
            throw new \InvalidArgumentException("L'image du message dépasse les dimensions autorisées.");
        }

        return new MessageExportFichier(
            $this->reencoder($binaire),
            sprintf('message-ia-%d-%s.png', $idMessage, (new \DateTimeImmutable('now'))->format('Ymd')),
            'image/png'
        );
    }

    /** Décodage strict : un base64 approximatif est un flux qu'on ne veut pas. */
    private function decoder(string $donnees): string
    {
        $donnees = trim($donnees);
        if (str_starts_with($donnees, 'data:')) {
            $virgule = strpos($donnees, ',');
            $donnees = $virgule === false ? '' : substr($donnees, $virgule + 1);
        }

        return (string) base64_decode($donnees, true);
    }

    /**
     * Ré-encodage GD : seuls les PIXELS survivent. C'est ce qui rend l'exception
     * acceptable — le serveur n'expédie pas l'octet reçu, il expédie le sien.
     */
    private function reencoder(string $binaire): string
    {
        $image = @imagecreatefromstring($binaire);
        if ($image === false) {
            throw new \InvalidArgumentException("L'image du message est illisible.");
        }

        try {
            imagesavealpha($image, true);
            ob_start();
            $ok = imagepng($image);
            $png = (string) ob_get_clean();
        } finally {
            imagedestroy($image);
        }

        if (!$ok || $png === '') {
            throw new \InvalidArgumentException("L'image du message n'a pas pu être préparée.");
        }

        return $png;
    }
}
