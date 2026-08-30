<?php

namespace App\Controller\Admin;

use App\Ai\Fichier\FichierAttachePolicy;
use App\Entity\Avenant;
use App\Entity\Entreprise;
use App\Entity\CompteBancaire;
use App\Entity\Document;
use App\Entity\Invite;
use App\Entity\Partenaire;
use App\Entity\ReversementRetroAgent;
use App\Repository\AvenantRepository;
use App\Repository\InviteRepository;
use App\Repository\TrancheRepository;
use App\Service\Retro\BeneficiaireRetro;
use App\Service\Retro\DefautsDuVersement;
use App\Service\Retro\JustificatifExige;
use App\Service\Retro\LotDeVersement;
use App\Service\Retro\BeneficiaireRetroFactory;
use App\Service\RetroAgent\RapportProductionAgentBuilder;
use App\Service\Soa\SoaPoliceDocumentsCollector;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Services\CanvasBuilder;
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
        // Les DEUX familles se règlent ici. La fabrique est le seul endroit qui sait de
        // quoi chacune est faite : le contrôleur n'a plus à porter leurs dépendances.
        private BeneficiaireRetroFactory $beneficiaires,
        private TrancheRepository $trancheRepository,
        CanvasBuilder $canvasBuilder,
    ) {
        $this->canvasBuilder = $canvasBuilder;
    }

    /**
     * LE BÉNÉFICIAIRE D'UN REVERSEMENT, quelle que soit sa famille — et le scoping.
     *
     * Le XOR agent / partenaire est celui de l'entité : le lire ici, une fois, évite que
     * chaque route en traite une moitié — ce qui est précisément arrivé au rapport.
     */
    private function cibleDuReversement(ReversementRetroAgent $reversement): Invite|Partenaire
    {
        $cible = $reversement->getAgent() ?? $reversement->getPartenaire();
        if ($cible === null
            || $reversement->getEntreprise()?->getId() !== $this->getInvite()?->getEntreprise()?->getId()) {
            throw $this->createNotFoundException('Reversement introuvable.');
        }

        return $cible;
    }

    /**
     * OUVRIR UN VIREMENT EN ÉDITION.
     *
     * « Éditer » sur une ligne de la rubrique n'ouvre pas le dialogue générique mais CETTE
     * fenêtre : une ligne y représente un virement entier, et le dialogue n'en aurait
     * montré qu'une échéance sur six. C'est aussi le seul endroit où le détail d'un
     * virement se lit, depuis que la rubrique le replie.
     *
     * La fenêtre est LA MÊME qu'à la création — même gabarit, même contrôleur, mêmes
     * gardes. Seules changent les lignes proposées (cf. `echeancesDuVirement`) et l'URL
     * d'envoi.
     */
    #[Route('/reversement/{id}/editer', name: 'reversement_editer', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    public function editerVirement(ReversementRetroAgent $reversement): Response
    {
        $cible = $this->cibleDuReversement($reversement);
        $this->assertPeutVerserA($cible);

        $membres = $this->lotDeVersement->membresDuLot($reversement);
        $beneficiaire = $this->beneficiaires->pour($cible);
        $entreprise = $reversement->getEntreprise();

        $virement = $this->rapportBuilder->echeancesDuVirement($beneficiaire, $entreprise, $membres);
        $porteur = $this->lotDeVersement->porteurParmi($membres);

        return $this->rendrePicker(
            $beneficiaire,
            $entreprise,
            $this->generateUrl('admin.retro_agent.reversement_editer', ['id' => $reversement->getId()]),
            [
                'lignes' => $virement['lignes'],
                'cochees' => $virement['cochees'],
                'edition' => [
                    'porteurId' => $porteur?->getId(),
                    'reference' => $reversement->getReference(),
                    'paidAt' => $reversement->getPaidAt(),
                    'compteId' => $reversement->getCompteBancaire()?->getId(),
                    'description' => $reversement->getDescription(),
                    // LES PIÈCES DÉJÀ DÉPOSÉES, NOMMÉES ET RETIRABLES.
                    //
                    // Annoncer « 1 pièce » sans la montrer laissait l'utilisateur devant un
                    // compte qu'il ne pouvait ni vérifier ni corriger : pour remplacer un
                    // bordereau, il fallait sortir de la fenêtre, ouvrir la boîte des
                    // documents, y supprimer la pièce, puis revenir. On les liste donc ici.
                    'pieces' => array_map(
                        static fn (Document $d) => ['id' => $d->getId(), 'nom' => $d->getNom()],
                        $this->lotDeVersement->documentsDuLot($membres),
                    ),
                ],
            ],
        );
    }

    /** L'écriture d'un virement rouvert : mêmes gardes, même corps, un lot en plus. */
    #[Route('/reversement/{id}/editer', name: 'reversement_editer_submit', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    public function editerVirementSubmit(ReversementRetroAgent $reversement, Request $request): JsonResponse
    {
        $cible = $this->cibleDuReversement($reversement);
        $this->assertPeutVerserA($cible);

        return $this->enregistrerVersement(
            $cible,
            $reversement->getEntreprise(),
            $request,
            $this->lotDeVersement->membresDuLot($reversement),
        );
    }

    /**
     * RETIRE LES PIÈCES QUE L'UTILISATEUR A MARQUÉES, ET REND CE QU'IL EN RESTE.
     *
     * Le périmètre est le VIREMENT : seuls les documents rattachés à l'une de ses lignes
     * peuvent être visés. Un identifiant venu d'ailleurs est simplement ignoré — jamais
     * suivi. C'est la seule garde nécessaire, et elle est fail-closed.
     *
     * @param ReversementRetroAgent[] $membres
     * @param int[]                   $retirees
     *
     * @return int le nombre de pièces qui restent au virement
     */
    private function retirerPieces(array $membres, array $retirees): int
    {
        if ($membres === []) {
            return 0;
        }

        $documents = $this->lotDeVersement->documentsDuLot($membres);
        $restants = 0;
        foreach ($documents as $document) {
            if (in_array($document->getId(), $retirees, true)) {
                $this->em->remove($document);
                continue;
            }
            ++$restants;
        }

        if ($restants !== count($documents)) {
            $this->em->flush();
        }

        return $restants;
    }

    /**
     * LES DOCUMENTS D'UNE LIGNE QUI SORT DU VIREMENT SUIVENT LE VIREMENT.
     *
     * La pièce justificative d'un virement est écrite sur son PORTEUR — le plus petit id.
     * Retirer cette ligne-là du virement aurait donc détruit le bordereau avec elle : le
     * décaissement se serait retrouvé sans preuve, alors que la règle en exige une, et rien
     * ne l'aurait dit — ni erreur, ni avertissement.
     *
     * On les rattache au membre qui reste, celui qui deviendra porteur. Si le virement ne
     * garde plus aucune ligne, le cas ne se pose pas ici : c'est une suppression.
     *
     * @param ReversementRetroAgent[] $restants
     */
    private function transfererDocuments(ReversementRetroAgent $sortant, array $restants): void
    {
        $repreneur = $this->lotDeVersement->porteurParmi($restants);
        if ($repreneur === null || $repreneur === $sortant) {
            return;
        }

        // ON NE TOUCHE QUE LE CÔTÉ PROPRIÉTAIRE, et on laisse le document dans la
        // collection du sortant : l'en retirer l'aurait fait supprimer comme ORPHELIN
        // (`orphanRemoval: true`), c'est-à-dire exactement ce qu'on cherche à éviter.
        // La collection est relue après le flush, avant la suppression.
        //
        // ON INTERROGE LE DÉPÔT, PAS LA COLLECTION. Une collection déjà chargée en
        // mémoire ne se recharge pas : si la pièce a été rattachée par son côté
        // propriétaire — ce que fait la route d'attachement — la collection du
        // reversement peut l'ignorer, et le transfert n'aurait alors rien transféré.
        // La question « quelles pièces pointent sur cette ligne ? » se pose à la base.
        $documents = $this->em->getRepository(\App\Entity\Document::class)
            ->findBy(['reversementRetroAgent' => $sortant]);

        foreach ($documents as $document) {
            $document->setReversementRetroAgent($repreneur);
            $repreneur->addDocument($document);
            $this->em->persist($document);
        }
    }

    /** La garde de versement, aiguillée sur la famille — les deux règles existent déjà. */
    private function assertPeutVerserA(Invite|Partenaire $cible): void
    {
        $cible instanceof Partenaire
            ? $this->assertPeutVerserAuPartenaire($cible)
            : $this->assertPeutVerser($cible);
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
        return $this->rendrePicker(
            $this->beneficiaires->pour($agent),
            $agent->getEntreprise(),
            $this->generateUrl('admin.retro_agent.reversement_submit', ['id' => $agent->getId()]),
        );
    }

    /**
     * LE PICKER, POUR L'UNE OU L'AUTRE FAMILLE.
     *
     * Un seul corps : les deux bénéficiaires règlent les mêmes ÉCHÉANCES, avec la même
     * exigence de justificatif et les mêmes défauts de saisie. Deux copies auraient divergé
     * au premier ajout de colonne — c'est ce qui est arrivé au rapport avant son extraction.
     */
    private function rendrePicker(
        BeneficiaireRetro $beneficiaire,
        Entreprise $entreprise,
        string $submitUrl,
        array $surcharges = [],
    ): Response {
        // DU HTML, PAS DU JSON. L'ouvreur de pickers autonomes (`picker-open.js`, partagé
        // avec le portefeuille, les risques ciblés et les clients) lit la réponse en TEXTE
        // et l'insère telle quelle. Une enveloppe JSON lui donnait donc une chaîne sans
        // aucun élément — « Contenu du picker vide » — et le bouton ne faisait rien.
        $contexte = [
            'beneficiaireNom' => $beneficiaire->nom(),
            // La FAMILLE : elle décide du vocabulaire de la boîte, et surtout du compte
            // SYSCOHADA qu'elle annonce — 6611 (charges de personnel) pour un agent,
            // 632 (rétrocommissions) pour un intermédiaire externe.
            'beneficiaireEstAgent' => $beneficiaire->type() === BeneficiaireRetro::TYPE_AGENT,
            // LES ÉCHÉANCES, et non les affaires : la prime et la commission se paient par
            // tranche, donc c'est une échéance qu'on règle. Proposer l'affaire obligerait
            // ensuite à répartir le versement, règle que personne n'a écrite.
            'lignes'  => $this->rapportBuilder->echeancesAVerser($beneficiaire, $entreprise),
            'monnaie' => $this->serviceMonnaies->getCodeMonnaieAffichage(),
            // Les comptes viennent d'un SERVICE : Entreprise n'expose aucune collection
            // de comptes bancaires, et surtout l'ordre de cette liste détermine lequel
            // est proposé — Ket doit proposer le même.
            'comptes' => $this->defautsDuVersement->comptes($entreprise),
            'compteProposeId' => $this->defautsDuVersement->comptePropose($entreprise)?->getId(),
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
            'submitUrl' => $submitUrl,
            // L'ÉDITION N'EST PAS UNE SECONDE FENÊTRE : c'est la même, à qui l'on
            // remplace les lignes proposées et à qui l'on donne les valeurs du virement
            // rouvert. Ces deux clés valent leur défaut à la création.
            'cochees' => [],
            'edition' => null,
        ];

        // L'UNION `+` GARDE LA GAUCHE : les surcharges l'emportent, et c'est le sens
        // voulu. Écrite dans l'autre ordre, elle aurait silencieusement rendu les lignes
        // de la CRÉATION sur une fenêtre d'édition — le piège déjà rencontré ailleurs
        // dans ce projet (une note d'outil que l'union jetait).
        return $this->render('components/retro_agent/_reversement_picker.html.twig', $surcharges + $contexte);
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

        return $this->enregistrerVersement($agent, $agent->getEntreprise(), $request);
    }

    /**
     * L'ENREGISTREMENT D'UN VERSEMENT, POUR L'UNE OU L'AUTRE FAMILLE.
     *
     * Le bénéficiaire est un agent interne OU un partenaire externe ; tout le reste — la
     * garde de justificatif, la référence, le lot, le compte débité, la maille — est
     * identique. Deux copies auraient divergé, et l'une aurait fini par écrire des lignes
     * que l'autre ne sait pas lire.
     */
    private function enregistrerVersement(
        Invite|Partenaire $beneficiaire,
        Entreprise $entreprise,
        Request $request,
        array $membresExistants = [],
    ): JsonResponse {
        $donnees = json_decode($request->getContent(), true);
        $lignes = is_array($donnees['lignes'] ?? null) ? $donnees['lignes'] : [];
        if ($lignes === []) {
            return $this->json(['message' => 'Aucune affaire sélectionnée.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // PAS DE VERSEMENT SANS PREUVE, et la garde est ICI — avant la moindre écriture.
        // Le client annonce la pièce qu'il déposera juste après (la cible n'existe pas
        // encore quand il la choisit) ; refuser plus tard laisserait un décaissement
        // enregistré sans justificatif, exactement ce que la règle interdit.
        // LES PIÈCES QU'ON RETIRE PARTENT D'ABORD, et la garde compte ensuite ce qui reste.
        //
        // L'ordre n'est pas indifférent : compter avant le retrait aurait laissé passer un
        // enregistrement qui supprime la dernière preuve du virement — un décaissement nu,
        // accepté par la règle qui l'interdit.
        $retireesIds = array_values(array_filter(array_map(
            'intval',
            is_array($donnees['piecesRetirees'] ?? null) ? $donnees['piecesRetirees'] : [],
        )));
        $piecesRestantes = $this->retirerPieces($membresExistants, $retireesIds);

        // UN VIREMENT ROUVERT A DÉJÀ SA PREUVE. Redemander un bordereau pour corriger une
        // date aurait fait déposer deux fois la même pièce — ou renoncé à la correction.
        if ($piecesRestantes === 0 && !$this->justificatifExige->estSatisfait(($donnees['avecPiece'] ?? false) === true)) {
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
                'entreprise' => $entreprise,
            ]);
        }

        // LES MEMBRES DU VIREMENT ROUVERT, indexés par le couple (échéance, affaire) —
        // c'est ce couple qu'une ligne postée désigne, et il ne peut y en avoir qu'un par
        // virement. Ce qui restera dans ce tableau à la fin n'a pas été reposté : ce sont
        // les lignes que l'utilisateur a retirées du virement.
        $aRapprocher = [];
        foreach ($membresExistants as $membre) {
            $aRapprocher[RapportProductionAgentBuilder::cleDeLigne(
                $membre->getTranche()?->getId(),
                $membre->getAvenant()?->getId(),
            )] = $membre;
        }

        $crees = 0;
        $total = 0.0;
        $ecrits = [];
        foreach ($lignes as $ligne) {
            // LA LIGNE DÉSIGNE UNE ÉCHÉANCE, et porte l'affaire qu'elle solde. Les deux
            // voyagent ensemble : la tranche dit QUAND, l'avenant dit SUR QUOI.
            $tranche = $this->trancheDuPerimetre($entreprise, (int) ($ligne['trancheId'] ?? 0));
            $avenant = $this->avenantDuPerimetre($entreprise, (int) ($ligne['avenantId'] ?? 0));
            if ($tranche === null && $avenant === null) {
                continue;
            }
            // L'INVARIANT, VÉRIFIÉ AVANT D'ÉCRIRE : une échéance et une affaire de deux
            // propositions différentes rendraient les deux soldes faux, sans erreur.
            if ($tranche !== null && $avenant !== null
                && $tranche->getCotation()?->getId() !== $avenant->getCotation()?->getId()) {
                continue;
            }
            $montant = round((float) ($ligne['montant'] ?? 0), 2);
            if ($montant <= 0) {
                continue;
            }

            // ON MET À JOUR CE QUI EXISTE, ON NE LE RECRÉE PAS. Recréer aurait changé
            // l'identifiant de la ligne — donc perdu les documents qui y sont rattachés et
            // rompu toute référence comptable déjà émise.
            $cle = RapportProductionAgentBuilder::cleDeLigne($tranche?->getId(), $avenant?->getId());
            $reversement = $aRapprocher[$cle] ?? new ReversementRetroAgent();
            unset($aRapprocher[$cle]);

            $reversement
                ->setAgent($beneficiaire instanceof Invite ? $beneficiaire : null)
                ->setPartenaire($beneficiaire instanceof Partenaire ? $beneficiaire : null)
                ->setTranche($tranche)
                ->setAvenant($avenant)
                ->setMontant($montant)
                ->setPaidAt($paidAt)
                ->setReference($reference)
                ->setLotReference($lotReference)
                ->setCompteBancaire($compte)
                ->setDescription($donnees['description'] ?? null);
            $reversement->setEntreprise($entreprise)->setInvite($this->getInvite());
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

        // CE QUI N'A PAS ÉTÉ REPOSTÉ SORT DU VIREMENT.
        //
        // ⚠ LE PIÈGE DU PORTEUR. La pièce justificative est écrite sur le PORTEUR du lot —
        // le plus petit id. Retirer cette ligne-là détruirait le bordereau avec elle, et le
        // virement se retrouverait sans preuve sans que rien ne le dise. On transfère donc
        // ses documents au membre qui reste, AVANT de la supprimer.
        // EN DEUX TEMPS, ET C'EST NÉCESSAIRE. La collection `documents` est déclarée
        // `cascade: ['remove'], orphanRemoval: true` : les deux gestes possibles échouaient
        // dans le MÊME flush — laisser le document dans la collection le faisait supprimer
        // en cascade, l'en retirer le faisait supprimer comme orphelin. On écrit donc
        // d'abord le nouveau rattachement, puis on relit la ligne sortante (sa collection
        // est alors vide) avant de la supprimer.
        // EN DEUX TEMPS, ET C'EST NÉCESSAIRE.
        //
        // La collection `documents` est déclarée `cascade: ['remove'], orphanRemoval: true`.
        // Les deux gestes possibles détruisaient donc le bordereau : le laisser dans la
        // collection le faisait supprimer EN CASCADE avec sa ligne ; l'en retirer le faisait
        // supprimer comme ORPHELIN. Et `refresh()` ne réinitialise pas une collection déjà
        // chargée : la cascade retrouvait le document malgré le nouveau rattachement.
        //
        // On écrit donc d'abord le rattachement au membre qui reste, puis on supprime les
        // lignes sortantes en DQL — une suppression qui ne cascade pas, ce qui est
        // exactement ce qu'on veut une fois les pièces mises à l'abri.
        $sortantsIds = [];
        foreach ($aRapprocher as $membre) {
            $this->transfererDocuments($membre, $ecrits);
            $sortantsIds[] = $membre->getId();
        }
        $this->em->flush();

        $retires = 0;
        if ($sortantsIds !== []) {
            $retires = (int) $this->em->createQuery(
                'DELETE FROM ' . ReversementRetroAgent::class . ' r WHERE r.id IN (:ids)',
            )->setParameter('ids', $sortantsIds)->execute();

            // Les entités supprimées en base doivent sortir de la mémoire de Doctrine, sans
            // quoi un flush ultérieur tenterait de les réécrire.
            foreach ($aRapprocher as $membre) {
                $this->em->detach($membre);
            }
        }

        $this->em->flush();

        return $this->json([
            'message' => sprintf(
                '%d reversement%s enregistré%s pour un total de %s.%s',
                $crees,
                $crees > 1 ? 's' : '',
                $crees > 1 ? 's' : '',
                number_format($total, 2, ',', ' '),
                // CE QUI A ÉTÉ RETIRÉ SE DIT. Un virement qui maigrit sans le dire laisse
                // croire à une erreur de saisie plutôt qu'à la correction qu'on vient de
                // demander.
                $retires > 0
                    ? sprintf(' %d échéance%s retirée%s du virement.', $retires, $retires > 1 ? 's' : '', $retires > 1 ? 's' : '')
                    : '',
            ),
            'crees' => $crees,
            'retires' => $retires,
            'total' => round($total, 2),
            // LE PORTEUR DU LOT : celui des reversements qui gardera la pièce. Le client
            // y poste ses fichiers juste après, sur la route générique. Un seul fichier
            // en base, quel que soit le nombre d'affaires soldées par ce virement.
            'porteurId' => $this->lotDeVersement->porteurParmi($ecrits)?->getId(),
        ]);
    }

    // ===================== Gardes =====================

    /** Le picker d'un partenaire : mêmes échéances réglables, même gabarit. */
    #[Route('/partenaire/{id}/reversement-picker', name: 'reversement_picker_partenaire', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    public function reversementPickerPartenaire(Partenaire $partenaire): Response
    {
        $this->assertPeutVerserAuPartenaire($partenaire);

        return $this->rendrePicker(
            $this->partenaireRetro($partenaire),
            $partenaire->getEntreprise(),
            $this->generateUrl('admin.retro_agent.reversement_submit_partenaire', ['id' => $partenaire->getId()]),
        );
    }

    /** L'enregistrement d'un versement à un partenaire : même corps, autre bénéficiaire. */
    #[Route('/partenaire/{id}/reversement', name: 'reversement_submit_partenaire', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    public function reversementSubmitPartenaire(Partenaire $partenaire, Request $request): JsonResponse
    {
        $this->assertPeutVerserAuPartenaire($partenaire);

        return $this->enregistrerVersement($partenaire, $partenaire->getEntreprise(), $request);
    }

    private function partenaireRetro(Partenaire $partenaire): BeneficiaireRetro
    {
        return $this->beneficiaires->pour($partenaire);
    }

    /** Un partenaire d'un AUTRE cabinet n'existe pas ici : scoping strict, comme l'agent. */
    private function assertPartenaireDuPerimetre(Partenaire $partenaire): void
    {
        $connecte = $this->getInvite();
        if ($connecte === null || $partenaire->getEntreprise() === null
            || $partenaire->getEntreprise() !== $connecte->getEntreprise()) {
            throw $this->createAccessDeniedException("Ce partenaire n'est pas dans votre espace de travail.");
        }
    }

    /**
     * VERSER À UN PARTENAIRE relève du droit de la rubrique, non de la gestion des invités.
     *
     * Pour un AGENT, la garde est `canManageInvites` — personne ne se paie soi-même, et
     * l'agent est un salarié dont la rémunération relève de l'administration de l'espace.
     * Un partenaire externe n'est pas un invité : lui régler sa facture relève du droit
     * d'écriture sur les rétros, celui qui gouverne déjà la rubrique.
     */
    private function assertPeutVerserAuPartenaire(Partenaire $partenaire): void
    {
        $this->assertPartenaireDuPerimetre($partenaire);

        if (!$this->accessResolver->can($this->getInvite(), 'ReversementRetroAgent', Invite::ACCESS_ECRITURE)) {
            throw $this->createAccessDeniedException(
                'Enregistrer un reversement exige le droit d\'écriture sur les rétros intermédiaires.',
            );
        }
    }

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

    /** L'échéance doit exister DANS l'entreprise : scoping strict, comme l'avenant. */
    private function trancheDuPerimetre(Entreprise $entreprise, int $id): ?\App\Entity\Tranche
    {
        if ($id <= 0) {
            return null;
        }

        return $this->trancheRepository->findOneBy(['id' => $id, 'entreprise' => $entreprise]);
    }

    /** L'avenant doit exister DANS l'entreprise de l'agent : scoping strict. */
    private function avenantDuPerimetre(Entreprise $entreprise, int $id): ?Avenant
    {
        if ($id <= 0) {
            return null;
        }

        return $this->avenantRepository->findOneBy(['id' => $id, 'entreprise' => $entreprise]);
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
