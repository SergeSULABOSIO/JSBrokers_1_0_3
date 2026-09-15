<?php

namespace App\Supervision;

use App\Entity\ErreurApplicative;

/**
 * @file À quelle branche de Joseara appartient une erreur.
 *
 * @description
 * Joseara est trois applications sous un même toit : le PORTAIL que voit un
 * prospect, le WORKSPACE où travaille un cabinet de courtage, et la CONSOLE où
 * travaille l'équipe Joseara. Une même exception n'a pas la même urgence selon
 * l'endroit où elle tombe : sur le portail elle empêche une inscription, dans
 * la Console elle ne gêne que nous.
 *
 * ── LA BRANCHE EST DÉDUITE, JAMAIS DÉCLARÉE ─────────────────────────────────
 * Elle se lit dans le nom de route (côté serveur) ou dans le chemin de la page
 * (côté navigateur) — deux choses que le serveur connaît de lui-même. Accepter
 * une branche envoyée par le client reviendrait à laisser n'importe qui
 * déguiser une erreur du portail en erreur de la Console, et fausser la seule
 * information qui hiérarchise le travail.
 */
final class OrigineErreur
{
    /**
     * La branche, d'après le nom de la route Symfony.
     *
     * Les préfixes sont ceux du projet : « console. » pour la Console,
     * « admin. » pour l'espace de travail. Tout le reste — vitrine, connexion,
     * inscription, pages légales, relevé public — est du portail.
     */
    public function depuisNomDeRoute(?string $route): string
    {
        if ($route === null || $route === '') {
            return ErreurApplicative::BRANCHE_PORTAIL;
        }

        if (str_starts_with($route, 'console.')) {
            return ErreurApplicative::BRANCHE_CONSOLE;
        }

        if (str_starts_with($route, 'admin.')) {
            return ErreurApplicative::BRANCHE_WORKSPACE;
        }

        return ErreurApplicative::BRANCHE_PORTAIL;
    }

    /**
     * La branche, d'après le chemin de la page où l'erreur est survenue.
     *
     * Utilisé pour les erreurs du navigateur : le JavaScript n'a pas de nom de
     * route, seulement une URL. Les préfixes de chemin sont le miroir des
     * préfixes de route ci-dessus — « /espacedetravail » s'y ajoute, car c'est
     * le chemin réel de l'espace de travail.
     */
    public function depuisUrl(?string $url): string
    {
        if ($url === null || $url === '') {
            return ErreurApplicative::BRANCHE_PORTAIL;
        }

        $chemin = (string) (parse_url($url, \PHP_URL_PATH) ?: $url);

        if (str_starts_with($chemin, '/console')) {
            return ErreurApplicative::BRANCHE_CONSOLE;
        }

        if (str_starts_with($chemin, '/admin') || str_starts_with($chemin, '/espacedetravail')) {
            return ErreurApplicative::BRANCHE_WORKSPACE;
        }

        return ErreurApplicative::BRANCHE_PORTAIL;
    }
}
