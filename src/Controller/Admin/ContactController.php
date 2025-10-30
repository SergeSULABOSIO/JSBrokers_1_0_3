<?php

/**
 * @file Ce fichier contient le contrôleur ContactController.
 * @description Ce contrôleur est un CRUD complet pour l'entité `Contact`.
 * Il est responsable de :
 * 1. `index()`: Afficher la vue principale de la liste des contacts (page non-générique).
 * 2. Fournir des points de terminaison API pour :
 *    - `getFormApi()`: Obtenir le formulaire de création/édition.
 *    - `submitApi()`: Traiter la soumission du formulaire, en gérant l'association à une entité parente (ex: NotificationSinistre) grâce au `HandleChildAssociationTrait`.
 *    - `deleteApi()`: Supprimer un contact.
 */

namespace App\Controller\Admin;

use App\Entity\Client;
use App\Entity\Contact;
use App\Form\ContactType;
use App\Constantes\Constante;
use App\Entity\NotificationSinistre;
use App\Repository\InviteRepository;
use App\Repository\ContactRepository;
use App\Repository\EntrepriseRepository;
use Doctrine\ORM\EntityManagerInterface;
use App\Services\JSBDynamicSearchService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use App\Controller\Admin\ControllerUtilsTrait;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use App\Entity\Traits\HandleChildAssociationTrait;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

#[Route("/admin/contact", name: 'admin.contact.')]
#[IsGranted('ROLE_USER')]
class ContactController extends AbstractController
{
    use HandleChildAssociationTrait;
    use ControllerUtilsTrait;

    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private EntityManagerInterface $em,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private ContactRepository $contactRepository,
        private Constante $constante,
        private JSBDynamicSearchService $searchService, // Ajoutez cette ligne
    ) {}

    protected function getParentAssociationMap(): array
    {
        return $this->buildParentAssociationMapFromEntity(Contact::class);
    }

    protected function getCollectionMap(): array
    {
        // Ce contrôleur n'expose pas de collections via l'API générique.
        return [];
    }

    #[Route(
        '/index/{idInvite}/{idEntreprise}',
        name: 'index',
        requirements: [
            'idEntreprise' => Requirement::DIGITS,
            'idInvite' => Requirement::DIGITS
        ],
        methods: ['GET', 'POST']
    )]
    public function index(Request $request)
    {
        return $this->renderViewOrListComponent(Contact::class, $request);
    }


    /**
     * Fournit le formulaire HTML pour un contact (nouveau ou existant).
     */
    #[Route('/api/get-form/{id?}', name: 'api.get_form', methods: ['GET'])]
    public function getFormApi(?Contact $contact , Request $request): Response
    {
        return $this->renderFormCanvas(
            $request,
            Contact::class,
            ContactType::class,
            $contact
            // No specific initializer needed for a new Contact
        );
    }

    /**
     * Traite la soumission du formulaire de contact (création ou modification).
     */
    #[Route('/api/submit', name: 'api.submit', methods: ['POST'])]
    public function submitApi(Request $request, EntityManagerInterface $em, SerializerInterface $serializer): Response
    {
        return $this->handleFormSubmission(
            $request,
            Contact::class,
            ContactType::class,
            $em,
            $serializer
        );
    }

    /**
     * Supprime un contact.
     */
    #[Route('/api/delete/{id}', name: 'api.delete', methods: ['DELETE'])]
    public function deleteApi(Contact $contact, EntityManagerInterface $em): Response
    {
        return $this->handleDeleteApi($contact, $em);
    }


    #[Route(
        '/api/dynamic-query/{idInvite}/{idEntreprise}',
        name: 'app_dynamic_query',
        requirements: [
            'idEntreprise' => Requirement::DIGITS,
            'idInvite' => Requirement::DIGITS
        ],
        methods: ['POST']
    )]
    public function query(Request $request)
    {
        return $this->renderViewOrListComponent(Contact::class, $request, true);
    }
}
