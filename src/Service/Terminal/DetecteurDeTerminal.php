<?php

namespace App\Service\Terminal;

use Symfony\Component\HttpFoundation\Request;

/**
 * @file Qui décide du type de terminal, et dans quel ordre.
 * @description La surface servie (coquille Ket ou espace de travail complet)
 * est choisie par le SERVEUR, avant le premier rendu. Décider dans le
 * navigateur reviendrait à télécharger et peindre la mauvaise coquille d'abord.
 *
 * ── L'ORDRE DES INDICES, DU PLUS FIABLE AU MOINS FIABLE ────────────────────
 *  1. `?terminal=` dans l'URL — une intention explicite, écrite à la main. Sert
 *     au support (« ouvre ce lien pour voir ce que voit ton client ») et aux
 *     tests de bout en bout.
 *  2. le cookie — un choix DÉJÀ fait, par l'utilisateur (« Afficher la version
 *     ordinateur ») ou par la sonde du navigateur.
 *  3. le client hint `Sec-CH-UA-Mobile` — déclaré par le navigateur lui-même,
 *     donc sans analyse de chaîne ni devinette. Uniquement en preuve POSITIVE :
 *     `?1` prouve un mobile, mais `?0` ne prouve pas un ordinateur (une
 *     tablette Android répond `?0`).
 *  4. le `User-Agent` — en dernier recours, parce qu'il ment. iPadOS moderne se
 *     déclare « Macintosh » : aucune analyse d'UA ne peut le reconnaître. C'est
 *     précisément le trou que la sonde du navigateur vient boucher (elle écrit
 *     le cookie, donc l'indice n° 2, sur les pages publiques — avant que
 *     l'utilisateur n'atteigne son espace de travail).
 *
 * ── CE N'EST PAS UNE GARDE DE SÉCURITÉ ─────────────────────────────────────
 * Le terminal ne donne ni ne retire aucun droit : il choisit un RENDU. Un
 * cookie forgé ne fait que changer la surface qu'on se sert à soi-même. Tous
 * les contrôles de périmètre restent où ils sont.
 */
final class DetecteurDeTerminal
{
    /**
     * Le cookie qui mémorise le terminal. ⚠ Ce nom est écrit DEUX FOIS : ici et
     * dans la sonde inline de `base.html.twig`, qui doit pouvoir l'écrire sans
     * passer par le serveur. `TerminalDetectionTest` vérifie la concordance.
     */
    public const COOKIE = 'jsb_terminal';

    /** Le paramètre d'URL qui force un terminal (support, tests E2E). */
    public const PARAM = 'terminal';

    /** Un an : ce choix n'a pas de raison d'expirer avant le renouvellement de l'appareil. */
    public const DUREE_COOKIE = 31536000;

    /**
     * Le terminal de cette requête, et rien d'autre — pas de mémoire, pas
     * d'effet de bord. La persistance du choix est l'affaire de
     * `TerminalCookieSubscriber`, son partage au sein d'une requête celle de
     * `TerminalContext`.
     */
    public function detecter(Request $request): Terminal
    {
        return $this->choixExplicite($request)
            ?? $this->depuisClientHints($request)
            ?? $this->depuisUserAgent((string) $request->headers->get('User-Agent', ''));
    }

    /**
     * CE QUE L'APPAREIL EST, ABSTRACTION FAITE DE CE QU'ON A DEMANDÉ.
     *
     * Une fois le cookie « ordinateur » posé sur une tablette, `detecter()` répond
     * « ordinateur » — c'est bien son rôle. Mais il faut alors pouvoir proposer le
     * RETOUR au mode conversation, et cette proposition doit nommer le bon
     * terminal : « mobile » sur un téléphone, « tablette » sur une tablette.
     *
     * C'est le seul usage de cette méthode : elle sert à construire le lien de
     * retour, et à ne PAS l'afficher sur un vrai ordinateur — où il n'aurait
     * aucun sens et ne ferait qu'encombrer la navigation.
     */
    public function detecterSansChoix(Request $request): Terminal
    {
        return $this->depuisClientHints($request)
            ?? $this->depuisUserAgent((string) $request->headers->get('User-Agent', ''));
    }

    /**
     * Le terminal a-t-il été CHOISI (URL ou cookie) plutôt que deviné ?
     *
     * La sonde du navigateur s'en sert pour se taire : corriger un choix
     * explicite reviendrait à retirer à l'utilisateur la bascule qu'on vient de
     * lui donner. Un utilisateur qui demande la version ordinateur sur sa
     * tablette doit la garder, y compris après un rechargement.
     */
    public function estFige(Request $request): bool
    {
        return $this->choixExplicite($request) !== null;
    }

    private function choixExplicite(Request $request): ?Terminal
    {
        return Terminal::depuisValeur($request->query->get(self::PARAM))
            ?? Terminal::depuisValeur($request->cookies->get(self::COOKIE));
    }

    /**
     * `Sec-CH-UA-Mobile: ?1` = mobile, de la bouche du navigateur. Combiné à
     * `Sec-CH-UA-Platform`, un `?0` sur Android désigne une tablette — la seule
     * façon fiable de nommer une tablette Android, dont l'UA ne se distingue
     * d'un téléphone que par l'ABSENCE du mot « Mobile ».
     */
    private function depuisClientHints(Request $request): ?Terminal
    {
        $mobile = $request->headers->get('Sec-CH-UA-Mobile');
        if ($mobile === null) {
            return null;
        }

        if (str_contains($mobile, '?1')) {
            return Terminal::MOBILE;
        }

        $plateforme = strtolower(trim((string) $request->headers->get('Sec-CH-UA-Platform', ''), '" '));
        if ($plateforme === 'android') {
            return Terminal::TABLETTE;
        }

        // `?0` sur une plateforme de bureau : c'est bien un ordinateur, et le
        // navigateur l'affirme. On ne descend pas jusqu'à l'UA pour le redire.
        return in_array($plateforme, ['windows', 'macos', 'linux', 'chrome os', 'chromium os'], true)
            ? Terminal::ORDINATEUR
            : null;
    }

    /**
     * Analyse du `User-Agent`. Volontairement courte : on ne cherche pas à
     * reconnaître tous les appareils du monde, seulement à ne pas se tromper
     * sur ceux qui comptent. Tout ce qui n'est pas reconnu est un ordinateur —
     * c'est le repli qui rend l'application ENTIÈRE, et la sonde corrigera.
     */
    private function depuisUserAgent(string $ua): Terminal
    {
        if ($ua === '') {
            return Terminal::ORDINATEUR;
        }

        // Les tablettes AVANT les téléphones : l'UA d'un iPad contient
        // « Mobile », et l'ordre inverse en ferait un téléphone.
        if (preg_match('/iPad|Tablet|PlayBook|Silk|Kindle|Nexus (?:7|9|10)/i', $ua)) {
            return Terminal::TABLETTE;
        }

        // Android sans « Mobile » = tablette (convention Google, respectée par
        // Chrome et Firefox Android depuis toujours).
        if (preg_match('/Android/i', $ua) && !preg_match('/Mobile/i', $ua)) {
            return Terminal::TABLETTE;
        }

        if (preg_match('/iPhone|iPod|Android|Windows Phone|IEMobile|BlackBerry|BB10|webOS|Opera Mini|Mobi/i', $ua)) {
            return Terminal::MOBILE;
        }

        return Terminal::ORDINATEUR;
    }
}
