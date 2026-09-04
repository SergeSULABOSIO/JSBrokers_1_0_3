<?php

namespace App\Echange\Service;

/**
 * ÉCRIT un flux de lignes JSON (NDJSON) au fil de l'eau, vers le navigateur.
 *
 * ⚠ POURQUOI DIFFUSER PLUTÔT QUE FAIRE SONDER LE CLIENT.
 *
 * La façon habituelle d'afficher une progression est de lancer le travail d'un côté et
 * d'interroger son état de l'autre, à intervalles réguliers. Cela suppose que deux
 * requêtes puissent être servies EN MÊME TEMPS. Le serveur de développement de ce
 * projet n'a qu'un seul processus PHP : la requête de sondage attendrait sagement la
 * fin de celle qu'elle interroge, et la barre resterait figée jusqu'à ce qu'il n'y ait
 * plus rien à afficher.
 *
 * On envoie donc la progression DANS la requête qui travaille. Une ligne JSON par
 * étape, terminée par une ligne de résultat. Le client lit le flux au fur et à mesure.
 * Aucune infrastructure nouvelle, et cela marche aussi bien avec un processus qu'avec
 * cent.
 *
 * ⚠ LE TAMPON DE SORTIE DOIT ÊTRE VIDÉ À CHAQUE LIGNE. PHP tamponne par défaut
 * (output_buffering=4096) : sans purge explicite, les quarante premières lignes de
 * progression arriveraient d'un bloc, à la fin — c'est-à-dire précisément quand elles
 * ne servent plus à rien.
 */
final class FluxNdjson
{
    /**
     * PRÉPARE la sortie à être diffusée. À appeler une fois, avant la première ligne.
     *
     * ⚠ TROIS TAMPONS SE METTENT EN TRAVERS, et il suffit d'en oublier un pour que
     * toutes les lignes arrivent d'un bloc à la fin — c'est-à-dire précisément quand
     * elles ne servent plus à rien, et la barre reste indéterminée alors que le serveur
     * savait parfaitement où il en était.
     *
     *  1. LA COMPRESSION. zlib accumule pour compresser : elle ne peut pas, par nature,
     *     émettre au fil de l'eau. On la désarme pour cette réponse.
     *  2. LES TAMPONS DE SORTIE de PHP (output_buffering=4096 sur ce poste), qu'on vide
     *     entièrement.
     *  3. LE SEUIL DES INTERMÉDIAIRES. Certains proxys ne transmettent rien avant
     *     d'avoir reçu quelques kilo-octets. On envoie donc un préambule de rembourrage,
     *     sous forme de lignes vides que le client ignore — le format NDJSON s'y prête,
     *     une ligne blanche n'y veut rien dire.
     */
    public static function demarrer(): void
    {
        @ini_set('zlib.output_compression', '0');
        @ini_set('implicit_flush', '1');
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        ob_implicit_flush(true);

        echo str_repeat("\n", 2048);
        @flush();
    }

    /** Écrit une ligne et la pousse jusqu'au navigateur. */
    public static function ligne(array $donnees): void
    {
        echo json_encode($donnees, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), "\n";

        // On vide TOUS les niveaux de tampon, pas seulement le dernier : le serveur de
        // développement en empile parfois plusieurs, et un seul niveau oublié suffit à
        // retenir la ligne.
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        @flush();
    }

    /**
     * En-têtes d'une réponse diffusée.
     *
     * `X-Accel-Buffering: no` désarme le tampon de nginx en production : sans lui, le
     * proxy retiendrait le flux et l'utilisateur retrouverait exactement la barre figée
     * qu'on cherche à supprimer.
     *
     * @return array<string, string>
     */
    public static function entetes(): array
    {
        return [
            'Content-Type'      => 'application/x-ndjson; charset=utf-8',
            'Cache-Control'     => 'no-store, private',
            'X-Accel-Buffering' => 'no',
        ];
    }
}
