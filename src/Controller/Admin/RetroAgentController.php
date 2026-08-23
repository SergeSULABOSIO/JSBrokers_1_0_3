<?php

namespace App\Controller\Admin;

use App\Ai\Fichier\FichierAttachePolicy;
use App\Entity\Avenant;
use App\Entity\CompteBancaire;
use App\Entity\Invite;
use App\Entity\ReversementRetroAgent;
use App\Repository\AvenantRepository;
use App\Repository\InviteRepository;
use App\Service\Retro\DefautsDuVersement;
use App\Service\Retro\JustificatifExige;
use App\Service\Retro\LotDeVersement;
use App\Service\RetroAgent\RapportProductionAgentBuilder;
use App\Service\Soa\SoaPoliceDocumentsCollector;
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
use Symfony\Component\Routing\Requirement\Requirement;
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
        // Les valeurs proposées du versement (référence, compte débité) sont les
        // MÊMES pour l'écran et pour Ket : elles vivent hors d'ici.
        private DefautsDuVersement $defautsDuVersement,
        private JustificatifExige $justificatifExige,
        private LotDeVersement $lotDeVersement,
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

        // LA DETTE DE PREUVE, LÀ OÙ ON LIT LES MONTANTS. Une affaire payée sans
        // justificatif ne se voyait nulle part : il fallait ouvrir un volet pour
        // l'apprendre. Le compte est joint au contexte, en UNE agrégation pour tout
        // le rapport — pas une requête par ligne.
        $contexte['piecesParAvenant'] = $this->lotDeVersement
            ->comptesDePiecesParAvenant($agent, $agent->getEntreprise());

        return $this->json([
            'html'  => $this->renderView('admin/retro_agent/rapport.html.twig', $contexte),
            'title' => 'Rétrocommissions — ' . $agent->getNom(),
        ]);
    }

    /**
     * LE RAPPORT DE PRODUCTION, DEPUIS UNE LIGNE DE REVERSEMENT.
     *
     * L'`%id%` d'une action de barre d'outils est celui de la LIGNE sélectionnée — ici un
     * reversement, pas un agent. D'où cette route de traduction : elle résout le
     * bénéficiaire et délègue au rapport, plutôt que d'inventer un `%agentId%` que le
     * mécanisme d'actions ne sait pas produire.
     */
    #[Route('/reversement/{id}/rapport', name: 'rapport_depuis_reversement', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    public function rapportDepuisReversement(ReversementRetroAgent $reversement, Request $request): JsonResponse
    {
        $agent = $reversement->getAgent();
        if ($agent === null || $reversement->getEntreprise()?->getId() !== $this->getInvite()->getEntreprise()?->getId()) {
            throw $this->createNotFoundException('Reversement introuvable.');
        }

        return $this->rapport($agent, $request);
    }
    /**
     * LES JUSTIFICATIFS D'UN VERSEMENT — c'est-à-dire de son VIREMENT.
     *
     * Un bordereau couvre tout un lot, et l'assistant peut avoir classé la pièce sur
     * n'importe lequel de ses membres. On rend donc l'UNION des pièces du virement : s'en
     * tenir à la ligne ferait passer pour nues deux lignes sur trois d'un versement groupé
     * pourtant justifié.
     *
     * Cette action REMPLACE la relecture générique pour cette rubrique : le canevas la
     * déclare lui-même, ce qui empêche l'injection de l'autre (porteDejaUneVueDocuments) et
     * évite deux entrées du même nom disant deux choses.
     */
    #[Route('/reversement/{id}/justificatifs', name: 'reversement_justificatifs', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    public function justificatifsDuVersement(
        ReversementRetroAgent $reversement,
        SoaPoliceDocumentsCollector $collector,
        WorkspaceAccessResolver $accessResolver,
    ): Response {
        if ($reversement->getEntreprise()?->getId() !== $this->getInvite()->getEntreprise()?->getId()) {
            throw $this->createNotFoundException('Reversement introuvable.');
        }

        $agent = $reversement->getAgent();
        $membres = $agent !== null
            ? $this->lotDeVersement->membres($agent, $reversement->getEntreprise(), $this->lotDeVersement->cle($reversement))
            : [$reversement];
        if ($membres === []) {
            $membres = [$reversement];
        }

        $reference = $reversement->getReference() ?: ('#' . $reversement->getId());
        $rubrique = $accessResolver->libellesEntites()['ReversementRetroAgent'] ?? 'Reversements';

        return $this->render('components/soa/_documents_picker.html.twig', [
            'titre'              => sprintf('Justificatifs du virement « %s »', $reference),
            'contexteNom'        => $reference,
            'items'              => $collector->decrire($this->lotDeVersement->documentsDuLot($membres), $rubrique),
            'downloadUrlPattern' => '/admin/document/api/%did%/download',
        ]);
    }
    /**
     * Picker de reversement : les affaires où l'agent a un solde EXIGIBLE, à cocher ligne
     * à ligne. Un seul envoi crée autant de reversements que de lignes cochées.
     */
    #[Route('/{id}/reversement-picker', name: 'reversement_picker', methods: ['GET'])]
    public function reversementPicker(Invite $agent): Response
    {
        $this->assertPeutVerser($agent);

        // DU HTML, PAS DU JSON. L'ouvreur de pickers autonomes (`picker-open.js`, partagé
        // avec le portefeuille, les risques ciblés et les clients) lit la réponse en TEXTE
        // et l'insère telle quelle. Une enveloppe JSON lui donnait donc une chaîne sans
        // aucun élément — « Contenu du picker vide » — et le bouton de reversement ne
        // faisait rien d'autre qu'une notification d'erreur.
        return $this->render('components/retro_agent/_reversement_picker.html.twig', [
            'agent'   => $agent,
            'lignes'  => $this->rapportBuilder->lignesAVerser($agent),
            'monnaie' => $this->serviceMonnaies->getCodeMonnaieAffichage(),
            // Les comptes viennent d'un SERVICE : Entreprise n'expose aucune collection
            // de comptes bancaires, et surtout l'ordre de cette liste détermine lequel
            // est proposé — Ket doit proposer le même.
            'comptes' => $this->defautsDuVersement->comptes($agent->getEntreprise()),
            'compteProposeId' => $this->defautsDuVersement->comptePropose($agent->getEntreprise())?->getId(),
            // Pré-remplie à l'instant de l'ouverture : l'utilisateur voit la référence
            // qui sera écrite, et peut la remplacer par celle de son virement réel.
            'referenceParDefaut' => $this->defautsDuVersement->reference(new \DateTimeImmutable('now')),
            // La zone de dépôt est celle des fiches : mêmes limites, même table de
            // familles, mêmes refus. Rien n'est redéfini ici.
            'limites' => FichierAttachePolicy::limitesFront(),
            'famillesParExtension' => SoaPoliceDocumentsCollector::famillesParExtension(),
            // Le gabarit d'URL d'attachement : l'identifiant du porteur du lot n'est
            // connu qu'APRÈS l'écriture des reversements, le client le substitue.
            'attacherUrlPattern' => $this->generateUrl('admin.document.api.attacher', [
                'parent' => 'reversementRetroAgent',
                'id' => 0,
            ]),
            // APRÈS L'ÉCRITURE, IL FAUT RAFRAÎCHIR CE QUI EST À L'ÉCRAN. Le picker
            // s'ouvre aussi depuis le rapport de production, qui n'est pas une liste :
            // on lui donne de quoi le redemander, sans quoi il resterait sur les
            // montants d'avant le versement.
            'rapportUrl' => $this->generateUrl('admin.retro_agent.rapport', ['id' => $agent->getId()]),
            'submitUrl' => $this->generateUrl('admin.retro_agent.reversement_submit', ['id' => $agent->getId()]),
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

        // PAS DE VERSEMENT SANS PREUVE, et la garde est ICI — avant la moindre écriture.
        // Le client annonce la pièce qu'il déposera juste après (la cible n'existe pas
        // encore quand il la choisit) ; refuser plus tard laisserait un décaissement
        // enregistré sans justificatif, exactement ce que la règle interdit.
        if (!$this->justificatifExige->estSatisfait(($donnees['avecPiece'] ?? false) === true)) {
            return $this->json(
                ['message' => $this->justificatifExige->messageEcran()],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $paidAt = $this->dateDeVersement($donnees['paidAt'] ?? null);
        $reference = trim((string) ($donnees['reference'] ?? ''));
        if ($reference === '') {
            // Le champ est pré-rempli à l'ouverture : ne revient vide que si
            // l'utilisateur l'a effacé. On lui en rend une plutôt que d'écrire
            // un reversement anonyme, introuvable en rapprochement bancaire.
            $reference = $this->defautsDuVersement->reference($paidAt);
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
        $ecrits = [];
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

            $ecrits[] = $reversement;
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
            // LE PORTEUR DU LOT : celui des reversements qui gardera la pièce. Le client
            // y poste ses fichiers juste après, sur la route générique. Un seul fichier
            // en base, quel que soit le nombre d'affaires soldées par ce virement.
            'porteurId' => $this->lotDeVersement->porteurParmi($ecrits)?->getId(),
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
