<?php

namespace App\Controller\Console\Crm;

use App\Controller\Console\AbstractConsoleController;
use App\Crm\CrmCampagneService;
use App\Entity\Crm\CrmCampagne;
use App\Form\CrmCampagneType;
use App\Repository\Crm\CrmCampagneRepository;
use App\Repository\Crm\CrmProfilRepository;
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
        private CrmProfilRepository $profilRepository,
    ) {
    }

    /** Tailles de chaque segment (badges du formulaire), partagées new/edit. */
    private function segmentCounts(): array
    {
        return [
            'stageCounts' => $this->profilRepository->countByStage(),
            'colorCounts' => $this->profilRepository->countByHealthColor(),
        ];
    }

    #[Route('', name: 'index')]
    public function index(Request $request, LocaleSwitcher $localeSwitcher): Response
    {
        $this->applyLangPreference($request, $localeSwitcher);

        return $this->render('console/crm/campagne/index.html.twig', [
            'pageName'  => 'CRM — Campagnes',
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
            'backLabel'   => 'Campagnes',
            'submitLabel' => 'Créer la campagne',
            'description' => 'Définissez le message et le segment ciblé (étapes de pipeline / santé). Vide = tous les clients.',
        ] + $this->segmentCounts());
    }

    /** Édition d'une campagne (message + segment). */
    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function edit(CrmCampagne $campagne, Request $request, LocaleSwitcher $localeSwitcher): Response
    {
        $this->applyLangPreference($request, $localeSwitcher);

        $form = $this->createForm(CrmCampagneType::class, $campagne);
        // Pré-remplit les cases (champs non mappés) depuis les règles enregistrées.
        $segment = $campagne->getSegmentRegles();
        $form->get('stages')->setData($segment['stages'] ?? []);
        $form->get('couleurs')->setData($segment['couleurs'] ?? []);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $campagne->setSegmentRegles([
                'stages'   => $form->get('stages')->getData() ?: [],
                'couleurs' => $form->get('couleurs')->getData() ?: [],
            ]);
            $this->em->flush();
            $this->addFlash('success', 'Campagne mise à jour.');

            return $this->redirectToRoute('console.crm.campagne.index');
        }

        return $this->render('console/crm/campagne/form.html.twig', [
            'pageName'    => 'Modifier la campagne',
            'formIcon'    => 'action:premium',
            'form'        => $form,
            'backUrl'     => $this->generateUrl('console.crm.campagne.index'),
            'backLabel'   => 'Campagnes',
            'submitLabel' => 'Enregistrer les modifications',
            'description' => 'Modifiez le message et le segment ciblé. Une campagne déjà envoyée peut être relancée depuis la liste.',
        ] + $this->segmentCounts());
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

    /** Relance (renvoi) d'une campagne déjà envoyée, vers son segment courant. */
    #[Route('/{id}/relancer', name: 'relancer', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    public function relancer(CrmCampagne $campagne, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('crm-campagne-relancer-' . $campagne->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $envois = $this->campagneService->send($campagne, true);
        $this->addFlash('success', sprintf('Campagne relancée : %d e-mail(s) envoyé(s).', $envois));

        return $this->redirectToRoute('console.crm.campagne.index');
    }
}
