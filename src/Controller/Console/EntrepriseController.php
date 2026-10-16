<?php

namespace App\Controller\Console;

use App\Entity\Entreprise;
use App\Event\AgentNotificationEvent;
use App\Repository\EntrepriseRepository;
use App\Repository\TokenConsumptionRepository;
use App\Services\ServiceSuppressionEntreprise;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * Liste globale des entreprises de la plateforme + suppression (irréversible).
 */
#[Route('/console/entreprises', name: 'console.entreprise.')]
#[IsGranted('ROLE_ADMIN')]
class EntrepriseController extends AbstractConsoleController
{
    public function __construct(
        private EntrepriseRepository $entrepriseRepository,
        private ServiceSuppressionEntreprise $serviceSuppression,
        private EventDispatcherInterface $dispatcher,
        private TokenConsumptionRepository $consumptionRepository,
    ) {}

    #[Route('', name: 'index')]
    public function index(Request $request, LocaleSwitcher $localeSwitcher): Response
    {
        $this->applyLangPreference($request, $localeSwitcher);

        $entreprises = $this->entrepriseRepository->paginateAll($request->query->getInt('page', 1));

        // Consommation cumulée de tokens par entreprise (un seul agrégat pour
        // toute la page) ; le solde restant est lu sur le propriétaire (déjà
        // chargé via e.utilisateur dans le gabarit).
        $ids = array_map(static fn (Entreprise $e) => $e->getId(), $entreprises->getItems());

        return $this->render('console/entreprise/index.html.twig', [
            'pageName'      => 'Entreprises',
            'pageIcon'      => 'entreprise',
            'entreprises'   => $entreprises,
            'consommations' => $this->consumptionRepository->totauxParEntreprises($ids),
        ]);
    }

    /** L'archive comptable d'un cabinet, avant sa suppression par la console (règle OHADA). */
    #[Route('/{id}/archive-comptable', name: 'archive_comptable', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function archiveComptable(Entreprise $entreprise, Request $request, \App\Service\Note\ArchiveComptableDuCabinet $archive): Response
    {
        // POST ET JETON : télécharger l'archive ENREGISTRE sa remise, qui rend la suppression
        // possible. Un lien suivi depuis un autre site ne doit pas le faire à l'insu de qui l'a.
        if (!$this->isCsrfTokenValid('archive-entreprise-' . $entreprise->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }
        $remise = $archive->remettre($entreprise);

        return (new \Symfony\Component\HttpFoundation\BinaryFileResponse($remise['chemin']))
            ->setContentDisposition(\Symfony\Component\HttpFoundation\ResponseHeaderBag::DISPOSITION_ATTACHMENT, $remise['nom'])
            ->deleteFileAfterSend();
    }

    #[Route('/{id}', name: 'delete', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function delete(Entreprise $entreprise, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('delete-entreprise-' . $entreprise->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $nom = $entreprise->getNom();
        $proprietaire = $entreprise->getUtilisateur();
        // La remise de l'archive comptable, que la purge efface : la notification en garde la trace.
        $archiveRemise = $entreprise->getArchiveComptableRemiseLe()
            ? $entreprise->getArchiveComptableRemiseLe()->format('d/m/Y H:i') . ' — SHA-256 ' . $entreprise->getArchiveComptableEmpreinte()
            : '—';

        // Destruction totale et irréversible (données scopées + fichiers serveur) — sauf si
        // des pièces comptables restent à conserver sans archive remise (règle OHADA).
        try {
            $this->serviceSuppression->supprimer($entreprise);
        } catch (\DomainException $refus) {
            $this->addFlash('error', $refus->getMessage());

            return $this->redirectToRoute('console.entreprise.index');
        }

        $this->dispatcher->dispatch(new AgentNotificationEvent(
            AgentNotificationEvent::ACTION_DELETE,
            AgentNotificationEvent::TYPE_ENTREPRISE,
            $nom,
            ['Entreprise' => $nom, 'Propriétaire' => $proprietaire?->getNom() ?: '—', 'Archive comptable remise' => $archiveRemise],
        ));
        $this->addFlash('success', sprintf('Entreprise « %s » supprimée.', $nom));

        return $this->redirectToRoute('console.entreprise.index');
    }
}
