<?php

namespace App\Service\Document;

use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ENVOYER UN FICHIER DE PASSAGE, PUIS L'EFFACER — quoi qu'il arrive (chantier 19 bis, 2026-10-23).
 *
 * Pour un fichier produit pour UN téléchargement (état du portefeuille, export préparé) :
 *  - l'effacement est dans un `finally`, avec `ignore_user_abort(true)` : un navigateur qui coupe
 *    en route n'arrête plus PHP avant l'effacement. L'ancien flux (`readfile` puis `unlink`)
 *    laissait le fichier du cabinet sur le serveur à chaque téléchargement interrompu ;
 *  - d'UN SEUL TENANT, jamais par morceaux : la réponse de fichier de Symfony honore l'en-tête
 *    `Range` (206), et un gestionnaire de téléchargement qui découpe aurait recollé des morceaux
 *    de fichiers différents — chaque requête régénère l'état — en un classeur corrompu. Le
 *    premier morceau servi aurait de plus effacé le fichier sous les suivants.
 */
final class EnvoiPuisEffacement
{
    public const TYPE_XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    public static function reponse(string $chemin, string $nomDeTelechargement, string $type = self::TYPE_XLSX): StreamedResponse
    {
        $reponse = new StreamedResponse(static function () use ($chemin): void {
            $avant = ignore_user_abort(true);
            try {
                readfile($chemin);
            } finally {
                @unlink($chemin);
                ignore_user_abort((bool) $avant);
            }
        });
        $reponse->headers->set('Content-Type', $type);
        // Le nom nettoyé et encodé RFC 5987, avec repli ASCII ; `nosniff` + `sandbox` comme toute
        // pièce (chantier 18) — un ZIP de pièces ou un classeur de clients en sont.
        $reponse->headers->set('Content-Disposition', ReponseDePiece::disposition(HeaderUtils::DISPOSITION_ATTACHMENT, $nomDeTelechargement));
        ReponseDePiece::durcir($reponse);
        if (is_file($chemin)) {
            $reponse->headers->set('Content-Length', (string) filesize($chemin));
        }
        $reponse->headers->set('Accept-Ranges', 'none');
        $reponse->headers->set('Cache-Control', 'no-store, private');

        return $reponse;
    }
}
