<?php

namespace App\Controller\Api;

use App\Entity\ErreurApplicative;
use App\Entity\Utilisateur;
use App\Supervision\EnregistreurDErreurs;
use App\Supervision\OrigineErreur;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * @file Collecte des erreurs JavaScript (endpoint PUBLIC).
 *
 * @description
 * Reçoit ce que `assets/veille-erreurs.js` observe dans le navigateur des
 * utilisateurs. Publique par nécessité : le JavaScript tourne aussi sur la
 * vitrine et sur la page de connexion, où personne n'est authentifié — et une
 * panne du portail, qui empêche une inscription, est précisément celle qu'on ne
 * veut pas manquer.
 *
 * ── PUBLIQUE, DONC TROIS PROTECTIONS ────────────────────────────────────────
 * Rappel de contexte : `access_control` est vide dans security.yaml, et toute
 * la protection du projet repose sur les attributs #[IsGranted]. Une route sans
 * attribut est donc ouverte à tout Internet.
 *
 * 1. ON NE FAIT PAS CONFIANCE AU CLIENT. Le filtre du navigateur est rejoué
 *    ici, et toutes les longueurs sont plafonnées. Un filtre qui ne vit que
 *    côté client ne filtre rien : il suffit de ne pas l'exécuter.
 * 2. LE DÉBIT EST LIMITÉ, par adresse IP, dans le cache déjà configuré.
 * 3. ELLE N'ÉCHOUE JAMAIS. Réponse 204 quoi qu'il arrive : une route de
 *    signalement qui provoque des erreurs serait une farce.
 *
 * ── CE QUI N'EST JAMAIS LU DANS LA CHARGE UTILE ─────────────────────────────
 * L'utilisateur, le cabinet et la BRANCHE. Tous trois sont résolus côté
 * serveur : autrement, n'importe qui pourrait déguiser une erreur du portail en
 * erreur de la Console et fausser la seule information qui hiérarchise le
 * travail de l'équipe.
 */
#[Route('/api/journal')]
class JournalNavigateurController extends AbstractController
{
    /** Au-delà, une même adresse est ignorée pendant une heure. */
    private const RAPPORTS_PAR_HEURE = 30;

    public function __construct(
        private readonly EnregistreurDErreurs $enregistreur,
        private readonly OrigineErreur $origine,
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    #[Route('/navigateur', name: 'api.journal.navigateur', methods: ['POST'])]
    public function recevoir(Request $request): Response
    {
        // La réponse est TOUJOURS la même — 204, sans corps. Elle ne dit ni si
        // le rapport a été gardé, ni pourquoi il ne l'a pas été : ce serait
        // offrir à qui sonde la route un moyen de découvrir le filtre et de le
        // contourner.
        $silence = new Response('', Response::HTTP_NO_CONTENT);

        try {
            if (!$this->sousLePlafond($request->getClientIp())) {
                return $silence;
            }

            /** @var array<string, mixed> $charge */
            $charge = json_decode($request->getContent(), true) ?: [];

            $message = self::texte($charge['message'] ?? null, 500);
            $fichier = self::texte($charge['fichier'] ?? null, 500);

            if (null === $message || !$this->meriteUnRapport($message, $fichier, $request)) {
                return $silence;
            }

            $utilisateur = $this->getUser();
            $page = self::texte($charge['page'] ?? null, 500);

            $this->enregistreur->enregistrer(
                cote: ErreurApplicative::COTE_NAVIGATEUR,
                branche: $this->origine->depuisUrl($page),
                type: self::texte($charge['type'] ?? null, 180) ?? 'Erreur',
                message: $message,
                fichier: $fichier,
                ligne: self::entier($charge['ligne'] ?? null),
                trace: self::texte($charge['trace'] ?? null, 20000),
                contexte: [
                    'url' => $page,
                    'navigateur' => self::texte($charge['navigateur'] ?? null, 300),
                    'utilisateur' => $utilisateur?->getUserIdentifier(),
                    'cabinet' => $utilisateur instanceof Utilisateur
                        ? $utilisateur->getConnectedTo()?->getNom()
                        : null,
                ],
            );
        } catch (\Throwable) {
            // PROTECTION 3 : rien de ce qui se passe ici ne remonte au client.
        }

        return $silence;
    }

    /**
     * Le filtre du navigateur, rejoué côté serveur.
     *
     * Volontairement redondant : `assets/veille-erreurs.js` l'applique déjà,
     * mais rien n'oblige un appelant à exécuter notre JavaScript.
     */
    private function meriteUnRapport(string $message, ?string $fichier, Request $request): bool
    {
        foreach (['Script error.', 'Script error', 'ResizeObserver loop'] as $bruit) {
            if (str_contains($message, $bruit)) {
                return false;
            }
        }

        if (null === $fichier || '' === $fichier) {
            return true;
        }

        foreach (['chrome-extension://', 'moz-extension://', 'safari-web-extension://', 'safari-extension://', 'webkit-masked-url:', 'about:blank'] as $etranger) {
            if (str_starts_with($fichier, $etranger)) {
                return false;
            }
        }

        // Un script servi par un autre domaine n'est pas notre code.
        if (preg_match('#^https?://#i', $fichier)) {
            return str_starts_with($fichier, $request->getSchemeAndHttpHost());
        }

        return true;
    }

    /**
     * Limitation de débit, par adresse et par heure.
     *
     * Le cache de l'application plutôt que symfony/rate-limiter : le composant
     * n'est pas installé, et ajouter une dépendance à une branche de Symfony en
     * fin de vie pour un compteur de trente coûterait plus cher que ce qu'il
     * rapporte. Le projet a déjà ce motif dans src/Ai/Debit/BudgetDebit.php.
     *
     * Un cache indisponible ne bloque RIEN : on préfère un rapport de trop à un
     * rapport perdu.
     */
    private function sousLePlafond(?string $ip): bool
    {
        if (null === $ip) {
            return true;
        }

        try {
            $cle = 'supervision.debit.' . sha1($ip);
            $item = $this->cache->getItem($cle);
            $compte = (int) ($item->get() ?? 0);

            if ($compte >= self::RAPPORTS_PAR_HEURE) {
                return false;
            }

            $item->set($compte + 1);
            $item->expiresAfter(3600);
            $this->cache->save($item);

            return true;
        } catch (\Throwable) {
            return true;
        }
    }

    private static function texte(mixed $valeur, int $max): ?string
    {
        if (!is_string($valeur) || '' === trim($valeur)) {
            return null;
        }

        return mb_strlen($valeur) > $max ? mb_substr($valeur, 0, $max - 1) . '…' : $valeur;
    }

    private static function entier(mixed $valeur): ?int
    {
        return is_numeric($valeur) ? (int) $valeur : null;
    }
}
