<?php

namespace App\Controller\Admin;

use App\Entity\Tache;
use App\Entity\Invite;
use App\Form\TacheType;
use App\Entity\Entreprise;
use App\Constantes\Constante;
use App\Constantes\MenuActivator;
use App\Repository\TacheRepository;
use App\Entity\NotificationSinistre;
use App\Entity\OffreIndemnisationSinistre;
use App\Repository\InviteRepository;
use App\Repository\EntrepriseRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Serializer\SerializerInterface;


#[Route("/admin/tache", name: 'admin.tache.')]
#[IsGranted('ROLE_USER')]
class TacheController extends AbstractController
{
    public MenuActivator $activator;

    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private EntityManagerInterface $manager,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private TacheRepository $tacheRepository,
        private Constante $constante,
    ) {
        $this->activator = new MenuActivator(MenuActivator::GROUPE_MARKETING);
    }


    #[Route('/index/{idEntreprise}', name: 'index', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function index($idEntreprise, Request $request)
    {
        $page = $request->query->getInt("page", 1);

        return $this->render('admin/tache/index.html.twig', [
            'pageName' => $this->translator->trans("tache_page_name_new"),
            'utilisateur' => $this->getUser(),
            'entreprise' => $this->entrepriseRepository->find($idEntreprise),
            'taches' => $this->tacheRepository->paginateForEntreprise($idEntreprise, $page),
            'page' => $page,
            'constante' => $this->constante,
            'activator' => $this->activator,
        ]);
    }


    /**
     * Fournit le formulaire HTML pour une pièce.
     */
    #[Route('/api/get-form/{id?}', name: 'api.get_form', methods: ['GET'])]
    public function getFormApi(?Tache $tache, Constante $constante): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        /** @var Invite $invite */
        $invite = $this->inviteRepository->findOneByEmail($user->getEmail());

        /** @var Entreprise $entreprise */
        $entreprise = $invite->getEntreprise();

        if (!$tache) {
            $tache = new Tache();
            $tache->setClosed(false);
            $tache->setToBeEndedAt(new DateTimeImmutable("+1 days"));
            $tache->setExecutor($invite);
        }

        $form = $this->createForm(TacheType::class, $tache);

        return $this->render('components/_form_canvas.html.twig', [
            'form' => $form->createView(),
            'entityFormCanvas' => $constante->getEntityFormCanvas($tache, $entreprise->getId()) // ID entreprise à adapter
        ]);
    }

    /**
     * Traite la soumission du formulaire.
     */
    #[Route('/api/submit', name: 'api.submit', methods: ['POST'])]
    public function submitApi(Request $request, EntityManagerInterface $em, SerializerInterface $serializer): Response
    {
        $data = $request->request->all();
        $files = $request->files->all();
        $submittedData = array_merge($data, $files);

        /** @var Tache $tache */
        $tache = isset($data['id']) ? $em->getRepository(Tache::class)->find($data['id']) : new Tache();

        if (isset($data['notificationSinistre'])) {
            /** @var NotificationSinistre $notification */
            $notification = $em->getReference(NotificationSinistre::class, $data['notificationSinistre']);
            if ($notification) $tache->setNotificationSinistre($notification);
        }
        if (isset($data['offreIndemnisation'])) {
            /** @var OffreIndemnisationSinistre $offreIndemnisation */
            $offreIndemnisation = $em->getReference(OffreIndemnisationSinistre::class, $data['offreIndemnisation']);
            if ($offreIndemnisation) $tache->setOffreIndemnisationSinistre($offreIndemnisation);
        }

        $form = $this->createForm(TacheType::class, $tache);
        $form->submit($submittedData, false);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($tache);
            $em->flush();

            // --- MODIFICATION ---
            // On sérialise l'entité complète (avec son nouvel ID) pour la renvoyer
            $jsonEntity = $serializer->serialize($tache, 'json', ['groups' => 'list:read']);
            return $this->json([
                'message' => 'Enregistrée avec succès!',
                'entity' => json_decode($jsonEntity) // On renvoie l'objet JSON
            ]);
        }


        $errors = [];
        // On parcourt toutes les erreurs du formulaire (y compris celles des champs enfants)
        foreach ($form->getErrors(true) as $error) {
            $errors[$error->getOrigin()->getName()][] = $error->getMessage();
        }

        return $this->json([
            'success' => false,
            'message' => 'Veuillez corriger les erreurs ci-dessous.',
            'errors'  => $errors // On envoie le tableau détaillé des erreurs au client
        ], 422); // 422 = Unprocessable Entity
    }

    /**
     * Supprime une pièce.
     */
    #[Route('/api/delete/{id}', name: 'api.delete', methods: ['DELETE'])]
    public function deleteApi(Tache $tache, EntityManagerInterface $em): Response
    {
        try {
            $em->remove($tache);
            $em->flush();
            return $this->json(['message' => 'Pièce supprimée avec succès.']);
        } catch (\Exception $e) {
            return $this->json(['message' => 'Erreur lors de la suppression.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    // AJOUTEZ CETTE NOUVELLE ACTION
    #[Route('/api/{id}/feedbacks', name: 'api.get_feedbacks', methods: ['GET'])]
    public function getFeedbacksListApi(int $id, TacheRepository $repository): Response
    {
        $tache = null;
        if ($id === 0) {
            $tache = new Tache();
        } else {
            $tache = $repository->find($id);
        }
        if (!$tache) {
            $tache = new Tache();
        }

        return $this->render('components/_collection_list.html.twig', [
            'items' => $tache->getFeedbacks(),
            'item_template' => 'components/collection_items/_feedback_item.html.twig'
        ]);
    }

    // AJOUTEZ CETTE NOUVELLE ACTION
    #[Route('/api/{id}/documents', name: 'api.get_documents', methods: ['GET'])]
    public function getDocumentsListApi(int $id, TacheRepository $repository): Response
    {
        $tache = null;
        if ($id === 0) {
            $tache = new Tache();
        } else {
            $tache = $repository->find($id);
        }
        if (!$tache) {
            $tache = new Tache();
        }

        return $this->render('components/_collection_list.html.twig', [
            'items' => $tache->getDocuments(),
            'item_template' => 'components/collection_items/_document_item.html.twig'
        ]);
    }
}
