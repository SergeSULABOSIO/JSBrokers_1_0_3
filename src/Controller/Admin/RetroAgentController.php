<?php

namespace App\Controller\Admin;

use App\Entity\Avenant;
use App\Entity\CompteBancaire;
use App\Entity\Invite;
use App\Entity\ReversementRetroAgent;
use App\Repository\AvenantRepository;
use App\Repository\InviteRepository;
use App\Service\RetroAgent\RapportProductionAgentBuilder;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Services\CanvasBuilder;
use App\Services\Search\CotationSouscriptionScope;
use App\Services\ServiceMonnaies;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * RÉTROCOMMISSION DES AGENTS INTERNES — rapport de production et reversements.
 *
 * ── LA RÈGLE D'ACCÈS, ET POURQUOI ELLE EST PARTICULIÈRE ─────────────────────────────
 * L'entité Invite relève de la GESTION DES INVITÉS (WorkspaceAccessResolver::
 * ROLE_MANAGEMENT_ENTITIES) : sa lecture exige canManageInvites(), un privilège
 * d'administration de l'espace, pas un droit de rubrique. Or l'exigence métier est
 * explicite — un agent doit retrouver, DEPUIS SON PROPRE COMPTE, ce qui lui est dû, payé
 * et restant dû.
 *
 * D'où la règle appliquée ici, fail-closed, et identique pour l'outil de l'assistant :
 *
 *      SOI-MÊME, TOUJOURS.  UN AUTRE AGENT, SEULEMENT SI GESTIONNAIRE D'INVITÉS.
 *
 * Elle ne relâche rien : le périmètre reste borné à l'entreprise active, et un invité
 * ordinaire ne voit jamais la rémunération d'un collègue. Les COLONNES de la rubrique
 * Invités, elles, restent réservées aux gestionnaires — c'est la rubrique qui est gardée,
 * pas le chiffre.
 *
 * Un droit dédié (RolesEnFinance::accessRetrocommission) serait l'extension propre le jour
 * où un rôle « paie » distinct de l'administration apparaîtra. Hors périmètre ici.
 */
#[Route('/admin/retro-agent', name: 'admin.retro_agent.')]
#[IsGranted('ROLE_USER')]
class RetroAgentController extends AbstractController
{
    use ControllerUtilsTrait;

    protected function getCollectionMap(): array
    {
        return [];
    }

    protected function getParentAssociationMap(): array
    {
        return [];
    }

    public function __construct(
        private EntityManagerInterface $em,
        private RapportProductionAgentBuilder $rapportBuilder,
        private WorkspaceAccessResolver $accessResolver,
        private AvenantRepository $avenantRepository,
        // Exigé par ControllerUtilsTrait::getInvite(), qui résout l'invité de l'entreprise
        // ACTIVE (connectedTo) — un utilisateur peut en avoir un par entreprise.
        private InviteRepository $inviteRepository,
        private ServiceMonnaies $serviceMonnaies,
        CanvasBuilder $canvasBuilder,
    ) {
        $this->canvasBuilder = $canvasBuilder;
    }

    /**
     * Rapport de production d'un agent, rendu dans un onglet de la zone de travail.
     * Réponse {html, title} — même contrat que le SOA d'un client.
     */
    #[Route('/{id}/rapport', name: 'rapport', methods: ['GET'])]
    public function rapport(Invite $agent, Request $request): JsonResponse
    {
        $this->assertPeutConsulter($agent);

        $contexte = $this->rapportBuilder->build(
            $agent,
            $agent->getEntreprise(),
            (string) $request->query->get('statut', CotationSouscriptionScope::STATUT_SOUSCRITES),
        );

        return $this->json([
            'html'  => $this->renderView('admin/retro_agent/rapport.html.twig', $contexte),
            'title' => 'Rétrocommissions — ' . $agent->getNom(),
        ]);
    }

    /**
     * Picker de reversement : les affaires où l'agent a un solde EXIGIBLE, à cocher ligne
     * à ligne. Un seul envoi crée autant de reversements que de lignes cochées.
     */
    #[Route('/{id}/reversement-picker', name: 'reversement_picker', methods: ['GET'])]
    public function reversementPicker(Invite $agent): JsonResponse
    {
        $this->assertPeutVerser($agent);

        return $this->json([
            'html' => $this->renderView('components/retro_agent/_reversement_picker.html.twig', [
                'agent'   => $agent,
                'lignes'  => $this->rapportBuilder->lignesAVerser($agent),
                'monnaie' => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                // Les comptes viennent d'ICI : Entreprise n'expose aucune collection de
                // comptes bancaires, et un gabarit qui interrogerait une methode absente
                // echouerait a l'affichage.
                'comptes' => $this->em->getRepository(CompteBancaire::class)
                    ->findBy(['entreprise' => $agent->getEntreprise()], ['intitule' => 'ASC']),
                'submitUrl' => $this->generateUrl('admin.retro_agent.reversement_submit', ['id' => $agent->getId()]),
            ]),
            'title' => 'Reverser une rétrocommission — ' . $agent->getNom(),
        ]);
    }

    /**
     * Enregistrement d'un reversement, éventuellement EN LOT.
     *
     * Les N lignes d'un même envoi partagent une `lotReference` générée ICI, côté serveur :
     * c'est elle qui permettra à la comptabilité d'émettre UNE écriture pour un virement
     * réel plutôt qu'une par affaire. La laisser au client, ce serait accepter qu'un lot se
     * mélange à un autre.
     */
    #[Route('/{id}/reversement', name: 'reversement_submit', methods: ['POST'])]
    public function reversementSubmit(Invite $agent, Request $request): JsonResponse
    {
        $this->assertPeutVerser($agent);

        $donnees = json_decode($request->getContent(), true);
        $lignes = is_array($donnees['lignes'] ?? null) ? $donnees['lignes'] : [];
        if ($lignes === []) {
            return $this->json(['message' => 'Aucune affaire sélectionnée.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $paidAt = $this->dateDeVersement($donnees['paidAt'] ?? null);
        $reference = trim((string) ($donnees['reference'] ?? ''));
        if ($reference === '') {
            $reference = 'RETRO-' . $paidAt->format('dmY-His');
        }
        // Un lot n'existe qu'à partir de DEUX lignes : un reversement isolé garde
        // lotReference à null, pour ne jamais être fondu dans le lot d'un autre.
        $lotReference = count($lignes) > 1 ? $reference : null;

        $compte = null;
        if (!empty($donnees['compteBancaireId'])) {
            $compte = $this->em->getRepository(CompteBancaire::class)->findOneBy([
                'id' => (int) $donnees['compteBancaireId'],
                'entreprise' => $agent->getEntreprise(),
            ]);
        }

        $crees = 0;
        $total = 0.0;
        foreach ($lignes as $ligne) {
            $avenant = $this->avenantDuPerimetre($agent, (int) ($ligne['avenantId'] ?? 0));
            if ($avenant === null) {
                continue;
            }
            $montant = round((float) ($ligne['montant'] ?? 0), 2);
            if ($montant <= 0) {
                continue;
            }

            $reversement = (new ReversementRetroAgent())
                ->setAgent($agent)
                ->setAvenant($avenant)
                ->setMontant($montant)
                ->setPaidAt($paidAt)
                ->setReference($reference)
                ->setLotReference($lotReference)
                ->setCompteBancaire($compte)
                ->setDescription($donnees['description'] ?? null);
            $reversement->setEntreprise($agent->getEntreprise())->setInvite($this->getInvite());
            $this->em->persist($reversement);

            ++$crees;
            $total += $montant;
        }

        if ($crees === 0) {
            return $this->json(
                ['message' => 'Aucune ligne exploitable : vérifiez les montants et les affaires sélectionnées.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $this->em->flush();

        return $this->json([
            'message' => sprintf(
                '%d reversement%s enregistré%s pour un total de %s.',
                $crees,
                $crees > 1 ? 's' : '',
                $crees > 1 ? 's' : '',
                number_format($total, 2, ',', ' '),
            ),
            'crees' => $crees,
            'total' => round($total, 2),
        ]);
    }

    // ===================== Gardes =====================

    /**
     * Consultation : soi-même toujours, un autre agent seulement si gestionnaire d'invités.
     * L'appartenance à l'entreprise ACTIVE est vérifiée d'abord — sans quoi la règle
     * « soi-même » ne dirait rien du périmètre.
     */
    private function assertPeutConsulter(Invite $agent): void
    {
        $connecte = $this->getInvite();
        if ($connecte === null || $agent->getEntreprise() === null
            || $agent->getEntreprise() !== $connecte->getEntreprise()) {
            throw $this->createAccessDeniedException("Cet agent n'est pas dans votre espace de travail.");
        }

        if ($agent->getId() === $connecte->getId()) {
            return; // Ses propres rétrocommissions : toujours consultables.
        }

        if (!$this->accessResolver->canManageInvites($connecte)) {
            throw $this->createAccessDeniedException(
                "Seul le propriétaire de l'espace ou un gestionnaire des invités peut consulter les "
                . "rétrocommissions d'un autre agent.",
            );
        }
    }

    /**
     * VERSER est autre chose que CONSULTER : personne ne se paie soi-même. Le décaissement
     * exige donc le privilège de gestion, même sur sa propre fiche.
     */
    private function assertPeutVerser(Invite $agent): void
    {
        $connecte = $this->getInvite();
        if ($connecte === null || $agent->getEntreprise() === null
            || $agent->getEntreprise() !== $connecte->getEntreprise()) {
            throw $this->createAccessDeniedException("Cet agent n'est pas dans votre espace de travail.");
        }

        if (!$this->accessResolver->canManageInvites($connecte)) {
            throw $this->createAccessDeniedException(
                'Enregistrer un reversement relève de la gestion de l\'espace de travail.',
            );
        }
    }

    /** L'avenant doit exister DANS l'entreprise de l'agent : scoping strict. */
    private function avenantDuPerimetre(Invite $agent, int $id): ?Avenant
    {
        if ($id <= 0) {
            return null;
        }

        return $this->avenantRepository->findOneBy(['id' => $id, 'entreprise' => $agent->getEntreprise()]);
    }

    /** Date fournie, sinon maintenant. Une date illisible ne fait pas échouer la saisie. */
    private function dateDeVersement(?string $brut): \DateTimeImmutable
    {
        $brut = trim((string) $brut);
        if ($brut === '') {
            return new \DateTimeImmutable('now');
        }

        try {
            return new \DateTimeImmutable($brut);
        } catch (\Throwable) {
            return new \DateTimeImmutable('now');
        }
    }
}
