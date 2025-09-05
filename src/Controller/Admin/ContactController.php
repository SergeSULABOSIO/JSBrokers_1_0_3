<?php

namespace App\Controller\Admin;

use App\Entity\Contact;
use App\Entity\Assureur;
use App\Form\ContactType;
use App\Entity\Entreprise;
use App\Constantes\Constante;
use App\Constantes\MenuActivator;
use App\Entity\NotificationSinistre;
use App\Entity\Traits\HandleChildAssociationTrait;
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
use Symfony\Component\Serializer\SerializerInterface;

#[Route("/admin/contact", name: 'admin.contact.')]
#[IsGranted('ROLE_USER')]
class ContactController extends AbstractController
{
    use HandleChildAssociationTrait;

    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private EntityManagerInterface $manager,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private ContactRepository $contactRepository,
        private Constante $constante,
    ) {
    }

    /**
     * On déclare ici tous les parents possibles pour un Contact.
     */
    protected function getParentAssociationMap(): array
    {
        return [
            // La clé 'notificationSinistre' correspond à celle envoyée par le JS
            'notificationSinistre' => NotificationSinistre::class,
            // Si un jour un contact peut être lié à un Client :
            // 'client' => \App\Entity\Client::class,
        ];
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
        ]);
    }


    /**
     * Fournit le formulaire HTML pour un contact (nouveau ou existant).
     */
    #[Route('/api/get-form/{id?}', name: 'api.get_form', methods: ['GET'])]
    public function getFormApi(?Contact $contact, Constante $constante): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        /** @var Invite $invite */
        $invite = $this->inviteRepository->findOneByEmail($user->getEmail());

        /** @var Entreprise $entreprise */
        $entreprise = $invite->getEntreprise();

        if (!$contact) {
            $contact = new Contact();
        }

        $form = $this->createForm(ContactType::class, $contact);

        if ($contact->getId()) {
            $entityCanvas = $constante->getEntityCanvas($contact);
            $constante->loadCalculatedValue($entityCanvas, [$contact]);
        }

        // On rend un template qui contient uniquement le formulaire
        return $this->render('components/_form_canvas.html.twig', [
            'form' => $form->createView(),
            'entityFormCanvas' => $constante->getEntityFormCanvas($contact, $entreprise->getId()),
            'entityCanvas' => $constante->getEntityCanvas($contact)
        ]);
    }

    /**
     * Traite la soumission du formulaire de contact (création ou modification).
     */
    #[Route('/api/submit', name: 'api.submit', methods: ['POST'])]
    public function submitApi(Request $request, EntityManagerInterface $em, SerializerInterface $serializer): Response
    {
        $data = $request->request->all();
        $files = $request->files->all();
        $submittedData = array_merge($data, $files);

        /** @var Contact $contact */
        $contact = isset($data['id']) && $data['id'] ? $em->getRepository(Contact::class)->find($data['id']) : new Contact();

        if (!$contact) {
            return $this->json(['message' => 'Contact non trouvé'], Response::HTTP_NOT_FOUND);
        }

        $form = $this->createForm(ContactType::class, $contact);
        $form->submit($submittedData, false); // Le 'false' permet de ne pas vider les champs non soumis

        if ($form->isSubmitted() && $form->isValid()) {
            // Notre Trait s'occupe de lier le contact à son parent
            $this->associateParent($contact, $data, $em);
            
            $em->persist($contact);
            $em->flush();

            // On sérialise l'entité complète (avec son nouvel ID) pour la renvoyer
            $jsonEntity = $serializer->serialize($contact, 'json', ['groups' => 'list:read']);
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
