<?php

/**
 * @file Ce fichier contient le contrôleur PaiementController.
 * @description Ce contrôleur est un CRUD complet pour l'entité `Paiement`.
 * Il est responsable de :
 * 1. `index()`: Afficher la vue principale de la liste des paiements (page non-générique).
 * 2. Fournir des points de terminaison API pour :
 *    - `getFormApi()`: Obtenir le formulaire de création/édition.
 *    - `submitApi()`: Traiter la soumission du formulaire, en gérant l'association à des entités parentes (ex: OffreIndemnisationSinistre) grâce au `HandleChildAssociationTrait`.
 *    - `deleteApi()`: Supprimer un paiement.
 *    - `getPreuvesListApi()`: Charger la liste des documents (preuves) liés à un paiement.
 */

namespace App\Controller\Admin;

use App\Entity\Invite;
use DateTimeImmutable;
use App\Entity\Classeur;
use App\Entity\Document;
use App\Entity\Paiement;
use App\Entity\Entreprise;
use App\Form\PaiementType;
use App\Constantes\Constante;
use App\Constantes\MenuActivator;
use App\Services\ServiceMonnaies;
use App\Repository\NoteRepository;
use App\Repository\InviteRepository;
use App\Repository\ClasseurRepository;
use App\Repository\PaiementRepository;
use App\Repository\EntrepriseRepository;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\OffreIndemnisationSinistre;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use App\Entity\Traits\HandleChildAssociationTrait;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;


#[Route("/admin/paiement", name: 'admin.paiement.')]
#[IsGranted('ROLE_USER')]
class PaiementController extends AbstractController
{
    use HandleChildAssociationTrait;

    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private EntityManagerInterface $manager,
        private EntrepriseRepository $entrepriseRepository,
        private NoteRepository $noteRepository,
        private InviteRepository $inviteRepository,
        private PaiementRepository $paiementRepository,
        private ClasseurRepository $classeurRepository,
        private ServiceMonnaies $serviceMonnaies,
        private Constante $constante,
    ) {}


    #[Route('/index/{idEntreprise}', name: 'index', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function index($idEntreprise, Request $request)
    {
        $page = $request->query->getInt("page", 1);

        return $this->render('admin/paiement/index.html.twig', [
            'pageName' => $this->translator->trans("paiement_page_name_new"),
            'utilisateur' => $this->getUser(),
            'entreprise' => $this->entrepriseRepository->find($idEntreprise),
            'paiements' => $this->paiementRepository->paginateForEntreprise($idEntreprise, $page),
            'page' => $page,
            'serviceMonnaie' => $this->serviceMonnaies,
            'constante' => $this->constante,
        ]);
    }

    private function loadClasseurPreuvesDesPaiements(Entreprise $entreprise): Classeur
    {
        /** @var Classeur $classeurPOP */
        $classeurPOP = $this->classeurRepository->findOneByNom(Classeur::NOM_CLASSEUR_POP, $entreprise->getId());
        // $classeurPOP = $this->loadClasseurPreuvesDesPaiements($idEntreprise);
        if ($classeurPOP == null) {
            $classeurPOP = (new Classeur())
                ->setNom(Classeur::NOM_CLASSEUR_POP)
                ->setDescription("Classeur des preuves des paiements")
                ->setEntreprise($entreprise);
            $this->manager->persist($classeurPOP);
            $this->manager->flush();
            // dd("Classeur POP", $classeurPOP, "Crée.");
        }
        return $classeurPOP;
    }


    private function loadDataOnNote(Paiement $paiement): string
    {
        $refNote = "";
        $montPayable = 0;
        $montPaye = 0;
        $montSolde = 0;
        if ($paiement->getNote() != null) {
            $note = $paiement->getNote();
            $refNote = $note->getReference();
            $montPayable = number_format($this->constante->Note_getMontant_payable($note), 2, ",", " ");
            $montPaye = number_format($this->constante->Note_getMontant_paye($note), 2, ",", " ");
            $montSolde = number_format($this->constante->Note_getMontant_solde($note), 2, ",", " ");
        }
        return $refNote . "__1986__" . $montPayable . "__1986__" . $montPaye . "__1986__" . $montSolde;
    }

    /**
     * Fournit le formulaire HTML pour une pièce.
     */
    #[Route('/api/get-form/{id?}', name: 'api.get_form', methods: ['GET'])]
    public function getFormApi(?Paiement $paiement, Constante $constante, Request $request): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        /** @var Invite $invite */
        $invite = $this->inviteRepository->findOneByEmail($user->getEmail());

        /** @var Entreprise $entreprise */
        $entreprise = $invite->getEntreprise();

        if (!$paiement) {
            $paiement = new Paiement();
            $defaultMontant = $request->query->get('default_montant');
            if ($defaultMontant !== null && $defaultMontant !== '') {
                $paiement->setMontant((float)$defaultMontant);
            }
            $paiement->setPaidAt(new DateTimeImmutable("now"));
            $paiement->setDescription("Descript. à générer automatiquement ici.");
        }

        $form = $this->createForm(PaiementType::class, $paiement);

        $entityCanvas = $constante->getEntityCanvas(Paiement::class);
        $constante->loadCalculatedValue($entityCanvas, [$paiement]);

        return $this->render('components/_form_canvas.html.twig', [
            'form' => $form->createView(),
            'entityFormCanvas' => $constante->getEntityFormCanvas($paiement, $entreprise->getId()), // ID entreprise à adapter
            'entityCanvas' => $constante->getEntityCanvas(Paiement::class)
        ]);
    }

    protected function getParentAssociationMap(): array
    {
        return [
            // Un paiement peut être lié à une OffreIndemnisationSinistre
            'offreIndemnisationSinistre' => OffreIndemnisationSinistre::class,
            // Ajoutez d'autres parents ici si nécessaire
        ];
    }

    /**
     * Traite la soumission du formulaire.
     */
    #[Route('/api/submit', name: 'api.submit', methods: ['POST'])]
    public function submitApi(Request $request, EntityManagerInterface $em, SerializerInterface $serializer): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        /** @var Invite $invite */
        $invite = $this->inviteRepository->findOneByEmail($user->getEmail());

        /** @var Entreprise $entreprise */
        $entreprise = $invite->getEntreprise();

        $data = $request->request->all();
        $files = $request->files->all();
        $submittedData = array_merge($data, $files);

        /** @var Paiement $paiement */
        $paiement = isset($data['id']) ? $em->getRepository(Paiement::class)->find($data['id']) : new Paiement();

        $form = $this->createForm(PaiementType::class, $paiement);
        $form->submit($submittedData, false);

        if ($form->isSubmitted() && $form->isValid()) {
            // Notre "boîte à outils" s'occupe de lier le paiement à son parent
            $this->associateParent($paiement, $data, $em);

            $em->persist($paiement);
            $em->flush();
            
            $jsonEntity = $serializer->serialize($paiement, 'json', ['groups' => 'list:read']);
            return $this->json([
                'message' => 'Enregistrée avec succès!',
                'entity' => json_decode($jsonEntity) // On renvoie l'objet JSON
            ]);
        }

        // Gestion des erreurs de validation...
        $errors = [];
        foreach ($form->getErrors(true) as $error) {
            $errors[$error->getOrigin()->getName()][] = $error->getMessage();
        }
        return $this->json(['message' => 'Veuillez corriger les erreurs.', 'errors' => $errors], 422);
    }

    /**
     * Supprime une pièce.
     */
    #[Route('/api/delete/{id}', name: 'api.delete', methods: ['DELETE'])]
    public function deleteApi(Paiement $paiement, EntityManagerInterface $em): Response
    {
        try {
            $em->remove($paiement);
            $em->flush();
            return $this->json(['message' => 'Paiement supprimée avec succès.']);
        } catch (\Exception $e) {
            return $this->json(['message' => 'Erreur lors de la suppression.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/api/{id}/preuves', name: 'api.get_preuves', methods: ['GET'])]
    public function getPreuvesListApi(int $id, PaiementRepository $repository): Response
    {
        $paiement = ($id === 0) ? new Paiement() : $repository->find($id);
        if (!$paiement) {
            $paiement = new Paiement();
        }

        return $this->render('components/_collection_list.html.twig', [
            'items' => $paiement->getPreuves(),
            'item_template' => 'components/collection_items/_document_item.html.twig'
        ]);
    }

    private function getEntreprise(): Entreprise
    {
        /** @var Invite $invite */
        $invite = $this->getInvite();
        return $invite->getEntreprise();
    }

    private function getInvite(): Invite
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();
        /** @var Invite $invite */
        $invite = $this->inviteRepository->findOneByEmail($user->getEmail());
        return $invite;
    }

    /**
     * Déduit le nom de l'entité à partir du nom du contrôleur.
     * Exemple: PieceSinistreController -> PieceSinistre
     * @return string
     */
    private function getEntityName($objectOrClass): string
    {
        $shortClassName = (new \ReflectionClass($objectOrClass))->getShortName();
        return str_replace('Controller', '', $shortClassName);
    }

    /**
     * Déduit le nom racine du serveur à partir du nom du contrôleur.
     * Exemple: PieceSinistreController -> piecesinistre
     * @return string
     */
    private function getServerRootName($className): string
    {
        return strtolower($this->getEntityName($className));
    }
}
