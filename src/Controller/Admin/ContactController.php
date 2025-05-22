<?php

namespace App\Controller\Admin;

use App\Entity\Entreprise;
use App\Constantes\Constante;
use App\Constantes\MenuActivator;
use App\Entity\Assureur;
use App\Entity\Contact;
use App\Form\AssureurType;
use App\Form\ContactType;
use App\Repository\AssureurRepository;
use App\Repository\ContactRepository;
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
use Symfony\Component\HttpFoundation\Response;

#[Route("/admin/contact", name: 'admin.contact.')]
#[IsGranted('ROLE_USER')]
class ContactController extends AbstractController
{
    public MenuActivator $activator;

    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private EntityManagerInterface $manager,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private ContactRepository $contactRepository,
        private Constante $constante,
    ) {
        $this->activator = new MenuActivator(MenuActivator::GROUPE_PRODUCTION);
    }


    #[Route('/index/{idEntreprise}', name: 'index', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function index($idEntreprise, Request $request)
    {
        $page = $request->query->getInt("page", 1);

        return $this->render('admin/contact/index.html.twig', [
            'pageName' => $this->translator->trans("contact_page_name_new"),
            'utilisateur' => $this->getUser(),
            'entreprise' => $this->entrepriseRepository->find($idEntreprise),
            'contacts' => $this->contactRepository->paginateForEntreprise($idEntreprise, $page),
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

        /** @var Contact $contact */
        $contact = new Contact();
        //Paramètres par défaut
        // $contact->setEntreprise($entreprise);

        $form = $this->createForm(ContactType::class, $contact);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->manager->persist($contact);
            $this->manager->flush();
            
            return new Response("Ok");
        }
        return $this->render('admin/contact/create.html.twig', [
            'pageName' => $this->translator->trans("contact_page_name_new"),
            'utilisateur' => $user,
            'entreprise' => $entreprise,
            'contact' => $contact,
            'activator' => $this->activator,
            'form' => $form,
        ]);
    }


    #[Route('/edit/{idEntreprise}/{idContact}', name: 'edit', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function edit($idEntreprise, $idContact, Request $request)
    {
        /** @var Entreprise $entreprise */
        $entreprise = $this->entrepriseRepository->find($idEntreprise);

        /** @var Utilisateur $user */
        $user = $this->getUser();

        /** @var Contact $contact */
        $contact = $this->contactRepository->find($idContact);

        $form = $this->createForm(ContactType::class, $contact);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->manager->persist($contact); //On peut ignorer cette instruction car la fonction flush suffit.
            $this->manager->flush();
            
            return new Response("Ok");
        }
        return $this->render('admin/contact/edit.html.twig', [
            'pageName' => $this->translator->trans("contact_page_name_update", [
                ":contact" => $contact->getNom(),
            ]),
            'utilisateur' => $user,
            'contact' => $contact,
            'entreprise' => $entreprise,
            'activator' => $this->activator,
            'form' => $form,
        ]);
    }

    #[Route('/remove/{idEntreprise}/{idContact}', name: 'remove', requirements: ['idContact' => Requirement::DIGITS, 'idEntreprise' => Requirement::DIGITS], methods: ['DELETE'])]
    public function remove($idEntreprise, $idContact, Request $request)
    {
        /** @var Contact $contact */
        $contact = $this->contactRepository->find($idContact);

        $message = $this->translator->trans("contact_deletion_ok", [
            ":contact" => $contact->getNom(),
        ]);;
        
        $this->manager->remove($contact);
        $this->manager->flush();

        $this->addFlash("success", $message);
        return $this->redirectToRoute("admin.contact.index", [
            'idEntreprise' => $idEntreprise,
        ]);
    }
}
