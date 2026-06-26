<?php

namespace App\Controller\Console\Crm;

use App\Controller\Console\AbstractConsoleController;
use App\Entity\Utilisateur;
use App\Repository\Crm\CrmNotificationRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * Notifications internes de l'équipe (alertes d'automatisation, jalons clients).
 */
#[Route('/console/crm/notifications', name: 'console.crm.notification.')]
#[IsGranted('ROLE_ADMIN')]
class CrmNotificationController extends AbstractConsoleController
{
    public function __construct(private CrmNotificationRepository $notificationRepository)
    {
    }

    #[Route('', name: 'index')]
    public function index(Request $request, LocaleSwitcher $localeSwitcher): Response
    {
        $this->applyLangPreference($request, $localeSwitcher);

        /** @var Utilisateur $agent */
        $agent = $this->getUser();

        return $this->render('console/crm/notification/index.html.twig', [
            'pageName'      => 'CRM — Notifications',
            'pageIcon'      => 'action:alert',
            'notifications' => $this->notificationRepository->forAgent($agent, 100),
        ]);
    }

    #[Route('/lues', name: 'read', methods: ['POST'])]
    public function read(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('crm-notif-read', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        /** @var Utilisateur $agent */
        $agent = $this->getUser();
        $this->notificationRepository->markAllReadForAgent($agent);
        $this->addFlash('success', 'Notifications marquées comme lues.');

        return $this->redirectToRoute('console.crm.notification.index');
    }
}
