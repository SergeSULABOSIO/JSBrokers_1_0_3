<?php

namespace App\Controller\Admin;

use App\Entity\Tache;
use App\Entity\Invite;
use DateTimeImmutable;
use App\Form\TacheType;
use App\Entity\Entreprise;
use App\Constantes\Constante;
use App\Constantes\MenuActivator;
use App\Repository\TacheRepository;
use App\Entity\NotificationSinistre;
use App\Repository\InviteRepository;
use App\Repository\EntrepriseRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;


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


    // #[Route('/create/{idEntreprise}', name: 'create')]
    // public function create($idEntreprise, Request $request)
    // {
    //     /** @var Entreprise $entreprise */
    //     $entreprise = $this->entrepriseRepository->find($idEntreprise);

    //     /** @var Utilisateur $user */
    //     $user = $this->getUser();

    //     /** @var Tache */
    //     $tache = new Tache();
    //     //Paramètres par défaut
    //     $tache->setToBeEndedAt(new DateTimeImmutable("+7 days"));
    //     $tache->setClosed(false);
    //     // $tache->setInvite($invite);

    //     $form = $this->createForm(TacheType::class, $tache);
    //     $form->handleRequest($request);

    //     if ($form->isSubmitted() && $form->isValid()) {
    //         $this->manager->persist($tache);
    //         $this->manager->flush();
    //         return new Response("Ok");
    //     }
    //     return $this->render('admin/tache/create.html.twig', [
    //         'pageName' => $this->translator->trans("tache_page_name_new"),
    //         'utilisateur' => $user,
    //         'entreprise' => $entreprise,
    //         'activator' => $this->activator,
    //         'form' => $form,
    //     ]);
    // }


    // #[Route('/edit/{idEntreprise}/{idTache}', name: 'edit', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    // public function edit($idEntreprise, $idTache, Request $request)
    // {
    //     /** @var Entreprise $entreprise */
    //     $entreprise = $this->entrepriseRepository->find($idEntreprise);

    //     /** @var Utilisateur $user */
    //     $user = $this->getUser();

    //     /** @var Tache */
    //     $tache = $this->tacheRepository->find($idTache);

    //     $form = $this->createForm(TacheType::class, $tache);
    //     $form->handleRequest($request);

    //     if ($form->isSubmitted() && $form->isValid()) {
    //         $this->manager->persist($tache); //On peut ignorer cette instruction car la fonction flush suffit.
    //         $this->manager->flush();

    //         return new Response("Ok");
    //     }
    //     return $this->render('admin/tache/edit.html.twig', [
    //         'pageName' => $this->translator->trans("tache_page_name_update", [
    //             ":tache" => $tache->getDescription(),
    //         ]),
    //         'utilisateur' => $user,
    //         'tache' => $tache,
    //         'entreprise' => $entreprise,
    //         'activator' => $this->activator,
    //         'form' => $form,
    //     ]);
    // }

    // #[Route('/remove/{idEntreprise}/{idTache}', name: 'remove', requirements: ['idTache' => Requirement::DIGITS, 'idEntreprise' => Requirement::DIGITS], methods: ['DELETE'])]
    // public function remove($idEntreprise, $idTache, Request $request)
    // {
    //     /** @var Tache $tache */
    //     $tache = $this->tacheRepository->find($idTache);

    //     $message = $this->translator->trans("tache_deletion_ok", [
    //         ":tache" => $tache->getDescription(),
    //     ]);;

    //     $this->manager->remove($tache);
    //     $this->manager->flush();

    //     $this->addFlash("success", $message);
    //     return $this->redirectToRoute("admin.tache.index", [
    //         'idEntreprise' => $idEntreprise,
    //     ]);
    // }


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
            $piece = new Tache();
            $piece->setCreatedAt(new DateTimeImmutable("now"));
            $piece->setUpdatedAt(new DateTimeImmutable("now"));
            $piece->setToBeEndedAt(new DateTimeImmutable("now"));
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
    public function submitApi(Request $request, EntityManagerInterface $em): Response
    {
        $message = "";
        try {
            $data = json_decode($request->getContent(), true);
            $tache = isset($data['id']) ? $em->getRepository(Tache::class)->find($data['id']) : new Tache();

            if (isset($data['notificationSinistre'])) {
                $notification = $em->getReference(NotificationSinistre::class, $data['notificationSinistre']);
                if ($notification) $tache->setNotificationSinistre($notification);
            }

            $form = $this->createForm(TacheType::class, $tache);
            $form->submit($data, false);

            if ($form->isSubmitted() && $form->isValid()) {
                $em->persist($tache);
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
}
