<?php

namespace App\Controller\Admin;

use App\Entity\Invite;
use App\Entity\Entreprise;
use App\Entity\Utilisateur;
use App\Constantes\Constante;
use App\Constantes\MenuActivator;
use App\Services\ServiceMonnaies;
use App\Entity\NotificationSinistre;
use App\Repository\InviteRepository;
use App\Repository\EntrepriseRepository;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\OffreIndemnisationSinistre;
use App\Form\OffreIndemnisationSinistreType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;
use App\Repository\OffreIndemnisationSinistreRepository;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Serializer\SerializerInterface;

#[Route("/admin/offreindemnisationsinistre", name: 'admin.offreindemnisationsinistre.')]
#[IsGranted('ROLE_USER')]
class OffreIndemnisationSinistreController extends AbstractController
{
    public MenuActivator $activator;

    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private EntityManagerInterface $manager,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private OffreIndemnisationSinistreRepository $offreIndemnisationSinistreRepository,
        private Constante $constante,
        private ServiceMonnaies $serviceMonnaies,
    ) {
        $this->activator = new MenuActivator(MenuActivator::GROUPE_CLAIMS);
    }


    #[Route('/index/{idEntreprise}', name: 'index', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function index($idEntreprise, Request $request)
    {
        $page = $request->query->getInt("page", 1);

        /** @var Utilisateur $utilisateur */
        $utilisateur = $this->getUser();

        return $this->render('admin/offreindemnisation/index.html.twig', [
            'pageName' => $this->translator->trans("offreindemnisation_page_name_new"),
            'utilisateur' => $utilisateur,
            'entreprise' => $this->entrepriseRepository->find($idEntreprise),
            'offreindemnisations' => $this->offreIndemnisationSinistreRepository->paginateForEntreprise($idEntreprise, $page),
            'page' => $page,
            'constante' => $this->constante,
            'serviceMonnaie' => $this->serviceMonnaies,
            'activator' => $this->activator,
        ]);
    }


    /**
     * Fournit le formulaire HTML pour une pièce.
     */
    #[Route('/api/get-form/{id?}', name: 'api.get_form', methods: ['GET'])]
    public function getFormApi(?OffreIndemnisationSinistre $offre, Constante $constante): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        /** @var Invite $invite */
        $invite = $this->inviteRepository->findOneByEmail($user->getEmail());

        /** @var Entreprise $entreprise */
        $entreprise = $invite->getEntreprise();

        if (!$offre) {
            $offre = new OffreIndemnisationSinistre();
        }

        $form = $this->createForm(OffreIndemnisationSinistreType::class, $offre);

        return $this->render('components/_form_canvas.html.twig', [
            'form' => $form->createView(),
            'entityFormCanvas' => $constante->getEntityFormCanvas($offre, $entreprise->getId()) // ID entreprise à adapter
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

        $offre = isset($data['id']) ? $em->getRepository(OffreIndemnisationSinistre::class)->find($data['id']) : new OffreIndemnisationSinistre();

        if (isset($data['notificationSinistre'])) {
            $notification = $em->getReference(NotificationSinistre::class, $data['notificationSinistre']);
            if ($notification) $offre->setNotificationSinistre($notification);
        }

        $form = $this->createForm(OffreIndemnisationSinistreType::class, $offre);
        $form->submit($submittedData, false);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($offre);
            $em->flush();

            // On sérialise l'entité complète (avec son nouvel ID) pour la renvoyer
            $jsonEntity = $serializer->serialize($offre, 'json', ['groups' => 'list:read']);
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
    public function deleteApi(OffreIndemnisationSinistre $offreIndemnisationSinistre, EntityManagerInterface $em): Response
    {
        try {
            $em->remove($offreIndemnisationSinistre);
            $em->flush();
            return $this->json(['message' => 'Offre supprimée avec succès.']);
        } catch (\Exception $e) {
            return $this->json(['message' => 'Erreur lors de la suppression.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/api/{id}/paiements', name: 'api.get_paiements', methods: ['GET'])]
    public function getPaiementsListApi(int $id, OffreIndemnisationSinistreRepository $repository): Response
    {
        $offreIndemnisationSinistre = null;
        if ($id === 0) {
            $offreIndemnisationSinistre = new OffreIndemnisationSinistre();
        } else {
            $offreIndemnisationSinistre = $repository->find($id);
        }
        if (!$offreIndemnisationSinistre) {
            $offreIndemnisationSinistre = new OffreIndemnisationSinistre();
        }

        return $this->render('components/_collection_list.html.twig', [
            'items' => $offreIndemnisationSinistre->getPaiements(),
            'item_template' => 'components/collection_items/_paiement_item.html.twig'
        ]);
    }

    #[Route('/api/{id}/documents', name: 'api.get_documents', methods: ['GET'])]
    public function getDocumentsListApi(int $id, OffreIndemnisationSinistreRepository $repository): Response
    {
        $offreIndemnisationSinistre = null;
        if ($id === 0) {
            $offreIndemnisationSinistre = new OffreIndemnisationSinistre();
        } else {
            $offreIndemnisationSinistre = $repository->find($id);
        }
        if (!$offreIndemnisationSinistre) {
            $offreIndemnisationSinistre = new OffreIndemnisationSinistre();
        }
        return $this->render('components/_collection_list.html.twig', [
            'items' => $offreIndemnisationSinistre->getDocuments(),
            'item_template' => 'components/collection_items/_document_item.html.twig'
        ]);
    }

    #[Route('/api/{id}/taches', name: 'api.get_taches', methods: ['GET'])]
    public function getTachesListApi(int $id, OffreIndemnisationSinistreRepository $repository): Response
    {
        $offreIndemnisationSinistre = null;
        if ($id === 0) {
            $offreIndemnisationSinistre = new OffreIndemnisationSinistre();
        } else {
            $offreIndemnisationSinistre = $repository->find($id);
        }
        if (!$offreIndemnisationSinistre) {
            $offreIndemnisationSinistre = new OffreIndemnisationSinistre();
        }
        return $this->render('components/_collection_list.html.twig', [
            'items' => $offreIndemnisationSinistre->getTaches(),
            'item_template' => 'components/collection_items/_tache_item.html.twig'
        ]);
    }
}
