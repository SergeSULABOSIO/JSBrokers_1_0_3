<?php

namespace App\Controller\Admin;

use App\Entity\Entreprise;
use App\Constantes\Constante;
use App\Constantes\MenuActivator;
use App\Entity\Assureur;
use App\Entity\Client;
use App\Form\AssureurType;
use App\Form\ClientType;
use App\Repository\AssureurRepository;
use App\Repository\ClientRepository;
use App\Repository\InviteRepository;
use App\Repository\EntrepriseRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;


#[Route("/admin/client", name: 'admin.client.')]
#[IsGranted('ROLE_USER')]
class ClientController extends AbstractController
{
    public MenuActivator $activator;

    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private EntityManagerInterface $manager,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private ClientRepository $clientRepository,
        private Constante $constante,
    ) {
        $this->activator = new MenuActivator(MenuActivator::GROUPE_PRODUCTION);
    }


    #[Route('/index/{idEntreprise}', name: 'index', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function index($idEntreprise, Request $request)
    {
        $page = $request->query->getInt("page", 1);

        return $this->render('admin/client/index.html.twig', [
            'pageName' => $this->translator->trans("client_page_name_new"),
            'utilisateur' => $this->getUser(),
            'entreprise' => $this->entrepriseRepository->find($idEntreprise),
            'clients' => $this->clientRepository->paginate($idEntreprise, $page),
            'page' => $page,
            'constante' => $this->constante,
            'activator' => $this->activator,
        ]);
    }


    #[Route('/create/{idEntreprise}', name: 'create')]
    public function create($idEntreprise, Request $request)
    {
        /** @var Entreprise $entreprise */
        $entreprise = $this->entrepriseRepository->find($idEntreprise);

        /** @var Utilisateur $user */
        $user = $this->getUser();

        /** @var Client $client */
        $client = new Client();
        //Paramètres par défaut
        $client->setExonere(false);
        $client->setEntreprise($entreprise);

        $form = $this->createForm(ClientType::class, $client);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->manager->persist($client);
            $this->manager->flush();
            $this->addFlash("success", $this->translator->trans("client_creation_ok", [
                ":client" => $client->getNom(),
            ]));
            return $this->redirectToRoute("admin.client.index", [
                'idEntreprise' => $idEntreprise,
            ]);
        }
        return $this->render('admin/client/create.html.twig', [
            'pageName' => $this->translator->trans("client_page_name_new"),
            'utilisateur' => $user,
            'entreprise' => $entreprise,
            'activator' => $this->activator,
            'form' => $form,
        ]);
    }


    #[Route('/edit/{idEntreprise}/{idClient}', name: 'edit', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function edit($idEntreprise, $idClient, Request $request)
    {
        /** @var Entreprise $entreprise */
        $entreprise = $this->entrepriseRepository->find($idEntreprise);

        /** @var Utilisateur $user */
        $user = $this->getUser();

        /** @var Client $client */
        $client = $this->clientRepository->find($idClient);

        $form = $this->createForm(ClientType::class, $client);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->manager->persist($client); //On peut ignorer cette instruction car la fonction flush suffit.
            $this->manager->flush();
            $this->addFlash("success", $this->translator->trans("client_edition_ok", [
                ":client" => $client->getNom(),
            ]));
            return $this->redirectToRoute("admin.client.index", [
                'idEntreprise' => $idEntreprise,
            ]);
        }
        return $this->render('admin/client/edit.html.twig', [
            'pageName' => $this->translator->trans("client_page_name_update", [
                ":client" => $client->getNom(),
            ]),
            'utilisateur' => $user,
            'client' => $client,
            'entreprise' => $entreprise,
            'activator' => $this->activator,
            'form' => $form,
        ]);
    }

    #[Route('/remove/{idEntreprise}/{idClient}', name: 'remove', requirements: ['idClient' => Requirement::DIGITS, 'idEntreprise' => Requirement::DIGITS], methods: ['DELETE'])]
    public function remove($idEntreprise, $idClient, Request $request)
    {
        /** @var Client $client */
        $client = $this->clientRepository->find($idClient);

        $message = $this->translator->trans("client_deletion_ok", [
            ":client" => $client->getNom(),
        ]);;
        
        $this->manager->remove($client);
        $this->manager->flush();

        $this->addFlash("success", $message);
        return $this->redirectToRoute("admin.client.index", [
            'idEntreprise' => $idEntreprise,
        ]);
    }
}
