<?php

namespace App\Ai\Fichier;

use Symfony\Component\Validator\Constraints\File as FileConstraint;

/**
 * Politique de sécurité (source unique) des fichiers attachables au chat de
 * l'assistant IA : taille maximale, nombre maximal par conversation et formats
 * autorisés. Consommée côté SERVEUR (contrainte Assert\File à l'upload) ET
 * publiée au FRONT (validation JS miroir avant l'envoi) — une seule vérité pour
 * les deux barrières.
 */
final class FichierAttachePolicy
{
    /** Nombre maximal de fichiers attachés à une même conversation. */
    public const MAX_FILES = 5;

    /** Taille maximale par fichier : 10 Mo. */
    public const MAX_SIZE_BYTES = 10 * 1024 * 1024;

    /**
     * Extensions autorisées. La contrainte File de Symfony valide l'extension ET
     * la cohérence du type MIME deviné (barrière contre le renommage trompeur).
     * Phase 1 : pack bureautique large ; l'extraction de texte ne couvre que le
     * texte simple et le PDF (cf. FichierTexteExtracteur), les autres restent
     * attachables/classables sans être lus.
     */
    public const EXTENSIONS = [
        'pdf', 'png', 'jpg', 'jpeg', 'webp', 'txt', 'csv', 'md', 'json', 'docx', 'xlsx',
    ];

    /** Contrainte de validation serveur appliquée à chaque fichier uploadé. */
    public static function contrainte(): FileConstraint
    {
        return new FileConstraint(
            maxSize: self::MAX_SIZE_BYTES,
            extensions: self::EXTENSIONS,
            maxSizeMessage: 'Le fichier « {{ name }} » dépasse la taille maximale autorisée ({{ limit }} {{ suffix }}).',
            extensionsMessage: 'Le format du fichier « {{ name }} » n\'est pas autorisé. Formats acceptés : {{ extensions }}.',
        );
    }

    /** Limites publiées au front pour la validation JS (miroir de la barrière serveur). */
    public static function limitesFront(): array
    {
        return [
            'maxFiles'   => self::MAX_FILES,
            'maxSize'    => self::MAX_SIZE_BYTES,
            'extensions' => self::EXTENSIONS,
        ];
    }
}
