<?php

/**
 * @file Contrôleur CRUD de l'entité `ReversementRetroAgent` — la part d'une commission
 * encaissée, reversée à l'agent interne qui a apporté l'affaire.
 *
 * ── UNE RUBRIQUE DE CONSULTATION ────────────────────────────────────────────────────
 * La CRÉATION n'a pas sa place ici, et le canevas la refuse (`creation_interdite`) : un
 * reversement ne s'enregistre pas sans justificatif, or les pièces d'une fiche s'attachent
 * APRÈS sa création. Ses deux portes restent le picker du rapport de production et
 * l'assistant, tous deux transactionnels — la pièce y part du même geste que le montant.
 *
 * Cette rubrique sert à RELIRE, à corriger une référence, et surtout à joindre un
 * justificatif oublié : c'est elle qui fait apparaître les actions « Attacher des pièces »
 * et « Voir les documents », injectées par FormCanvasProvider sur toute entité portant une
 * collection de documents. Sans écran, elles n'avaient nulle part où s'afficher.
 */

namespace App\Controller\Admin;

use App\Entity\ReversementRetroAgent;
use App\Entity\Traits\HandleChildAssociationTrait;
use App\Form\ReversementRetroAgentType;
use App\Repository\EntrepriseRepository;
use App\Repository\InviteRepository;
use App\Service\Retro\LotDeVersement;
use App\Services\Canvas\CalculationProvider;
use App\Services\CanvasBuilder;
use App\Services\JSBDynamicSearchService;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\SerializerInterface;

#[Route("/admin/reversementretroagent", name: 'admin.reversementretroagent.')]
#[IsGranted('ROLE_USER')]
class ReversementRetroAgentController extends AbstractController
{
    use HandleChildAssociationTrait;
    use ControllerUtilsTrait;

    public function __construct(
        private EntityManagerInterface $em,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private JSBDynamicSearchService $searchService,
        private SerializerInterface $serializer,
        private CalculationProvider $calculationProvider,
        private LotDeVersement $lots,
        CanvasBuilder $canvasBuilder
    ) {
        $this->canvasBuilder = $canvasBuilder;
    }

    protected function getCollectionMap(): array
    {
        return $this->buildCollectionMapFromEntity(ReversementRetroAgent::class);
    }

    /**
     * L'ONGLET « JUSTIFICATIFS » MONTRE LES PIÈCES DU VIREMENT, pas de la seule ligne.
     *
     * Un virement groupé solde N affaires avec UN bordereau, attaché au porteur du lot
     * (le membre de plus petit id) : c'est la consigne du non-stockage redondant. La
     * colonne de la liste annonce donc « 1 pièce » sur CHAQUE ligne du virement — et sans
     * cette surcharge, l'onglet contextuel d'un membre NON porteur en affichait zéro.
     * Deux surfaces disaient deux choses de la même pièce, et celle qui disait « aucune »
     * laissait croire à un décaissement sans preuve.
     *
     * On réutilise `LotDeVersement::documentsDuLot()`, déjà employé par la colonne : une
     * seule requête pour tout le lot, et une seule règle pour les deux surfaces.
     */
    protected function ajusterCollectionContextuelle(
        string $collectionName,
        object $parentEntity,
        Collection $data,
    ): Collection {
        if ($collectionName !== 'documents' || !$parentEntity instanceof ReversementRetroAgent) {
            return $data;
        }

        $agent = $parentEntity->getAgent();
        $entreprise = $parentEntity->getEntreprise();
        if ($agent === null || $entreprise === null) {
            return $data;
        }

        $membres = $this->lots->membres($agent, $entreprise, $this->lots->cle($parentEntity));

        // Lot inconnu (versement isolé, ou données en cours d'écriture) : la relation dit
        // déjà la vérité, on n'y touche pas.
        return $membres === []
            ? $data
            : new ArrayCollection($this->lots->documentsDuLot($membres));
    }

    protected function getParentAssociationMap(): array
    {
        return $this->buildParentAssociationMapFromEntity(ReversementRetroAgent::class);
    }

    /**
     * La LISTE de la rubrique. Sans cette action, la carte des composants n'a rien à
     * appeler et l'onglet répond 404 — ce que le chargeur traduit par un panneau vide.
     */
    #[Route('/index/{idInvite}/{idEntreprise}', name: 'index', requirements: ['idEntreprise' => Requirement::DIGITS, 'idInvite' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function index(Request $request)
    {
        return $this->renderViewOrListComponent(ReversementRetroAgent::class, $request);
    }

    #[Route('/api/get-form/{id?}', name: 'api.get_form', methods: ['GET'])]
    public function getFormApi(?ReversementRetroAgent $reversement, Request $request): Response
    {
        return $this->renderFormCanvas(
            $request,
            ReversementRetroAgent::class,
            ReversementRetroAgentType::class,
            $reversement,
        );
    }

    #[Route('/api/submit', name: 'api.submit', methods: ['POST'])]
    public function submitApi(Request $request): Response
    {
        return $this->handleFormSubmission(
            $request,
            ReversementRetroAgent::class,
            ReversementRetroAgentType::class
        );
    }

    /**
     * SUPPRIMER UN REVERSEMENT DÉFAIT TOUT SON VIREMENT.
     *
     * Depuis que la rubrique replie chaque lot sur son porteur, la ligne sélectionnée
     * REPRÉSENTE un virement entier : n'en supprimer qu'une échéance aurait fait maigrir le
     * décaissement d'un montant que l'écran ne montrait même pas, et laissé une écriture
     * comptable partielle. La règle du lot est celle de `LotDeVersement` — un seul endroit,
     * partagé avec l'édition et la relecture des pièces.
     */
    #[Route('/api/delete/{id}', name: 'api.delete', methods: ['DELETE'])]
    public function deleteApi(ReversementRetroAgent $reversement, LotDeVersement $lots): Response
    {
        // LE PORTEUR EN DERNIER : ses frères sont supprimés d'abord, et la réponse — comme
        // les gardes du socle — porte sur la ligne que l'utilisateur a désignée.
        foreach ($lots->membresDuLot($reversement) as $membre) {
            if ($membre === $reversement) {
                continue;
            }
            $this->em->remove($membre);
        }

        return $this->handleDeleteApi($reversement);
    }

    #[Route(
        '/api/dynamic-query/{idInvite}/{idEntreprise}',
        name: 'app_dynamic_query',
        requirements: ['idEntreprise' => Requirement::DIGITS, 'idInvite' => Requirement::DIGITS],
        methods: ['POST']
    )]
    public function query(Request $request): Response
    {
        return $this->renderViewOrListComponent(ReversementRetroAgent::class, $request, true);
    }

    #[Route('/api/{id}/{collectionName}/{usage}', name: 'api.get_collection', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    public function getCollectionListApi(int $id, string $collectionName, ?string $usage = "generic"): Response
    {
        return $this->handleCollectionApiRequest($id, $collectionName, ReversementRetroAgent::class, $usage);
    }
}
