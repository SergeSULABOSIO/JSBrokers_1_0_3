<?php

namespace App\EventListener;

use App\Security\Csp\NonceCsp;
use App\Security\Csp\PolitiqueCsp;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * POSE LA CONTENT-SECURITY-POLICY sur chaque page HTML (`PolitiqueCsp`).
 *
 * Ici et non dans `.htaccess` : le nonce change à chaque requête, seul PHP le connaît.
 *
 * - Requête principale seulement : une sous-requête partage la page de sa requête mère.
 * - Réponses HTML seulement : la CSP gouverne un DOCUMENT ; un JSON, un PDF, un flux de
 *   Ket n'en ont que faire.
 * - Jamais sur une réponse en cache public (icônes) : un nonce mis en cache serait servi
 *   à tous, il ne serait plus un secret.
 *
 * Priorité -10 : APRÈS `ResponseListener` (0), qui pose le `Content-Type` par défaut, et
 * AVANT la barre de débogage (-128), qui ajoute ses propres nonces à la politique trouvée.
 */
#[AsEventListener(event: KernelEvents::RESPONSE, priority: -10)]
final class PolitiqueCspListener
{
    public function __construct(private readonly NonceCsp $nonce)
    {
    }

    public function __invoke(ResponseEvent $evenement): void
    {
        $reponse = $evenement->getResponse();
        if (!$evenement->isMainRequest()
            || !str_starts_with((string) $reponse->headers->get('Content-Type'), 'text/html')
            || $reponse->headers->hasCacheControlDirective('public')) {
            return;
        }

        $securisee = $evenement->getRequest()->isSecure();
        $reponse->headers->set(PolitiqueCsp::nomDeLEntete(), PolitiqueCsp::pour($this->nonce->valeur(), $securisee));
        if ($securisee) {
            $reponse->headers->set('Reporting-Endpoints', PolitiqueCsp::pointsDeRapport());
        }
    }
}
