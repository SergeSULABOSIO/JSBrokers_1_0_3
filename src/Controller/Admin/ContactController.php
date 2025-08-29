<?php

namespace App\Controller\Admin;

use App\Entity\Contact;
use App\Entity\Assureur;
use App\Form\ContactType;
use App\Entity\Entreprise;
use App\Constantes\Constante;
use App\Constantes\MenuActivator;
use App\Entity\NotificationSinistre;
use App\Repository\InviteRepository;
use App\Repository\ContactRepository;
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
        // $data = json_decode($request->getContent(), true);
        $data = $request->request->all();
        // Les fichiers uploadés sont dans $request->files, le composant Form de Symfony les trouvera tout seul.
        
        /** @var Contact $contact */
        $contact = isset($data['id']) && $data['id'] ? $em->getRepository(Contact::class)->find($data['id']) : new Contact();

        if (!$contact) {
            return $this->json(['message' => 'Contact non trouvé'], Response::HTTP_NOT_FOUND);
        }

        $form = $this->createForm(ContactType::class, $contact);
        $form->submit($data, false); // Le 'false' permet de ne pas vider les champs non soumis

        if ($form->isSubmitted() && $form->isValid()) {
            // --- LOGIQUE DYNAMIQUE D'ASSOCIATION PARENT ---
            // Un contact peut être lié à une NotificationSinistre.
            if (isset($data['notificationSinistre'])) {
                $parent = $em->getReference(NotificationSinistre::class, $data['notificationSinistre']);
                if ($parent) $contact->setNotificationSinistre($parent);
            }
            // Demain, si un contact peut aussi être lié à un Client :
            // elseif (isset($data['client'])) {
            //     $parent = $em->getReference(Client::class, $data['client']);
            //     if ($parent) $contact->setClient($parent);
            // }
            // --- FIN DE LA LOGIQUE DYNAMIQUE ---

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
