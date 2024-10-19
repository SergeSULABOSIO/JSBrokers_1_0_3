<?php

namespace App\Controller\Admin;

use App\Entity\Entreprise;
use App\Entity\Utilisateur;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

#[Route("/admin/entreprise_dashbord", name: 'admin.entreprise.')]
#[IsGranted('ROLE_USER')]
class EntrepriseDashbordController extends AbstractController
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator
    ) {}


    #[Route('/{id}', name: 'dashbord', requirements: ['id' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function dashbord(Entreprise $entreprise, Request $request)
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        // dd($entreprise, $user);

        if ($user->isVerified()) {
            return $this->render('admin/dashbord/index.html.twig', [
                'pageName' => "Tableau de bord",
                'utilisateur' => $user,
                'entreprise' => $entreprise,
                'page' => $request->query->getInt("page", 1),
            ]);
        } else {
            $this->addFlash("warning", "" . $user->getNom() . ", votre adresse mail n'est pas encore vérifiée. Veuillez cliquer sur le lien de vérification qui vous a été envoyé par JS Brokers à votre adresse " . $user->getEmail() . ".");
            return new RedirectResponse($this->urlGenerator->generate("app_login"));
        }
    }
}
