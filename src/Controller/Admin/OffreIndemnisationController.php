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


#[Route("/admin/offreindemnisation", name: 'admin.offreindemnisation.')]
#[IsGranted('ROLE_USER')]
class OffreIndemnisationController extends AbstractController
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


    // #[Route('/create/{idEntreprise}', name: 'create')]
    // public function create($idEntreprise, Request $request)
    // {
    //     /** @var Entreprise $entreprise */
    //     $entreprise = $this->entrepriseRepository->find($idEntreprise);

    //     /** @var Utilisateur $user */
    //     $user = $this->getUser();

    //     /** @var Invite $invite */
    //     $invite = $this->inviteRepository->findOneByEmail($user->getEmail());

    //     /** @var OffreIndemnisationSinistre $offre */
    //     $offre = new OffreIndemnisationSinistre();

    //     $form = $this->createForm(OffreIndemnisationSinistre::class, $offre);
    //     $form->handleRequest($request);

    //     if ($form->isSubmitted() && $form->isValid()) {
    //         $this->manager->persist($offre);
    //         $this->manager->flush();
    //         $this->addFlash("success", $this->translator->trans("offreindemnisation_creation_ok", [
    //             ":offreindemnisation" => $offre->getNom(),
    //         ]));
    //         return $this->redirectToRoute("admin.offreindemnisation.index", [
    //             'idEntreprise' => $idEntreprise,
    //         ]);
    //     }
    //     return $this->render('admin/offreindemnisation/create.html.twig', [
    //         'pageName' => $this->translator->trans("offreindemnisation_page_name_new"),
    //         'utilisateur' => $user,
    //         'entreprise' => $entreprise,
    //         'activator' => $this->activator,
    //         'form' => $form,
    //     ]);
    // }


    // #[Route('/edit/{idEntreprise}/{idOffreindemnisation}', name: 'edit', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    // public function edit($idEntreprise, $idOffreindemnisation, Request $request)
    // {
    //     /** @var Entreprise $entreprise */
    //     $entreprise = $this->entrepriseRepository->find($idEntreprise);

    //     /** @var Utilisateur $user */
    //     $user = $this->getUser();

    //     /** @var OffreIndemnisationSinistre $offre */
    //     $offre = $this->offreIndemnisationSinistreRepository->find($idOffreindemnisation);

    //     $form = $this->createForm(OffreIndemnisationSinistreType::class, $offre);
    //     $form->handleRequest($request);

    //     if ($form->isSubmitted() && $form->isValid()) {
    //         $this->manager->persist($offre); //On peut ignorer cette instruction car la fonction flush suffit.
    //         $this->manager->flush();
    //         $this->addFlash("success", $this->translator->trans("offreindemnisation_edition_ok", [
    //             ":offreindemnisation" => $offre->getNom(),
    //         ]));
    //         return $this->redirectToRoute("admin.offreindemnisation.index", [
    //             'idEntreprise' => $idEntreprise,
    //         ]);
    //     }
    //     return $this->render('admin/offreindemnisation/edit.html.twig', [
    //         'pageName' => $this->translator->trans("offreindemnisation_page_name_update", [
    //             ":offreindemnisation" => $offre->getNom(),
    //         ]),
    //         'utilisateur' => $user,
    //         'offreindemnisation' => $offre,
    //         'entreprise' => $entreprise,
    //         'activator' => $this->activator,
    //         'form' => $form,
    //     ]);
    // }

    // #[Route('/remove/{idEntreprise}/{idOffreindemnisation}', name: 'remove', requirements: ['idOffreindemnisation' => Requirement::DIGITS, 'idEntreprise' => Requirement::DIGITS], methods: ['DELETE'])]
    // public function remove($idEntreprise, $idOffreindemnisation, Request $request)
    // {
    //     /** @var OffreIndemnisationSinistre $offre */
    //     $offre = $this->offreIndemnisationSinistreRepository->find($idOffreindemnisation);

    //     $message = $this->translator->trans("offreindemnisation_deletion_ok", [
    //         ":offreindemnisation" => $offre->getNom(),
    //     ]);

    //     $this->manager->remove($offre);
    //     $this->manager->flush();

    //     $this->addFlash("success", $message);
    //     return $this->redirectToRoute("admin.offreindemnisation.index", [
    //         'idEntreprise' => $idEntreprise,
    //     ]);
    // }




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
    public function submitApi(Request $request, EntityManagerInterface $em): Response
    {

        $data = json_decode($request->getContent(), true);
        $offre = isset($data['id']) ? $em->getRepository(OffreIndemnisationSinistre::class)->find($data['id']) : new OffreIndemnisationSinistre();

        if (isset($data['notificationSinistre'])) {
            $notification = $em->getReference(NotificationSinistre::class, $data['notificationSinistre']);
            if ($notification) $offre->setNotificationSinistre($notification);
        }

        $form = $this->createForm(OffreIndemnisationSinistreType::class, $offre);
        $form->submit($data, false);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($offre);
            $em->flush();
            return $this->json(['message' => 'Offre enregistrée avec succès!']);
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
}
