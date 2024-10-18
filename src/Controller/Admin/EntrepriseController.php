<?php

namespace App\Controller\Admin;

use App\Entity\Entreprise;
use App\Entity\Utilisateur;
use App\Form\EntrepriseType;
use App\Repository\InviteRepository;
use App\Repository\EntrepriseRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

#[Route("/admin/entreprise", name: 'admin.entreprise.')]
#[IsGranted('ROLE_USER')]
class EntrepriseController extends AbstractController
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private MailerInterface $mailer,
        private EntityManagerInterface $manager,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
    ) {}

    #[Route(name: 'index')]
    public function index(Request $request)
    {
        $page = $request->query->getInt("page", 1);

        /** @var Utilisateur $user */
        $user = $this->getUser();
        
        if ($user->isVerified()) {
            return $this->render('admin/entreprise/index.html.twig', [
                'pageName' => "Entreprises",
                'utilisateur' => $user,
                'entreprises' => $this->entrepriseRepository->paginateEntreprises($page),
                'page' => $request->query->getInt("page", 1),
                'nbEntreprises' => $this->entrepriseRepository->getNBEntreprises(),
                'nbInvites' => $this->inviteRepository->getNBInvites(),
            ]);
        } else {
            $this->addFlash("warning", "" . $user->getNom() . ", votre adresse mail n'est pas encore vérifiée. Veuillez cliquer sur le lien de vérification qui vous a été envoyé par JS Brokers à votre adresse " . $user->getEmail() . ".");
            return new RedirectResponse($this->urlGenerator->generate("app_login"));
        }
    }


    #[Route('/create', name: 'create')]
    public function create(Request $request)
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        /** @var Entreprise $entreprise */
        $entreprise = new Entreprise();
        $form = $this->createForm(EntrepriseType::class, $entreprise);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->manager->persist($entreprise);
            $this->manager->flush();
            $this->addFlash("success", "" . $entreprise->getNom() . " est ajoutée avec succès.");
            return $this->redirectToRoute("admin.entreprise.index");
        }
        return $this->render('admin/entreprise/create.html.twig', [
            'pageName' => 'Nouveau',
            'utilisateur' => $user,
            'nbEntreprises' => $this->entrepriseRepository->getNBEntreprises(),
            'nbInvites' => $this->inviteRepository->getNBInvites(),
            'form' => $form,
        ]);
    }


    #[Route('/{id}', name: 'edit', requirements: ['id' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function edit(Entreprise $entreprise, Request $request)
    {
        // dd($invite);
        /** @var Utilisateur $user */
        $user = $this->getUser();

        $form = $this->createForm(EntrepriseType::class, $entreprise);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->manager->persist($entreprise); //On peut ignorer cette instruction car la fonction flush suffit.
            $this->manager->flush();
            $this->addFlash("success", $entreprise->getNom() . " a été modifiée avec succès.");
            return $this->redirectToRoute("admin.entreprise.index");
        }
        return $this->render('admin/entreprise/edit.html.twig', [
            'pageName' => "Edition",
            'utilisateur' => $user,
            'entreprise' => $entreprise,
            'nbEntreprises' => $this->entrepriseRepository->getNBEntreprises(),
            'nbInvites' => $this->inviteRepository->getNBInvites(),
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'remove', requirements: ['id' => Requirement::DIGITS], methods: ['DELETE'])]
    public function remove(Entreprise $entreprise)
    {
        $this->manager->remove($entreprise);
        $this->manager->flush();
        $this->addFlash("success", $entreprise->getNom() . " a été supprimée avec succès.");
        return $this->redirectToRoute("admin.entreprise.index");
    }
}
