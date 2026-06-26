<?php

namespace App\Controller\Console\Crm;

use App\Controller\Console\AbstractConsoleController;
use App\Crm\CrmCampagneService;
use App\Entity\Crm\CrmCampagne;
use App\Form\CrmCampagneType;
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

        $campagne = new CrmCampagne();
        $form = $this->createForm(CrmCampagneType::class, $campagne);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $campagne->setSegmentRegles([
                'stages'   => $form->get('stages')->getData() ?: [],
                'couleurs' => $form->get('couleurs')->getData() ?: [],
            ]);
            $this->em->persist($campagne);
            $this->em->flush();
            $this->addFlash('success', 'Campagne créée. Vous pouvez maintenant l\'envoyer depuis la liste.');

            return $this->redirectToRoute('console.crm.campagne.index');
        }

        return $this->render('console/crm/campagne/form.html.twig', [
            'pageName'    => 'Nouvelle campagne',
            'formIcon'    => 'action:premium',
            'form'        => $form,
            'backUrl'     => $this->generateUrl('console.crm.campagne.index'),
            'backLabel'   => 'Marketing',
            'submitLabel' => 'Créer la campagne',
            'description' => 'Définissez le message et le segment ciblé (étapes de pipeline / santé). Vide = tous les clients.',
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
