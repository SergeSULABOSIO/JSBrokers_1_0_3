<?php

namespace App\Security\Csp;

/**
 * LA CONTENT-SECURITY-POLICY DE JOSEARA — écrite ici, et nulle part ailleurs.
 *
 * Le dernier filet : si une donnée piégée échappait un jour à l'échappement, le
 * navigateur refuserait quand même d'exécuter le script qu'elle apporte. Seuls passent
 * nos fichiers (`'self'`) et les `<script>` inline qui portent le nonce de la réponse.
 *
 * ── DEUX CHOIX DÉLIBÉRÉS ────────────────────────────────────────────────────
 * - Le nonce est dans `script-src` SEULEMENT. Dans `style-src`, il y annulerait
 *   `'unsafe-inline'` (un navigateur qui voit un nonce ignore `'unsafe-inline'`), et les
 *   ~1 600 attributs `style=` des gabarits cesseraient de s'appliquer.
 * - `style-src 'unsafe-inline'` reste — CIBLE : le retirer quand les styles inline
 *   auront migré vers des classes. Un style injecté ne vole rien ; un script, si.
 *
 * ── LES RAPPORTS ────────────────────────────────────────────────────────────
 * `report-uri` (tous les navigateurs) ET, en HTTPS seulement, `report-to csp` (Reporting
 * API), vers la même route : `JournalCspController`, qui les verse dans la supervision.
 */
final class PolitiqueCsp
{
    public const ROUTE_DES_RAPPORTS = '/api/journal/csp';
    public const GROUPE_DES_RAPPORTS = 'csp';

    /**
     * APPLIQUÉE depuis le 2026-10-20, sur décision de Serge : la politique a d'abord tourné en
     * Report-Only (commits 1 à 5), sa liste de contrôle a été parcourue en production, et la
     * supervision n'a plus rapporté aucune violation. Repasser à `false` (observation) est le
     * levier de secours si une page cassait — la collecte des rapports continue dans les deux
     * modes.
     */
    public const APPLIQUEE = true;

    public static function nomDeLEntete(): string
    {
        return self::APPLIQUEE ? 'Content-Security-Policy' : 'Content-Security-Policy-Report-Only';
    }

    /**
     * @param bool $securisee la requête est en HTTPS. `report-to` n'y est déclaré qu'alors :
     *                        un navigateur qui le connaît IGNORE `report-uri` quand il est
     *                        présent, et ne l'envoie qu'en HTTPS — en HTTP (poste de
     *                        développement), aucun rapport ne partirait.
     */
    public static function pour(string $nonce, bool $securisee = true): string
    {
        $directives = [
            'default-src' => ["'self'"],
            // Aucun hôte externe : Bootstrap, Quill et le polyfill es-module-shims sont servis
            // par nous (assets/vendor/). Un hôte ajouté ici rouvre la porte à tout ce qu'il sert.
            'script-src' => ["'self'", "'nonce-" . $nonce . "'", "'report-sample'"],
            'style-src' => ["'self'", "'unsafe-inline'"],
            'img-src' => ["'self'", 'data:'],
            'font-src' => ["'self'", 'data:'],
            'connect-src' => ["'self'"],
            'media-src' => ["'self'"],
            'worker-src' => ["'self'"],
            'frame-src' => ["'self'"],
            'object-src' => ["'none'"],
            'base-uri' => ["'self'"],
            'form-action' => ["'self'"],
            'frame-ancestors' => ["'self'"],
            'report-uri' => [self::ROUTE_DES_RAPPORTS],
        ];
        if ($securisee) {
            $directives['report-to'] = [self::GROUPE_DES_RAPPORTS];
        }

        return implode('; ', array_map(
            static fn (string $nom, array $sources): string => $nom . ' ' . implode(' ', $sources),
            array_keys($directives),
            $directives,
        ));
    }

    /** L'en-tête `Reporting-Endpoints` qui nomme le groupe de `report-to`. */
    public static function pointsDeRapport(): string
    {
        return sprintf('%s="%s"', self::GROUPE_DES_RAPPORTS, self::ROUTE_DES_RAPPORTS);
    }
}
