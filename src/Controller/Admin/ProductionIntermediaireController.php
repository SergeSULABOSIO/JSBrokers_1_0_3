<?php

namespace App\Controller\Admin;

use App\Entity\Invite;
use App\Entity\Partenaire;
use App\Entity\ReversementRetroAgent;
use App\Repository\EntrepriseRepository;
use App\Repository\InviteRepository;
use App\Service\Retro\BeneficiaireRetro;
use App\Service\Retro\BeneficiaireRetroFactory;
use App\Service\Retro\LotDeVersement;
use App\Service\Soa\SoaPoliceDocumentsCollector;
use App\Service\RetroAgent\RapportProductionAgentBuilder;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Services\CanvasBuilder;
use App\Services\Search\ProductionScope;
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
 * LA RUBRIQUE « PRODUCTION INTERMÉDIAIRES ».
 *
 * Elle montre ce que chaque intermédiaire — agent interne ou partenaire externe — apporte
 * au cabinet, affaire par affaire. C'est l'ancien rapport de production, qui ne s'ouvrait
 * que par la porte d'une fiche et ne se situait nulle part dans l'arbre du menu.
 *
 * ── POURQUOI CE CONTRÔLEUR NE PASSE PAS PAR `renderViewOrListComponent` ─────────────
 * Le socle des rubriques interroge Doctrine : il prend une classe d'entité et la cherche.
 * Ici il n'y a pas d'entité. Les affaires d'un intermédiaire sont choisies par le MOTEUR
 * de partage, cotation par cotation (`BeneficiaireRetro::cotations()` — « le moteur
 * tranche, pas nous ») : les traduire en SQL reviendrait à réécrire la cascade de partage,
 * et à la voir diverger au premier ajustement.
 *
 * Ce qui est réutilisé, c'est le CONTRAT — la forme des variables que la coquille attend,
 * et celle de la réponse JSON qu'attend le cerveau. Pas son implémentation.
 *
 * ── ET AUCUN MONTANT N'EST CALCULÉ ICI ──────────────────────────────────────────────
 * Toutes les lignes viennent de `RapportProductionAgentBuilder`, inchangé. Le contrôleur
 * choisit un bénéficiaire, un statut, et rend ce que le constructeur lui donne.
 */
#[IsGranted('ROLE_USER')]
#[Route('/admin/productionintermediaire')]
class ProductionIntermediaireController extends AbstractController
{
    use ControllerUtilsTrait;

    public function __construct(
        private EntityManagerInterface $em,
        private RapportProductionAgentBuilder $rapportBuilder,
        private BeneficiaireRetroFactory $beneficiaires,
        private WorkspaceAccessResolver $accessResolver,
        private ServiceMonnaies $serviceMonnaies,
        // La dette de preuve : quelles affaires ont été réglées avec un justificatif.
        private LotDeVersement $lotDeVersement,
        private InviteRepository $inviteRepository,
        private EntrepriseRepository $entrepriseRepository,
        CanvasBuilder $canvasBuilder,
    ) {
        $this->canvasBuilder = $canvasBuilder;
    }

    /**
     * AUCUNE COLLECTION : une ligne de cette rubrique n'est pas un enregistrement, elle
     * n'a donc rien à déplier en onglet contextuel. Le trait l'exige de tous ses
     * utilisateurs — c'est ici la réponse « rien », et elle est exacte.
     *
     * @return array<string, string>
     */
    protected function getCollectionMap(): array
    {
        return [];
    }

    /** Le chargement initial de la rubrique : la coquille, et sa première page. */
    #[Route('/{idInvite}/{idEntreprise}', name: 'admin.productionintermediaire.index', requirements: ['idEntreprise' => Requirement::DIGITS, 'idInvite' => Requirement::DIGITS], methods: ['GET'])]
    public function index(Request $request): Response
    {
        return $this->rendre($request, false);
    }

    /**
     * Le rafraîchissement : recherche, chips, sélection d'un bénéficiaire.
     * Le nom de route suit la convention du socle — c'est celui que `mainListUrl`
     * fabrique, et que le contrôleur Stimulus rappelle à chaque changement de critère.
     */
    #[Route(
        '/api/dynamic-query/{idInvite}/{idEntreprise}',
        name: 'admin.productionintermediaire.app_dynamic_query',
        requirements: ['idEntreprise' => Requirement::DIGITS, 'idInvite' => Requirement::DIGITS],
        methods: ['POST'],
    )]
    public function query(Request $request): Response
    {
        return $this->rendre($request, true);
    }

    /**
     * OUVRIR LA RUBRIQUE SUR QUELQU'UN — depuis la fiche d'un agent ou d'un partenaire.
     *
     * Le mécanisme d'actions ne sait transporter qu'un ÉVÉNEMENT et une URL : il ne peut
     * pas porter un jeu de critères. Plutôt que d'apprendre au navigateur à fabriquer
     * celui-ci — ce qui en aurait fait une seconde source, à côté de `ProductionScope` —
     * on lui rend ici le critère TOUT FAIT, avec le nom qui s'affichera en badge.
     *
     * C'est donc la même construction que celle du chip et que celle de l'assistant :
     * `ProductionScope::critereBeneficiaire()`, appelée d'un seul endroit.
     */
    #[Route(
        '/ouvrir/{famille}/{id}',
        name: 'admin.productionintermediaire.ouvrir',
        requirements: ['famille' => 'agent|partenaire', 'id' => Requirement::DIGITS],
        methods: ['GET'],
    )]
    public function ouvrirSur(string $famille, int $id): JsonResponse
    {
        return $this->ouverture($famille, $id);
    }

    /**
     * LA PRODUCTION DU BÉNÉFICIAIRE D'UN REVERSEMENT.
     *
     * L'`%id%` d'une action de barre d'outils est celui de la LIGNE sélectionnée — ici un
     * versement, pas un intermédiaire. Cette route traduit, exactement comme le faisait
     * l'ancienne route du rapport, aujourd'hui supprimée : sans elle, l'action de la
     * rubrique des rétros n'aurait plus rien à ouvrir.
     */
    #[Route(
        '/ouvrir/reversement/{id}',
        name: 'admin.productionintermediaire.ouvrir_depuis_reversement',
        requirements: ['id' => Requirement::DIGITS],
        methods: ['GET'],
    )]
    public function ouvrirDepuisReversement(ReversementRetroAgent $reversement): JsonResponse
    {
        // LES DEUX FAMILLES vivent sur le même enregistrement, en XOR : lire la seule
        // colonne `agent` aurait rendu 404 sur la ligne d'un partenaire.
        $cible = $reversement->getAgent() ?? $reversement->getPartenaire();
        if ($cible === null
            || $reversement->getEntreprise()?->getId() !== $this->getInvite()?->getEntreprise()?->getId()) {
            throw $this->createNotFoundException('Reversement introuvable.');
        }

        return $this->ouverture(
            $cible instanceof Partenaire ? ProductionScope::TYPE_PARTENAIRE : ProductionScope::TYPE_AGENT,
            (int) $cible->getId(),
        );
    }

    /**
     * LES PIÈCES QUI JUSTIFIENT UNE AFFAIRE, depuis sa ligne du tableau.
     *
     * ── UN BOUTON QUI NE FAISAIT RIEN ───────────────────────────────────────────────
     * Le tableau annonce « 2 pièces » sur une affaire réglée, et ce compte est juste. Mais
     * le bouton n'avait aucune route à appeler : l'ancien écran la fabriquait depuis un
     * `beneficiaire.prefixe` qui n'a jamais existé, si bien que la page entière échouait au
     * rendu. Le service qui rassemble ces pièces, lui, était écrit et n'était appelé de
     * nulle part ({@see LotDeVersement::documentsPourAvenant()}).
     *
     * ── LA PREUVE EST CELLE DU VIREMENT, PAS DE LA LIGNE ────────────────────────────
     * Un bordereau couvre tout un lot : la pièce vit sur l'un de ses membres, pas
     * nécessairement celui qui porte cette affaire. C'est la règle du service, et elle
     * n'est pas recopiée ici.
     *
     * Le périmètre est celui de la rubrique — même garde que l'affichage des montants
     * ({@see self::beneficiaireDemande()}) : qui ne peut pas voir la production d'un
     * collègue ne peut pas davantage en lire les justificatifs.
     */
    #[Route(
        '/{famille}/{id}/affaire/{avenantId}/justificatifs',
        name: 'admin.productionintermediaire.justificatifs_affaire',
        requirements: ['famille' => 'agent|partenaire', 'id' => Requirement::DIGITS, 'avenantId' => Requirement::DIGITS],
        methods: ['GET'],
    )]
    public function justificatifsDeLAffaire(
        string $famille,
        int $id,
        int $avenantId,
        SoaPoliceDocumentsCollector $collector,
    ): Response {
        $cible = $this->beneficiaireDemande(
            ProductionScope::critereBeneficiaire($id, '', $famille),
        );
        if ($cible === null) {
            throw $this->createNotFoundException('Intermédiaire introuvable dans cet espace.');
        }

        $documents = $this->lotDeVersement->documentsPourAvenant(
            $cible,
            $cible->getEntreprise(),
            $avenantId,
        );
        $rubrique = $this->accessResolver->libellesEntites()['ReversementRetroAgent'] ?? 'Reversements';

        return $this->render('components/soa/_documents_picker.html.twig', [
            'titre'              => 'Justificatifs des versements sur cette affaire',
            'contexteNom'        => (string) $cible->getNom(),
            'items'              => $collector->decrire($documents, $rubrique),
            'downloadUrlPattern' => '/admin/document/api/%did%/download',
        ]);
    }
    /** Le corps commun des deux portes d'entrée : une cible, un critère. */
    private function ouverture(string $famille, int $id): JsonResponse
    {
        $connecte = $this->getInvite();
        $entreprise = $connecte?->getEntreprise();
        if ($entreprise === null) {
            throw $this->createAccessDeniedException('Aucun espace de travail actif.');
        }

        $cible = $famille === ProductionScope::TYPE_PARTENAIRE
            ? $this->em->getRepository(Partenaire::class)->findOneBy(['id' => $id, 'entreprise' => $entreprise])
            : $this->em->getRepository(Invite::class)->findOneBy(['id' => $id, 'entreprise' => $entreprise]);

        if ($cible === null) {
            throw $this->createNotFoundException('Intermédiaire introuvable dans cet espace.');
        }

        return $this->json([
            'entityName' => ProductionScope::ENTITE,
            'criteres' => ProductionScope::critereBeneficiaire($id, (string) $cible->getNom(), $famille),
        ]);
    }

    // ===================== Le corps commun =====================

    private function rendre(Request $request, bool $estRafraichissement): Response
    {
        // ⚠ LE DROIT DE LA RUBRIQUE N'EST PAS LE DROIT DE LA PRODUCTION.
        //
        // Le menu est gaté sur « Intermédiaires » — c'est leur production qu'on met en
        // scène. Mais un agent doit retrouver SA production depuis SON compte, et il n'a
        // pas ce droit-là : le lui refuser ici aurait fermé la rubrique à ceux qu'elle
        // concerne le plus, alors que l'ancien rapport la leur ouvrait.
        //
        // La garde grossière se borne donc à exiger un invité du cabinet ; ce sont les
        // gardes par BÉNÉFICIAIRE qui décident, une par famille — voir `beneficiaireDemande`.
        if ($this->getInvite() === null) {
            return $estRafraichissement
                ? $this->json(['error' => 'Accès restreint.'], Response::HTTP_FORBIDDEN)
                : $this->render('components/_access_denied.html.twig', ['entite' => 'Production intermédiaires']);
        }

        $criteres = $estRafraichissement
            ? (json_decode($request->getContent(), true)['criteria'] ?? [])
            : [];
        if (!is_array($criteres)) {
            $criteres = [];
        }

        $statut = ProductionScope::statutDemande($criteres);
        $contexte = $this->contexteDuRapport($criteres, $statut);

        if ($estRafraichissement) {
            return $this->json([
                // LE CORPS SEUL, comme `_list_content` pour les autres rubriques : le socle
                // remplace le contenu de sa cible `donnees`, pas la coquille qui l'entoure.
                // Lui renvoyer le gabarit entier aurait imbriqué une seconde coquille dans
                // la première à chaque recherche.
                'html' => $this->renderView('components/production/_corps.html.twig', $contexte),
                'numericAttributesAndValues' => $this->valeursNumeriques($contexte['data']),
                // AUCUNE PAGINATION. Le rapport d'un intermédiaire tient en quelques dizaines
                // de lignes, et son pied de totaux doit rester VRAI : paginer l'aurait rendu
                // partiel sans le dire.
                'pagination' => $this->pagination(count($contexte['data'])),
            ]);
        }

        return $this->render('components/_view_manager.html.twig', $contexte + [
            'entite_nom' => ProductionScope::ENTITE,
            'serverRootName' => 'productionintermediaire',
            'listeCanvas' => $this->canvasBuilder->getListeCanvas(ProductionScope::ENTITE),
            'entityCanvas' => $this->canvasBuilder->getEntityCanvas(ProductionScope::ENTITE),
            'entityFormCanvas' => $this->canevasDeFormulaire(),
            'searchCanvas' => $this->canvasBuilder->getSearchCanvas(ProductionScope::ENTITE),
            'numericAttributesAndValues' => $this->valeursNumeriques($contexte['data']) ?: new \stdClass(),
            'idInvite' => $request->attributes->get('idInvite'),
            'idEntreprise' => $request->attributes->get('idEntreprise'),
            'mainListUrl' => $this->generateUrl('admin.productionintermediaire.app_dynamic_query', [
                'idInvite' => $request->attributes->get('idInvite'),
                'idEntreprise' => $request->attributes->get('idEntreprise'),
            ]),
            'paginationMeta' => $this->pagination(count($contexte['data'])),
            'initialSearchCriteria' => [],
        ]);
    }

    /**
     * LE RAPPORT, OU L'ÉTAT D'ACCUEIL.
     *
     * Sans bénéficiaire désigné, la rubrique ne calcule RIEN et ne montre RIEN : la
     * production se calcule affaire par affaire, et la calculer pour tout le cabinet
     * d'emblée coûterait cher pour un écran que personne n'a demandé. L'écran invite alors
     * à choisir — c'est une réponse, pas un vide.
     *
     * @param array<string, mixed> $criteres
     *
     * @return array<string, mixed>
     */
    private function contexteDuRapport(array $criteres, string $statut): array
    {
        $vide = [
            'data' => [],
            'lignes' => [],
            'totaux' => RapportProductionAgentBuilder::totauxVides(),
            'colonnes' => [],
            'beneficiaire' => null,
            'projection' => false,
            'monnaie' => $this->serviceMonnaies->getCodeMonnaieAffichage(),
            'piecesParAvenant' => [],
            'statut' => $statut,
        ];

        $cible = $this->beneficiaireDemande($criteres);
        if ($cible === null) {
            return $vide;
        }

        $rapport = $this->rapportBuilder->pour(
            $this->beneficiaires->pour($cible),
            $cible->getEntreprise(),
            $statut,
        );

        // `data` EST la liste que la coquille manipule — sélection, comptage, totaux — et
        // `lignes` le nom que le gabarit du tableau connaît depuis toujours. Une seule
        // collection, deux clés : renommer dans le gabarit aurait touché vingt-trois
        // colonnes pour rien.
        return $rapport + [
            'data' => $rapport['lignes'],
            // LES PIÈCES, PAR AFFAIRE — pour les DEUX familles. Une affaire réglée sans
            // bordereau se voit ici et nulle part ailleurs ; passer un tableau vide
            // affichait « sans pièce » sur des versements pourtant justifiés. Sur un
            // écran dont c'est la question même, c'est un mensonge, pas un détail.
            'piecesParAvenant' => $this->lotDeVersement->comptesDePiecesParAvenant(
                $cible,
                $cible->getEntreprise(),
            ),
            'statut' => $statut,
        ];
    }

    /**
     * LE BÉNÉFICIAIRE DÉSIGNÉ PAR LES CHIPS, et le droit de le consulter.
     *
     * Fail-closed de bout en bout : une valeur illisible, un couple type/bénéficiaire
     * contradictoire, une cible hors du cabinet ou hors des droits, et la rubrique reste à
     * son état d'accueil plutôt que de montrer la production de quelqu'un d'autre.
     *
     * @param array<string, mixed> $criteres
     */
    private function beneficiaireDemande(array $criteres): Invite|Partenaire|null
    {
        $valeur = ProductionScope::valeurDe($criteres, ProductionScope::CLE_BENEFICIAIRE);
        $decode = ProductionScope::decoderBeneficiaire($valeur);
        if ($decode === null) {
            return null;
        }

        // UN COUPLE CONTRADICTOIRE NE MONTRE RIEN. « Type : Agent » avec un partenaire nommé
        // décrit un ensemble vide ; l'écran rend ce couple inatteignable, mais une URL
        // fabriquée ou l'assistant peuvent le former.
        $type = ProductionScope::valeurDe($criteres, ProductionScope::CLE_TYPE);
        if (!ProductionScope::beneficiaireCompatibleAvecType($type, $valeur)) {
            return null;
        }

        [$famille, $id] = $decode;
        $connecte = $this->getInvite();
        $entreprise = $connecte?->getEntreprise();
        if ($entreprise === null) {
            return null;
        }

        if ($famille === ProductionScope::TYPE_PARTENAIRE) {
            // LA PRODUCTION D'UN PARTENAIRE relève de la lecture des « Intermédiaires » —
            // la rubrique où il vit. C'est la même règle qu'ailleurs dans l'application, et
            // elle n'a pas de raison de se relâcher parce qu'on change d'écran.
            if (!$this->accessResolver->can($connecte, 'Partenaire', Invite::ACCESS_LECTURE)) {
                return null;
            }

            return $this->em->getRepository(Partenaire::class)
                ->findOneBy(['id' => $id, 'entreprise' => $entreprise]);
        }

        $agent = $this->em->getRepository(Invite::class)
            ->findOneBy(['id' => $id, 'entreprise' => $entreprise]);
        if ($agent === null) {
            return null;
        }

        // SOI-MÊME TOUJOURS, UN COLLÈGUE SEULEMENT SI GESTIONNAIRE. La règle est celle de
        // l'ancien rapport, et elle ne se relâche pas en changeant d'écran : la rémunération
        // d'un collègue n'est pas consultable.
        if ($agent->getId() === $connecte->getId() || $this->accessResolver->canManageInvites($connecte)) {
            return $agent;
        }

        return null;
    }

    /**
     * CE QUE LA BARRE DES TOTAUX ADDITIONNE.
     *
     * Les montants sont en CENTIMES — c'est le contrat de `list-summary`, partagé par les
     * trente-quatre rubriques. Les colonnes proposées sont celles du rapport, et rien
     * d'autre : la barre doit totaliser ce que l'écran montre, jamais un chiffre voisin.
     *
     * @param array<int, array<string, mixed>> $lignes
     *
     * @return array<int, array<string, array{description: string, value: float}>>
     */
    private function valeursNumeriques(array $lignes): array
    {
        $colonnes = [
            'prime' => 'Prime due',
            'primePayee' => 'Prime payée',
            'commissionTtc' => 'Commission TTC',
            'commissionEncaissee' => 'Commission encaissée',
            'due' => 'Rétrocommission due',
            'payee' => 'Rétrocommission payée',
            'solde' => 'Rétrocommission solde',
            'exigible' => 'Rétrocommission exigible',
        ];

        $valeurs = [];
        foreach ($lignes as $ligne) {
            $id = $this->identifiantDeLigne($ligne);
            if ($id === 0) {
                continue;
            }

            $attributs = [];
            foreach ($colonnes as $code => $intitule) {
                $attributs[$code] = [
                    'description' => $intitule,
                    'value' => round((float) ($ligne[$code] ?? 0.0), 2) * 100,
                ];
            }
            $valeurs[$id] = $attributs;
        }

        return $valeurs;
    }

    /**
     * L'IDENTIFIANT D'UNE LIGNE — le même que celui du gabarit, et il le faut : la barre
     * des totaux croise ses valeurs avec les cases cochées par leur identifiant.
     *
     * @param array<string, mixed> $ligne
     */
    private function identifiantDeLigne(array $ligne): int
    {
        $avenant = $ligne['avenant'] ?? null;
        $cotation = $ligne['cotation'] ?? null;

        return (int) ($avenant?->getId() ?? $cotation?->getId() ?? 0);
    }

    /**
     * UNE SEULE PAGE, TOUJOURS. Le pied du tableau totalise ce que l'écran montre : le
     * pagineur dirait « page 1 sur 3 » sur des totaux qui, eux, portent sur tout.
     *
     * @return array<string, int>
     */
    private function pagination(int $total): array
    {
        return [
            'currentPage' => 1,
            'totalPages' => 1,
            'totalItems' => $total,
            'itemsPerPage' => max(1, $total),
        ];
    }

    /**
     * LE CANEVAS DE FORMULAIRE D'UNE RUBRIQUE QUI NE SE SAISIT PAS.
     *
     * On ne produit pas une affaire depuis cet écran : elle naît d'une piste et d'une
     * proposition. La création est donc interdite, et la barre d'outils comme le menu
     * contextuel s'alignent sur ce seul drapeau — trois portes, une règle.
     *
     * @return array<string, mixed>
     */
    private function canevasDeFormulaire(): array
    {
        return [
            'parametres' => [
                'description' => 'Production des intermédiaires',
                'icone' => 'invite',
                'creation_interdite' => true,
                // LES DEUX COMMANDES DE L'ANCIEN ENTÊTE. Le rapport les portait en haut à
                // droite : verser au bénéficiaire affiché, et relire ce qu'on lui a déjà
                // versé. Elles deviennent des actions de la rubrique — barre d'outils ET
                // menu contextuel, les deux portes du socle les lisent ICI, sous
                // `parametres` : posées à la racine du canevas, elles étaient invisibles
                // des deux, sans erreur ni message.
                //
                // ⚠ ELLES PORTENT SUR LE BÉNÉFICIAIRE DU FILTRE, pas sur la ligne cochée :
                // on verse à quelqu'un, pas à une affaire. Le mécanisme d'actions ne sait
                // transporter que l'`%id%` d'une ligne ; c'est donc le cerveau qui lit le
                // chip « Bénéficiaire » de l'onglet — la même source que la liste affichée,
                // et jamais une seconde.
                //
                // `multi: true` les rend visibles dès UNE ligne cochée : elles ne portent
                // pas sur la sélection, mais c'est le seul moment où le socle les propose.
                'attribute_actions' => [
                    [
                        'label' => 'Signaler reversement',
                        'icon' => 'depense',
                        'multi' => true,
                        'event' => 'ui:production.reversement-request',
                        'url' => '',
                    ],
                    [
                        'label' => 'Voir reversements existants',
                        'icon' => 'classeur',
                        'multi' => true,
                        'event' => 'ui:production.versements-request',
                        'url' => '',
                    ],
                ],
            ],
            'form_layout' => [],
            'fields_map' => [],
        ];
    }
}
