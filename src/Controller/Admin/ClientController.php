<?php

namespace App\Controller\Admin;

use App\Entity\Client;
use App\Form\ClientType;
use App\Entity\Entreprise;
use App\Constantes\Constante;
use App\Services\ServiceTaxes;
use App\Services\ServiceMonnaies;
use App\Repository\ClientRepository;
use App\Repository\InviteRepository;
use App\Repository\EntrepriseRepository;
use Doctrine\ORM\EntityManagerInterface;
use App\Services\JSBDynamicSearchService;
use App\Services\CanvasBuilder;
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

#[Route("/admin/client", name: 'admin.client.')]
#[IsGranted('ROLE_USER')]
class ClientController extends AbstractController
{
    use HandleChildAssociationTrait;
    use ControllerUtilsTrait;

    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private EntityManagerInterface $em,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private ClientRepository $clientRepository,
        private Constante $constante,
        private ServiceMonnaies $serviceMonnaies,
        private ServiceTaxes $serviceTaxes,
        private JSBDynamicSearchService $searchService,
        private SerializerInterface $serializer,
        CanvasBuilder $canvasBuilder // Inject CanvasBuilder without property promotion
    ) {
        // Assign the injected CanvasBuilder to the property declared in the trait
        $this->canvasBuilder = $canvasBuilder;
    }

    protected function getCollectionMap(): array
    {
        return $this->buildCollectionMapFromEntity(Client::class);
    }

    protected function getParentAssociationMap(): array
    {
        return $this->buildParentAssociationMapFromEntity(Client::class);
    }

    #[Route('/index/{idInvite}/{idEntreprise}', name: 'index', requirements: ['idEntreprise' => Requirement::DIGITS, 'idInvite' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function index(Request $request)
    {
        return $this->renderViewOrListComponent(Client::class, $request);
    }


    #[Route('/api/get-form/{id?}', name: 'api.get_form', methods: ['GET'])]
    public function getFormApi(?Client $client, Request $request): Response
    {
        return $this->renderFormCanvas(
            $request,
            Client::class,
            ClientType::class,
            $client,
            function (Client $client, \App\Entity\Invite $invite) {
                $client->setExonere(false);
                $client->setEntreprise($invite->getEntreprise());
            }
        );
    }


    #[Route('/api/submit', name: 'api.submit', methods: ['POST'])]
    public function submitApi(Request $request): Response
    {
        return $this->handleFormSubmission(
            $request,
            Client::class,
            ClientType::class
        );
    }

    #[Route('/api/delete/{id}', name: 'api.delete', methods: ['DELETE'])]
    public function deleteApi(Client $client): Response
    {
        return $this->handleDeleteApi($client);
    }

    #[Route('/api/dynamic-query/{idInvite}/{idEntreprise}', name: 'app_dynamic_query', requirements: ['idEntreprise' => Requirement::DIGITS, 'idInvite' => Requirement::DIGITS], methods: ['POST'])]
    public function query(Request $request)
    {
        return $this->renderViewOrListComponent(Client::class, $request, true);
    }

    /**
     * Boîte de SÉLECTION d'un portefeuille cible pour un client (actions « Affecter à un
     * portefeuille » / « Transférer vers un autre portefeuille » de la rubrique Clients).
     * Miroir du picker de clients de la fiche Portefeuille : liste les portefeuilles de
     * l'espace ; en mode transfert, le portefeuille actuel est marqué « Actuel » sans action.
     * Déclarée AVANT la route fourre-tout api/{id}/{collectionName}/{usage}.
     */
    #[Route('/api/{id}/portefeuille-picker', name: 'api.portefeuille_picker', requirements: ['id' => Requirement::DIGITS], methods: ['GET'], priority: 1)]
    public function portefeuillePicker(Client $client): Response
    {
        // Mutation d'un client à venir → exige le droit de Modification (fail-closed).
        if (!$this->mayAccessEntity(Client::class, \App\Entity\Invite::ACCESS_MODIFICATION)) {
            throw $this->createAccessDeniedException("Affectation de portefeuille hors de votre périmètre d'accès.");
        }
        // Scoping : le client doit appartenir à l'espace de travail courant.
        $entreprise = $this->getEntreprise();
        if ($entreprise === null || $client->getEntreprise()?->getId() !== $entreprise->getId()) {
            throw $this->createNotFoundException("Client introuvable dans cet espace de travail.");
        }

        $portefeuilles = $this->em->getRepository(\App\Entity\Portefeuille::class)->findBy(
            ['entreprise' => $entreprise],
            ['nom' => 'ASC']
        );

        return $this->render('components/client/_portefeuille_picker.html.twig', [
            'client'        => $client,
            'portefeuilles' => $portefeuilles,
            'isTransfert'   => $client->getPortefeuille() !== null,
        ]);
    }

    /**
     * Affecte un client à un portefeuille cible — couvre l'AFFECTATION (client libre)
     * et le TRANSFERT (client déjà rattaché ailleurs). Refuse le portefeuille actuel (409).
     */
    #[Route('/api/{id}/affecter-portefeuille/{portefeuilleId}', name: 'api.affecter_portefeuille', requirements: ['id' => Requirement::DIGITS, 'portefeuilleId' => Requirement::DIGITS], methods: ['PUT'])]
    public function affecterPortefeuille(Client $client, int $portefeuilleId): Response
    {
        if (!$this->mayAccessEntity(Client::class, \App\Entity\Invite::ACCESS_MODIFICATION)) {
            return $this->accessDeniedJson();
        }
        $entreprise = $this->getEntreprise();
        if ($entreprise === null || $client->getEntreprise()?->getId() !== $entreprise->getId()) {
            return $this->json(['message' => "Client introuvable dans cet espace de travail."], Response::HTTP_NOT_FOUND);
        }

        $portefeuille = $this->em->getRepository(\App\Entity\Portefeuille::class)->find($portefeuilleId);
        if ($portefeuille === null || $portefeuille->getEntreprise()?->getId() !== $entreprise->getId()) {
            return $this->json(['message' => "Portefeuille introuvable dans cet espace de travail."], Response::HTTP_NOT_FOUND);
        }

        $ancien = $client->getPortefeuille();
        if ($ancien !== null && $ancien->getId() === $portefeuille->getId()) {
            return $this->json(['message' => "Ce client appartient déjà à ce portefeuille."], Response::HTTP_CONFLICT);
        }

        $client->setPortefeuille($portefeuille);
        $this->em->flush();

        $message = $ancien !== null
            ? sprintf('« %s » transféré de « %s » vers « %s ».', $client->getNom(), $ancien->getNom(), $portefeuille->getNom())
            : sprintf('« %s » affecté au portefeuille « %s ».', $client->getNom(), $portefeuille->getNom());

        return $this->json(['message' => $message]);
    }

    /**
     * Retire le client de son portefeuille (client.portefeuille = null) SANS le supprimer :
     * détachement non destructif, le client reste réaffectable à tout moment.
     */
    #[Route('/api/retirer-portefeuille/{id}', name: 'api.retirer_portefeuille', requirements: ['id' => Requirement::DIGITS], methods: ['DELETE'])]
    public function retirerPortefeuille(Client $client): Response
    {
        if (!$this->mayAccessEntity(Client::class, \App\Entity\Invite::ACCESS_MODIFICATION)) {
            return $this->accessDeniedJson();
        }
        $entreprise = $this->getEntreprise();
        if ($entreprise === null || $client->getEntreprise()?->getId() !== $entreprise->getId()) {
            return $this->json(['message' => "Client introuvable dans cet espace de travail."], Response::HTTP_NOT_FOUND);
        }

        $portefeuille = $client->getPortefeuille();
        if ($portefeuille === null) {
            return $this->json(['message' => "Ce client n'appartient à aucun portefeuille."], Response::HTTP_NOT_FOUND);
        }

        $client->setPortefeuille(null); // détache, ne supprime pas
        $this->em->flush();

        return $this->json([
            'message' => sprintf('« %s » retiré du portefeuille « %s ». Le client n\'est pas supprimé.', $client->getNom(), $portefeuille->getNom()),
        ]);
    }

    #[Route('/api/{id}/{collectionName}/{usage}', name: 'api.get_collection', methods: ['GET'])]
    public function getCollectionListApi(int $id, string $collectionName, ?string $usage = "generic"): Response
    {
        return $this->handleCollectionApiRequest($id, $collectionName, Client::class, $usage);
    }
}
