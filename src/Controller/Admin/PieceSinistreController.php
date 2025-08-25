<?php

namespace App\Controller\Admin;

use App\Entity\Invite;
use App\Entity\Entreprise;
use App\Constantes\Constante;
use App\Entity\PieceSinistre;
use App\Form\PieceSinistreType;
use App\Constantes\MenuActivator;
use App\Entity\NotificationSinistre;
use App\Repository\InviteRepository;
use App\Repository\EntrepriseRepository;
use Doctrine\ORM\EntityManagerInterface;
use App\Repository\PieceSinistreRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;


#[Route("/admin/piecesinistre", name: 'admin.piecesinistre.')]
#[IsGranted('ROLE_USER')]
class PieceSinistreController extends AbstractController
{
    public MenuActivator $activator;

    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private EntityManagerInterface $manager,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private PieceSinistreRepository $pieceSinistreRepository,
        private Constante $constante,
    ) {
        $this->activator = new MenuActivator(MenuActivator::GROUPE_CLAIMS);
    }


    #[Route('/index/{idEntreprise}', name: 'index', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function index($idEntreprise, Request $request)
    {
        $page = $request->query->getInt("page", 1);

        return $this->render('admin/modelepiecesinistre/index.html.twig', [
            'pageName' => $this->translator->trans("modelepiecesinistre_page_name_new"),
            'utilisateur' => $this->getUser(),
            'entreprise' => $this->entrepriseRepository->find($idEntreprise),
            'piecesinistres' => $this->pieceSinistreRepository->paginate($idEntreprise, $page),
            'page' => $page,
            'constante' => $this->constante,
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

    //     /** @var ModelePieceSinistre $modele */
    //     $modele = new ModelePieceSinistre();
    //     //Paramètres par défaut
    //     $modele->setEntreprise($entreprise);

    //     $form = $this->createForm(ModelePieceSinistreType::class, $modele);
    //     $form->handleRequest($request);

    //     if ($form->isSubmitted() && $form->isValid()) {
    //         $this->manager->persist($modele);
    //         $this->manager->flush();
    //         $this->addFlash("success", $this->translator->trans("modelepiecesinistre_creation_ok", [
    //             ":modelepiecesinistre" => $modele->getNom(),
    //         ]));
    //         return $this->redirectToRoute("admin.modelepiecesinistre.index", [
    //             'idEntreprise' => $idEntreprise,
    //         ]);
    //     }
    //     return $this->render('admin/modelepiecesinistre/create.html.twig', [
    //         'pageName' => $this->translator->trans("modelepiecesinistre_page_name_new"),
    //         'utilisateur' => $user,
    //         'entreprise' => $entreprise,
    //         'activator' => $this->activator,
    //         'form' => $form,
    //     ]);
    // }


    // #[Route('/edit/{idEntreprise}/{idPiecesinistre}', name: 'edit', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    // public function edit($idEntreprise, $idPiecesinistre, Request $request)
    // {
    //     /** @var Entreprise $entreprise */
    //     $entreprise = $this->entrepriseRepository->find($idEntreprise);

    //     /** @var Utilisateur $user */
    //     $user = $this->getUser();

    //     /** @var PieceSinistre $modele */
    //     $modele = $this->pieceSinistreRepository->find($idPiecesinistre);

    //     $form = $this->createForm(PieceSinistreType::class, $modele);
    //     $form->handleRequest($request);

    //     if ($form->isSubmitted() && $form->isValid()) {
    //         $this->manager->persist($modele); //On peut ignorer cette instruction car la fonction flush suffit.
    //         $this->manager->flush();
    //         return $this->redirectToRoute("admin.piecesinistre.index", [
    //             'idEntreprise' => $idEntreprise,
    //         ]);
    //     }
    //     return $this->render('admin/piecesinistre/edit.html.twig', [
    //         'utilisateur' => $user,
    //         'piecesinistre' => $modele,
    //         'entreprise' => $entreprise,
    //         'activator' => $this->activator,
    //         'form' => $form,
    //     ]);
    // }

    // #[Route('/remove/{idEntreprise}/{idPiecesinistre}', name: 'remove', requirements: ['idPiecesinistre' => Requirement::DIGITS, 'idEntreprise' => Requirement::DIGITS], methods: ['DELETE'])]
    // public function remove($idEntreprise, $idPiecesinistre, Request $request)
    // {
    //     /** @var ModelePieceSinistre $modele */
    //     $modele = $this->pieceSinistreRepository->find($modele);

    //     $message = $this->translator->trans("piecesinistre_deletion_ok", [
    //         ":piecesinistre" => $modele->getNom(),
    //     ]);;

    //     $this->manager->remove($modele);
    //     $this->manager->flush();

    //     $this->addFlash("success", $message);
    //     return $this->redirectToRoute("admin.piecesinistre.index", [
    //         'idEntreprise' => $idEntreprise,
    //     ]);
    // }




    
    /**
     * Fournit le formulaire HTML pour une pièce.
     */
    #[Route('/get-form/{id?}', name: 'api.get_form', methods: ['GET'])]
    public function getFormApi(?PieceSinistre $piece, Constante $constante): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        /** @var Invite $invite */
        $invite = $this->inviteRepository->findOneByEmail($user->getEmail());

        /** @var Entreprise $entreprise */
        $entreprise = $invite->getEntreprise();

        if (!$piece) {
            $piece = new PieceSinistre();
            $piece->setInvite($invite);
        }
        $form = $this->createForm(PieceSinistreType::class, $piece);

        return $this->render('components/_form_canvas.html.twig', [
            'form' => $form->createView(),
            'entityFormCanvas' => $constante->getEntityFormCanvas($piece, $entreprise->getId()) // ID entreprise à adapter
        ]);
    }

    /**
     * Traite la soumission du formulaire.
     */
    #[Route('/submit', name: 'api.submit', methods: ['POST'])]
    public function submitApi(Request $request, EntityManagerInterface $em): Response
    {
        $message = "";
        try {
            $data = json_decode($request->getContent(), true);
            $piece = isset($data['id']) ? $em->getRepository(PieceSinistre::class)->find($data['id']) : new PieceSinistre();

            if (isset($data['notificationSinistre'])) {
                $notification = $em->getReference(NotificationSinistre::class, $data['notificationSinistre']);
                if ($notification) $piece->setNotificationSinistre($notification);
            }

            $form = $this->createForm(PieceSinistreType::class, $piece);
            $form->submit($data, false);

            if ($form->isSubmitted() && $form->isValid()) {
                $em->persist($piece);
                $em->flush();
                return $this->json(['message' => 'Pièce enregistrée avec succès!']);
            }
        } catch (\Throwable $th) {
            //throw $th;
            $message = $th->getMessage();
        }

        return $this->json([
            'success' => false,
            'message' => 'Des erreurs de validation sont survenues. ' . $message,
            // 'errors' => $errors
        ], 422); // 422 = Unprocessable Entity (erreur de validation)
    }

    /**
     * Supprime une pièce.
     */
    #[Route('/delete/{id}', name: 'api.delete', methods: ['DELETE'])]
    public function deleteApi(PieceSinistre $piece, EntityManagerInterface $em): Response
    {
        try {
            $em->remove($piece);
            $em->flush();
            return $this->json(['message' => 'Pièce supprimée avec succès.']);
        } catch (\Exception $e) {
            return $this->json(['message' => 'Erreur lors de la suppression.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
