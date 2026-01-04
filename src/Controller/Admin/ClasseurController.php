<?php

namespace App\Controller\Admin;

use App\Entity\Invite;
use App\Entity\Entreprise;
use App\Constantes\Constante;
use App\Entity\Classeur;
use App\Form\ClasseurType;
use App\Repository\ClasseurRepository;
use App\Repository\InviteRepository;
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

#[Route("/admin/classeur", name: 'admin.classeur.')]
#[IsGranted('ROLE_USER')]
class ClasseurController extends AbstractController
{
    use HandleChildAssociationTrait;
    use ControllerUtilsTrait;

    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private EntityManagerInterface $em,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private ClasseurRepository $classeurRepository,
        private Constante $constante,
        private JSBDynamicSearchService $searchService,
        private SerializerInterface $serializer
    ) {
    }

    protected function getCollectionMap(): array
    {
        return $this->buildCollectionMapFromEntity(Classeur::class);
    }

    protected function getParentAssociationMap(): array
    {
        return $this->buildParentAssociationMapFromEntity(Classeur::class);
    }

    #[Route('/index/{idEntreprise}', name: 'index', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function index($idEntreprise, Request $request)
    {
        return $this->renderViewOrListComponent(Classeur::class, $request);
    }


    #[Route('/api/get-form/{id?}', name: 'api.get_form', methods: ['GET'])]
    public function getFormApi(?Classeur $classeur, Request $request): Response
    {
        return $this->renderFormCanvas(
            $request,
            Classeur::class,
            ClasseurType::class,
            $classeur,
            function (Classeur $classeur, Invite $invite) {
                $classeur->setEntreprise($invite->getEntreprise());
            }
        );
    }


    #[Route('/api/submit', name: 'api.submit', methods: ['POST'])]
    public function submitApi(Request $request): Response
    {
        return $this->handleFormSubmission(
            $request,
            Classeur::class,
            ClasseurType::class
        );
    }

    #[Route('/api/delete/{id}', name: 'api.delete', methods: ['DELETE'])]
    public function deleteApi(Classeur $classeur): Response
    {
        return $this->handleDeleteApi($classeur);
    }

    #[Route('/api/dynamic-query/{idInvite}/{idEntreprise}', name: 'app_dynamic_query', requirements: ['idEntreprise' => Requirement::DIGITS, 'idInvite' => Requirement::DIGITS], methods: ['POST'])]
    public function query(Request $request)
    {
        return $this->renderViewOrListComponent(Classeur::class, $request, true);
    }

    #[Route('/api/{id}/{collectionName}/{usage}', name: 'api.get_collection', methods: ['GET'])]
    public function getCollectionListApi(int $id, string $collectionName, ?string $usage = "generic"): Response
    {
        return $this->handleCollectionApiRequest($id, $collectionName, Classeur::class, $usage);
    }
}
