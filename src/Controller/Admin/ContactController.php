<?php

namespace App\Controller\Admin;

use App\Entity\Contact;
use App\Entity\Assureur;
use App\Form\ContactType;
use App\Entity\Entreprise;
use App\Form\AssureurType;
use App\Constantes\Constante;
use App\Constantes\MenuActivator;
use App\Entity\NotificationSinistre;
use App\Repository\InviteRepository;
use App\Repository\ContactRepository;
use App\Repository\AssureurRepository;
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

    /**
     * Fournit le formulaire HTML pour un contact (nouveau ou existant).
     */
    #[Route('/api/get-form/{id?}', name: 'api.get_form', methods: ['GET'])]
    public function getFormApi(?Contact $contact, Constante $constante): Response
    {
        if (!$contact) {
            $contact = new Contact();
        }

        /** @var Utilisateur $user */
        $user = $this->getUser();

        /** @var Invite $invite */
        $invite = $this->inviteRepository->findOneByEmail($user->getEmail());

        /** @var Entreprise $entreprise */
        $entreprise = $invite->getEntreprise();

        $form = $this->createForm(ContactType::class, $contact);


        // On rend un template qui contient uniquement le formulaire
        return $this->render('components/_form_canvas.html.twig', [
            'form' => $form->createView(),
            'entityFormCanvas' => $constante->getEntityFormCanvas($contact, $entreprise->getId())
        ]);
    }

    /**
     * Traite la soumission du formulaire de contact (création ou modification).
     */
    #[Route('/api/submit', name: 'api.submit', methods: ['POST'])]
    public function submitApi(Request $request, EntityManagerInterface $em): Response
    {
        $data = json_decode($request->getContent(), true);
        /** @var Contact $contact */
        $contact = isset($data['id']) && $data['id'] ? $em->getRepository(Contact::class)->find($data['id']) : new Contact();

        if (!$contact) {
            return $this->json(['message' => 'Contact non trouvé'], Response::HTTP_NOT_FOUND);
        }

        $form = $this->createForm(ContactType::class, $contact);
        $form->submit($data, false); // Le 'false' permet de ne pas vider les champs non soumis

        if ($form->isSubmitted() && $form->isValid()) {
            // --- AJOUT : ASSOCIER LA NOTIFICATION PARENTE ---
            if (isset($data['notificationSinistre'])) {
                $notification = $em->getRepository(NotificationSinistre::class)->find($data['notificationSinistre']);
                if ($notification) {
                    $contact->setNotificationSinistre($notification);
                }
            }
            // --- FIN DE L'AJOUT ---

            $em->persist($contact);
            $em->flush();
            return $this->json([
                'message' => 'Contact enregistré avec succès!',
                'contact' => ['id' => $contact->getId()] // Retourne l'ID pour référence
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
     * Supprime un contact.
     */
    #[Route('/api/delete/{id}', name: 'api.delete', methods: ['DELETE'])]
    public function deleteApi(Contact $contact, EntityManagerInterface $em): Response
    {
        try {
            $em->remove($contact);
            $em->flush();
            return $this->json(['message' => 'Contact supprimé avec succès.']);
        } catch (\Exception $e) {
            return $this->json(['message' => 'Erreur lors de la suppression.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
