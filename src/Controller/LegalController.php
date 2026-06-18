<?php

/**
 * @file Ce fichier contient le contrôleur LegalController.
 * @description Expose les pages légales publiques de JS Brokers. Pour l'instant :
 * 1. `terms()`: Affiche l'intégralité des Conditions et Termes d'Utilisation.
 *    Route PUBLIQUE (aucune authentification requise) afin que les visiteurs,
 *    les futurs inscrits comme les utilisateurs déjà connectés puissent lire
 *    le contrat dans son intégralité, le partager et l'imprimer.
 */

namespace App\Controller;

use App\Legal\Cgu;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

class LegalController extends AbstractController
{
    #[Route('/conditions-utilisation', name: 'app_terms')]
    public function terms(Request $request): Response
    {
        // La page est publique : la langue ne peut donc pas dépendre du compte
        // connecté. On l'expose via un paramètre ?lang= (fr|en), auto-suffisant
        // et fiable pour les visiteurs anonymes ; à défaut, on suit la locale
        // courante de la requête (fr par défaut).
        $lang = $request->query->get('lang');
        if (!in_array($lang, ['fr', 'en'], true)) {
            $lang = in_array($request->getLocale(), ['fr', 'en'], true) ? $request->getLocale() : 'fr';
        }

        return $this->render('legal/conditions_utilisation.html.twig', [
            'pageName'   => $lang === 'en' ? 'Terms and Conditions of Use' : "Conditions et termes d'utilisation",
            'lang'       => $lang,
            'cguVersion' => Cgu::VERSION,
            'cguDate'    => new \DateTimeImmutable(Cgu::DATE),
        ]);
    }
}
