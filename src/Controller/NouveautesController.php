<?php

/**
 * @file Ce fichier contient le contrôleur NouveautesController.
 * @description Expose le journal des mises à jour de la plateforme : ce qui a
 * changé sur les 30 derniers jours, une entrée par livraison (date complète,
 * référence, titre et description de l'amélioration). Le but est que le courtier
 * découvre les évolutions qui améliorent son quotidien sans avoir à les deviner.
 *
 * Route PUBLIQUE, comme les CGU : le lien vit dans le pied de page et sur les
 * écrans de connexion, donc avant toute authentification.
 */

namespace App\Controller;

use App\Services\VersionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class NouveautesController extends AbstractController
{
    /** Fenêtre glissante du journal, en jours. */
    private const FENETRE_JOURS = 30;

    public function __construct(
        private readonly VersionService $versionService,
        private readonly LocaleSwitcher $localeSwitcher,
    ) {}

    #[Route('/nouveautes', name: 'app_changelog')]
    public function index(Request $request, CacheInterface $cache): Response
    {
        // Page publique : la langue ne peut pas dépendre du compte connecté, on
        // l'expose via ?lang= (fr|en) comme les CGU. Elle n'habille que le chrome
        // (pied de page, bascule) : les mises à jour sont rédigées en français.
        $lang = $request->query->get('lang');
        if (!in_array($lang, ['fr', 'en'], true)) {
            $lang = in_array($request->getLocale(), ['fr', 'en'], true) ? $request->getLocale() : 'fr';
        }
        $this->localeSwitcher->setLocale($lang);

        // `git log` est interrogé au runtime : on met le résultat en cache pour ne
        // pas relancer un processus à chaque affichage. La clé porte la version
        // applicative, réestampillée par .githooks/pre-commit à chaque commit :
        // une nouvelle livraison invalide donc l'entrée d'elle-même, sans purge.
        $commits = $cache->get(
            'changelog.' . self::FENETRE_JOURS . 'j.' . $this->versionService->getVersion(),
            function (ItemInterface $item, bool &$save): array {
                $item->expiresAfter(3600);

                $commits = $this->versionService->getRecentCommits(self::FENETRE_JOURS);

                // Un échec ponctuel de git (binaire absent, dépôt verrouillé) ne doit
                // pas figer la page sur son état dégradé pendant une heure : on ne
                // retient que les résultats utiles, et on réessaie sinon.
                $save = $commits !== [];

                return $commits;
            }
        );

        return $this->render('nouveautes/index.html.twig', [
            'pageName' => $lang === 'en' ? 'What\'s new' : 'Nouveautés de la plateforme',
            'lang'     => $lang,
            'commits'  => $commits,
            'jours'    => self::FENETRE_JOURS,
        ]);
    }
}
