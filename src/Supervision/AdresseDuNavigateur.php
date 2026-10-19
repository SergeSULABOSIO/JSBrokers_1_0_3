<?php

namespace App\Supervision;

/**
 * LES ADRESSES QU'UN NAVIGATEUR NOUS RAPPORTE — triées et anonymisées ici, nulle part ailleurs.
 *
 * Servent les deux collectes publiques : erreurs JavaScript (`JournalNavigateurController`)
 * et violations de la CSP (`JournalCspController`).
 *
 * - `estEtrangere()` : ce qui ne vient pas de notre code (extension du navigateur, page
 *   vide, antivirus qui injecte son script). La CSP réduit une adresse non HTTP(S) à son
 *   SCHÉMA NU (« chrome-extension », sans « :// ») : les deux formes sont reconnues.
 * - `anonymisee()` : une adresse sans requête ni fragment, dont les segments secrets (le
 *   jeton du relevé public `/soa/{jeton}`, tout identifiant long) et numériques sont
 *   remplacés. Un jeton n'a rien à faire dans la supervision ni dans un e-mail d'alerte ;
 *   et `/espacedetravail/12/34` ne doit pas faire une ligne par cabinet.
 *
 * Le même filtre vit côté navigateur dans `assets/veille-erreurs.js` (premier tri, non fiable
 * par nature : seul celui-ci fait foi).
 */
final class AdresseDuNavigateur
{
    private const SCHEMAS_ETRANGERS = '#^(chrome-extension|moz-extension|safari-web-extension|safari-extension|webkit-masked-url|about)(:|$)#i';

    /** Kaspersky, vu sur le poste de développement le 2026-10-19 (`http://me.kis.v2.scr.kaspersky-labs.com/…`). */
    private const HOTES_INJECTEURS = '#^https?://[^/]*\.(kaspersky-labs\.com|kaspersky\.com)(/|$)#i';

    /**
     * Un segment de 32 caractères ou plus, fait de lettres, chiffres, « - » ou « _ », dont au
     * moins un chiffre : un jeton (un nom de route, lui, n'a ni cette longueur ni de chiffre).
     */
    private const SEGMENT_SECRET = '#^(?=[^/]*\d)[A-Za-z0-9_-]{32,}$#';

    public static function estEtrangere(string $adresse): bool
    {
        return 1 === preg_match(self::SCHEMAS_ETRANGERS, $adresse)
            || 1 === preg_match(self::HOTES_INJECTEURS, $adresse);
    }

    public static function anonymisee(?string $adresse): ?string
    {
        if (null === $adresse) {
            return null;
        }
        $adresse = (string) preg_replace('/[?#].*$/s', '', $adresse);
        if ('' === $adresse) {
            return null;
        }

        // Le schéma et l'hôte restent tels quels ; seuls les segments du CHEMIN sont examinés.
        $debutDuChemin = preg_match('#^[a-z][a-z0-9+.-]*://[^/]*#i', $adresse, $m) ? \strlen($m[0]) : 0;
        $segments = explode('/', substr($adresse, $debutDuChemin));
        foreach ($segments as $i => $segment) {
            if (1 === preg_match(self::SEGMENT_SECRET, $segment)) {
                $segments[$i] = '{jeton}';
            } elseif ('' !== $segment && ctype_digit($segment)) {
                $segments[$i] = '{id}';
            }
        }

        return substr($adresse, 0, $debutDuChemin) . implode('/', $segments);
    }
}
