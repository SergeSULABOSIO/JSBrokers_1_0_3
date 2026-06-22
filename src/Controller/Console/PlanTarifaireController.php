<?php

namespace App\Controller\Console;

use App\Form\PlanTarifaireType;
use App\Repository\PlateformeParametresRepository;
use App\Token\ParametresTokenService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * Édition du plan tarifaire global de JS Brokers (paquets, allocation gratuite,
 * poids d'écriture/lecture, taux USD). Réservé au super-admin.
 */
#[Route('/console/plan-tarifaire', name: 'console.plan.')]
#[IsGranted('ROLE_SUPER_ADMIN')]
class PlanTarifaireController extends AbstractConsoleController
{
    public function __construct(
        private PlateformeParametresRepository $repository,
        private ParametresTokenService $parametres,
    ) {}

    #[Route('', name: 'index', methods: ['GET', 'POST'])]
    public function index(Request $request, LocaleSwitcher $localeSwitcher): Response
    {
        $this->applyLangPreference($request, $localeSwitcher);

        $params = $this->repository->getSingleton();

        // Pré-remplissage : on montre les valeurs EFFECTIVES (BDD ou, à défaut,
        // constantes), afin que l'agent édite des nombres réels et non des champs vides.
        $params->setFreeAllowance($params->getFreeAllowance() ?? $this->parametres->freeAllowance());
        $params->setFreeWindowHours($params->getFreeWindowHours() ?? $this->parametres->freeWindowHours());
        $params->setReadWeight($params->getReadWeight() ?? $this->parametres->readWeight());
        $params->setDefaultWriteWeight($params->getDefaultWriteWeight() ?? $this->parametres->defaultWriteWeight());
        $params->setUsdPerToken($params->getUsdPerToken() ?? $this->parametres->usdPerToken());

        $jsonOpts = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        $form = $this->createForm(PlanTarifaireType::class, $params, [
            'packs_json'         => json_encode($this->parametres->packs(), $jsonOpts),
            'write_weights_json' => json_encode($this->parametres->writeWeights(), $jsonOpts),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $packs = $this->decodeJsonMap($form, 'packsJson');
            $weights = $this->decodeJsonMap($form, 'writeWeightsJson');

            if ($packs !== false && $weights !== false) {
                $params->setPacks($packs);
                $params->setWriteWeights($weights);
                $this->em->flush();
                $this->parametres->refresh();

                $this->addFlash('success', 'Plan tarifaire mis à jour.');

                return $this->redirectToRoute('console.plan.index');
            }
        }

        return $this->render('console/form.html.twig', [
            'pageName'    => 'Plan tarifaire',
            'form'        => $form,
            'backUrl'     => $this->generateUrl('console.dashboard'),
            'backLabel'   => 'Tableau de bord',
            'submitLabel' => 'Enregistrer le plan',
        ]);
    }

    /**
     * Décode un champ JSON du formulaire en tableau associatif ; ajoute une erreur
     * de formulaire et retourne false si le JSON est invalide.
     *
     * @return array<string, mixed>|false
     */
    private function decodeJsonMap(\Symfony\Component\Form\FormInterface $form, string $champ): array|false
    {
        $brut = (string) $form->get($champ)->getData();
        if (trim($brut) === '') {
            return [];
        }

        $decoded = json_decode($brut, true);
        if (!is_array($decoded)) {
            $form->get($champ)->addError(new \Symfony\Component\Form\FormError('JSON invalide.'));

            return false;
        }

        return $decoded;
    }
}
