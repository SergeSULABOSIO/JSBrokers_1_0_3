<?php

namespace App\Controller\Console\Crm;

use App\Controller\Console\AbstractConsoleController;
use App\Crm\CrmCampagneService;
use App\Crm\CrmPipelineService;
use App\Entity\Crm\CrmCampagne;
use App\Repository\Crm\CrmCampagneRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * Marketing : campagnes ciblées (onboarding, recharge, réactivation, upsell).
 * Le segment est défini par étapes de pipeline et/ou couleurs de santé ; l'envoi
 * passe par CorporateMailer.
 */
#[Route('/console/crm/campagnes', name: 'console.crm.campagne.')]
#[IsGranted('ROLE_ADMIN')]
class CrmCampagneController extends AbstractConsoleController
{
    public function __construct(
        private CrmCampagneRepository $campagneRepository,
        private CrmCampagneService $campagneService,
        private CrmPipelineService $pipeline,
    ) {
    }

    #[Route('', name: 'index')]
    public function index(Request $request, LocaleSwitcher $localeSwitcher): Response
    {
        $this->applyLangPreference($request, $localeSwitcher);

        return $this->render('console/crm/campagne/index.html.twig', [
            'pageName'  => 'CRM — Marketing',
            'pageIcon'  => 'action:premium',
            'campagnes' => $this->campagneRepository->paginateAll($request->query->getInt('page', 1)),
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request, LocaleSwitcher $localeSwitcher): Response
    {
        $this->applyLangPreference($request, $localeSwitcher);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('crm-campagne-new', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Jeton CSRF invalide.');
            }

            $nom = trim((string) $request->request->get('nom', ''));
            $objet = trim((string) $request->request->get('objet', ''));
            $message = trim((string) $request->request->get('message', ''));

            if ($nom !== '' && $objet !== '' && $message !== '') {
                $campagne = (new CrmCampagne())
                    ->setNom($nom)
                    ->setType((string) $request->request->get('type', CrmCampagne::TYPE_ONBOARDING))
                    ->setObjet($objet)
                    ->setMessage($message)
                    ->setSegmentRegles([
                        'stages'   => (array) $request->request->all('stages'),
                        'couleurs' => (array) $request->request->all('couleurs'),
                    ]);
                $this->em->persist($campagne);
                $this->em->flush();
                $this->addFlash('success', 'Campagne créée. Vous pouvez maintenant l\'envoyer.');

                return $this->redirectToRoute('console.crm.campagne.index');
            }

            $this->addFlash('warning', 'Nom, objet et message sont requis.');
        }

        return $this->render('console/crm/campagne/new.html.twig', [
            'pageName' => 'Nouvelle campagne',
            'pageIcon' => 'action:premium',
            'types'    => CrmCampagne::TYPES,
            'stages'   => $this->pipeline->orderedStages(),
        ]);
    }

    #[Route('/{id}/envoyer', name: 'envoyer', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    public function envoyer(CrmCampagne $campagne, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('crm-campagne-envoyer-' . $campagne->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $envois = $this->campagneService->send($campagne);
        $this->addFlash('success', sprintf('Campagne envoyée à %d destinataire(s).', $envois));

        return $this->redirectToRoute('console.crm.campagne.index');
    }
}
