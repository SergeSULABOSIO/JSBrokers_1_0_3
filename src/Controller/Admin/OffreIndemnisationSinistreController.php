<?php

/**
 * @file Ce fichier contient le contrôleur OffreIndemnisationSinistreController.
 * @description Ce contrôleur est un CRUD complet pour l'entité `OffreIndemnisationSinistre`.
 * Il est responsable de :
 * 1. `index()`: Afficher la vue principale de la liste des offres d'indemnisation.
 * 2. Fournir des points de terminaison API pour :
 *    - `getFormApi()`: Obtenir le formulaire de création/édition.
 *    - `submitApi()`: Traiter la soumission du formulaire.
 *    - `deleteApi()`: Supprimer une offre.
 *    - `getPaiementsListApi()`, `getDocumentsListApi()`, etc. : Charger les listes des collections liées à une offre.
 */

namespace App\Controller\Admin;

use App\Entity\Invite;
use App\Entity\Entreprise;
use App\Entity\Traits\HandleChildAssociationTrait;
use App\Entity\Utilisateur;
use App\Constantes\Constante;
use App\Constantes\MenuActivator;
use App\Services\ServiceMonnaies;
use App\Entity\NotificationSinistre;
use App\Repository\InviteRepository;
use App\Repository\EntrepriseRepository;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\OffreIndemnisationSinistre;
use App\Entity\Paiement;
use App\Entity\Tache;
use App\Form\OffreIndemnisationSinistreType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;
use App\Repository\OffreIndemnisationSinistreRepository;
use Dom\Document;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Serializer\SerializerInterface;

#[Route("/admin/offreindemnisationsinistre", name: 'admin.offreindemnisationsinistre.')]
#[IsGranted('ROLE_USER')]
class OffreIndemnisationSinistreController extends AbstractController
{
    use HandleChildAssociationTrait;

    public MenuActivator $activator;

    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private EntityManagerInterface $manager,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private OffreIndemnisationSinistreRepository $repository,
        private Constante $constante,
        private ServiceMonnaies $serviceMonnaies,
    ) {
        $this->activator = new MenuActivator(MenuActivator::GROUPE_CLAIMS);
    }

    protected function getParentAssociationMap(): array
    {
        return [
            'notificationSinistre' => NotificationSinistre::class,
        ];
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
            'offreindemnisations' => $this->repository->paginateForEntreprise($idEntreprise, $page),
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

        $entityCanvas = $constante->getEntityCanvas(OffreIndemnisationSinistre::class);
        $constante->loadCalculatedValue($entityCanvas, [$offre]);

        return $this->render('components/_form_canvas.html.twig', [
            'form' => $form->createView(),
            'entityFormCanvas' => $constante->getEntityFormCanvas($offre, $entreprise->getId()), // ID entreprise à adapter
            'entityCanvas' => $constante->getEntityCanvas(OffreIndemnisationSinistre::class)
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

        $offre = isset($data['id']) && $data['id'] ? $em->getRepository(OffreIndemnisationSinistre::class)->find($data['id']) : new OffreIndemnisationSinistre();

        $form = $this->createForm(OffreIndemnisationSinistreType::class, $offre);
        $form->submit($submittedData, false);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->associateParent($offre, $data, $em);
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

    private function getEntreprise(): Entreprise
    {
        /** @var Invite $invite */
        $invite = $this->getInvite();
        return $invite->getEntreprise();
    }

    private function getInvite(): Invite
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();
        /** @var Invite $invite */
        $invite = $this->inviteRepository->findOneByEmail($user->getEmail());
        return $invite;
    }

    #[Route('/api/{id}/paiements', name: 'api.get_paiements', methods: ['GET'])]
    public function getPaiementsListApi(int $id): Response
    {
        $data = [];
        if ($id !== 0) {
            /** @var OffreIndemnisationSinistre $offreIndemnisationSinistre */
            $offreIndemnisationSinistre = $this->repository->find($id);
            $data = $offreIndemnisationSinistre->getPaiements();
        }
        $paiementCanvas = $this->constante->getEntityCanvas(Paiement::class);
        $this->constante->loadCalculatedValue($paiementCanvas, $data);

        return $this->render('components/_generic_list_component.html.twig', [
            'data' => $data,
            'entite_nom' => 'Paiements',
            'entityCanvas' => $paiementCanvas,
            'listeCanvas' => $this->constante->getListeCanvas(Paiement::class),
            'entityFormCanvas' => $this->constante->getEntityFormCanvas(new Paiement(), $this->getEntreprise()->getId()),
            'constante' => $this->constante,
            'numericAttributes' => $this->constante->getNumericAttributesAndValuesForTotalsBar($data),
        ]);
    }

    #[Route('/api/{id}/documents', name: 'api.get_documents', methods: ['GET'])]
    public function getDocumentsListApi(int $id, OffreIndemnisationSinistreRepository $repository): Response
    {
        $data = [];
        if ($id !== 0) {
            /** @var OffreIndemnisationSinistre $offreIndemnisationSinistre */
            $offreIndemnisationSinistre = $this->repository->find($id);
            $data = $offreIndemnisationSinistre->getDocuments();
        }
        $documentCanvas = $this->constante->getEntityCanvas(Paiement::class);
        $this->constante->loadCalculatedValue($documentCanvas, $data);

        return $this->render('components/_generic_list_component.html.twig', [
            'data' => $data,
            'entite_nom' => 'Documents',
            'entityCanvas' => $documentCanvas,
            'listeCanvas' => $this->constante->getListeCanvas(Document::class),
            'entityFormCanvas' => $this->constante->getEntityFormCanvas(new Document(), $this->getEntreprise()->getId()),
            'constante' => $this->constante,
            'numericAttributes' => $this->constante->getNumericAttributesAndValuesForTotalsBar($data),
        ]);
    }

    #[Route('/api/{id}/taches', name: 'api.get_taches', methods: ['GET'])]
    public function getTachesListApi(int $id): Response
    {
        $data = [];
        if ($id !== 0) {
            /** @var OffreIndemnisationSinistre $offreIndemnisationSinistre */
            $offreIndemnisationSinistre = $this->repository->find($id);
            $data = $offreIndemnisationSinistre->getTaches();
        }
        $tacheCanvas = $this->constante->getEntityCanvas(Paiement::class);
        $this->constante->loadCalculatedValue($tacheCanvas, $data);

        return $this->render('components/_generic_list_component.html.twig', [
            'data' => $data,
            'entite_nom' => 'Tâches',
            'entityCanvas' => $tacheCanvas,
            'listeCanvas' => $this->constante->getListeCanvas(Tache::class),
            'entityFormCanvas' => $this->constante->getEntityFormCanvas(new Tache(), $this->getEntreprise()->getId()),
            'constante' => $this->constante,
            'numericAttributes' => $this->constante->getNumericAttributesAndValuesForTotalsBar($data),
        ]);
    }
}
