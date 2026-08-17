<?php

/**
 * @file Ce fichier contient le contrôleur DocumentController.
 * @description Ce contrôleur est un CRUD complet pour l'entité `Document`.
 * Il est responsable de :
 * 1. `index()`: Afficher la vue principale de la liste des documents (page non-générique).
 * 2. Fournir des points de terminaison API pour :
 *    - `getFormApi()`: Obtenir le formulaire de création/édition.
 *    - `submitApi()`: Traiter la soumission du formulaire, en gérant l'association à diverses entités parentes
 *      (PieceSinistre, Tache, etc.) grâce au `HandleChildAssociationTrait`.
 *    - `deleteApi()`: Supprimer un document.
 *    - `downloadApi()`: Gérer le téléchargement du fichier associé à un document, en utilisant le `DownloadHandler` de VichUploader.
 *
 * Ce contrôleur est un bon exemple de gestion d'une entité "enfant" qui peut être liée à de nombreux
 * types d'entités "parentes" de manière dynamique.
 */

namespace App\Controller\Admin;

use App\Ai\Fichier\FichierAttachePolicy;
use App\Token\InsufficientTokensException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use App\Service\Soa\SoaPoliceDocumentsCollector;
use App\Ai\Tool\EntiteLibelle;
use App\Entity\Tache;
use App\Entity\Invite;
use App\Entity\Document;
use App\Entity\Feedback;
use App\Entity\Paiement;
use App\Entity\Entreprise;
use App\Form\DocumentType;
use App\Constantes\Constante;
use App\Entity\PieceSinistre;
use App\Constantes\MenuActivator;
use App\Repository\InviteRepository;
use App\Repository\DocumentRepository;
use App\Repository\EntrepriseRepository;
use Doctrine\ORM\EntityManagerInterface;
use App\Service\Document\ArchiveDeDocuments;
use App\Service\Document\DocumentFichier;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Services\JSBDynamicSearchService;
use App\Services\CanvasBuilder;
use App\Entity\OffreIndemnisationSinistre;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use App\Controller\Admin\ControllerUtilsTrait;
use App\Entity\Avenant;
use App\Entity\Bordereau;
use App\Entity\Classeur;
use App\Entity\Client;
use App\Entity\CompteBancaire;
use App\Entity\Cotation;
use App\Entity\Partenaire;
use App\Entity\Piste;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Vich\UploaderBundle\Handler\DownloadHandler;
use App\Entity\Traits\HandleChildAssociationTrait;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

#[Route("/admin/document", name: 'admin.document.')]
#[IsGranted('ROLE_USER')]
class DocumentController extends AbstractController
{
    use HandleChildAssociationTrait;
    use ControllerUtilsTrait;

    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private EntityManagerInterface $em,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private DocumentRepository $documentRepository,
        private Constante $constante,
        private JSBDynamicSearchService $searchService, // Ajoutez cette ligne
        private SerializerInterface $serializer,
        CanvasBuilder $canvasBuilder // Inject CanvasBuilder without property promotion
    ) {
        // Assign the injected CanvasBuilder to the property declared in the trait
        $this->canvasBuilder = $canvasBuilder;
    }

    /**
     * 3. IMPLÉMENTER LA "NOTICE D'INSTRUCTIONS" REQUISE PAR LE TRAIT
     * On déclare ici tous les parents possibles pour un Document.
     */
    protected function getParentAssociationMap(): array
    {
        // Utilise la méthode du trait pour construire dynamiquement la carte
        // en inspectant les relations ManyToOne de l'entité Document.
        return $this->buildParentAssociationMapFromEntity(Document::class);
    }

    protected function getCollectionMap(): array
    {
        return $this->buildCollectionMapFromEntity(Document::class);
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
        return $this->renderViewOrListComponent(Document::class, $request);
    }

    #[Route('/api/get-form/{id?}', name: 'api.get_form', methods: ['GET'])]
    public function getFormApi(?Document $document, Request $request): Response
    {
        return $this->renderFormCanvas(
            $request,
            Document::class,
            DocumentType::class,
            $document
            // No specific initializer needed for a new Document
        );
    }


    #[Route('/api/submit', name: 'api.submit', methods: ['POST'])]
    public function submitApi(Request $request): Response
    {
        return $this->handleFormSubmission(
            $request,
            Document::class,
            DocumentType::class
        );
    }


    #[Route('/api/delete/{id}', name: 'api.delete', methods: ['DELETE'])]
    public function deleteApi(Document $document): Response
    {
        return $this->handleDeleteApi($document);
    }

    /**
     * Télécharge le fichier d'un document.
     *
     * DEUX CORRECTIONS, ET ELLES COMPTENT TOUTES LES DEUX.
     *
     * 1. LE CONTRÔLE D'ACCÈS N'EXISTAIT PAS. La route se contentait du `ROLE_USER` de
     *    la classe : n'importe quel utilisateur connecté, de n'importe quelle
     *    entreprise, obtenait n'importe quel document en incrémentant l'identifiant
     *    dans l'URL. Le ParamConverter chargeait l'entité par sa clé primaire, sans
     *    jamais demander à qui elle appartenait. On repasse donc par le MÊME chemin que
     *    l'assistant : droit de lecture sur Document, et résolution scopée entreprise
     *    par le service de recherche. Un document d'ailleurs devient un 404 — la
     *    réponse qu'aurait donnée un id inexistant, et qui n'apprend donc rien.
     *
     * 2. LE FICHIER ARRIVAIT SOUS SON NOM DE STOCKAGE. `downloadObject()` sans quatrième
     *    argument sert le nom généré par SmartUniqueNamer : l'utilisateur recevait
     *    « contrat-a1b2c3d4.pdf » là où sa fiche affiche « Contrat KIN AVIA ». Le nom
     *    lisible vient maintenant de DocumentFichier, la même source que l'assistant :
     *    le fichier servi par la rubrique et celui servi par Ket sont identiques, nom
     *    compris.
     */
    #[Route('/api/{id}/download', name: 'api.download', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    public function downloadApi(
        int $id,
        DownloadHandler $downloadHandler,
        WorkspaceAccessResolver $accessResolver,
        DocumentFichier $documentFichier,
    ): Response {
        // getInvite() résout l'invité du WORKSPACE COURANT (connectedTo) et lève
        // lui-même si l'utilisateur n'en a aucun : le périmètre est celui de l'écran.
        $invite = $this->getInvite();
        $entreprise = $invite->getEntreprise();
        if (!$accessResolver->canRead($invite, 'Document')) {
            throw $this->createAccessDeniedException('Accès refusé.');
        }

        $result = $this->searchService->search(Document::class, ['id' => $id], $entreprise, null, 1, 1);
        $document = $result['data'][0] ?? null;
        if (($result['status']['code'] ?? 500) !== 200 || !$document instanceof Document || $document->getNomFichierStocke() === null) {
            throw $this->createNotFoundException('Document introuvable ou sans fichier.');
        }

        return $downloadHandler->downloadObject(
            $document,
            DocumentFichier::CHAMP_VICH,
            null,
            $documentFichier->nomDeTelechargement($document),
        );
    }

    /**
     * TÉLÉCHARGER LA SÉLECTION de la rubrique Documents — un fichier, ou une archive.
     *
     * UNE SEULE ROUTE POUR LES DEUX CAS, et c'est délibéré. L'interface ne sait pas
     * combien de pièces sont réellement téléchargeables : parmi les lignes cochées, l'une
     * peut avoir perdu son binaire, une autre appartenir à un autre cabinet et devoir
     * disparaître en silence. Laisser le navigateur choisir entre « le fichier » et
     * « l'archive » lui ferait prendre cette décision sur un décompte qu'il n'a pas. Le
     * serveur, lui, le connaît après résolution : une pièce → le fichier lui-même, sous
     * son nom lisible ; plusieurs → un ZIP.
     *
     * LE NOM DE L'ARCHIVE. La demande veut qu'il vaille « le libellé de l'objet Document ».
     * Or un Document ne porte qu'UN fichier (champ Vich unique) : plusieurs fichiers, ce
     * sont plusieurs Documents, et donc plusieurs libellés. On prend alors le libellé de
     * ce qu'ils ont en commun — leur classeur quand ils le partagent, ce qui est le cas
     * courant depuis que les pièces d'un client se rangent ensemble. À défaut,
     * « documents.zip ». Chaque fichier DANS l'archive garde, lui, le libellé de son
     * propre Document.
     *
     * FAIL-CLOSED, comme le téléchargement unitaire : droit de lecture sur Document, puis
     * re-résolution de CHAQUE identifiant dans l'entreprise de l'écran. Les identifiants
     * viennent du navigateur ; sans cela, il suffirait d'en écrire d'autres à la main.
     */
    #[Route('/api/telecharger', name: 'api.telecharger', methods: ['GET'])]
    public function telechargerApi(
        Request $request,
        DownloadHandler $downloadHandler,
        WorkspaceAccessResolver $accessResolver,
        DocumentFichier $documentFichier,
        ArchiveDeDocuments $archives,
    ): Response {
        $invite = $this->getInvite();
        $entreprise = $invite->getEntreprise();
        if (!$accessResolver->canRead($invite, 'Document')) {
            throw $this->createAccessDeniedException('Accès refusé.');
        }

        $ids = ArchiveDeDocuments::identifiants((string) $request->query->get('ids', ''));
        if ($ids === []) {
            throw $this->createNotFoundException('Aucun document demandé.');
        }
        if (\count($ids) > ArchiveDeDocuments::MAX_FICHIERS) {
            return new Response(
                sprintf('Trop de documents demandés (%d) : le maximum est de %d par archive.', \count($ids), ArchiveDeDocuments::MAX_FICHIERS),
                Response::HTTP_REQUEST_ENTITY_TOO_LARGE,
                ['Content-Type' => 'text/plain; charset=UTF-8'],
            );
        }

        $documents = $archives->documentsLisibles($ids, $entreprise);
        if ($documents === []) {
            throw $this->createNotFoundException('Aucun document téléchargeable.');
        }

        if (\count($documents) === 1) {
            return $downloadHandler->downloadObject(
                $documents[0],
                DocumentFichier::CHAMP_VICH,
                null,
                $documentFichier->nomDeTelechargement($documents[0]),
            );
        }

        return $archives->archiver($documents, $this->nomDeLArchive($documents));
    }

    /**
     * Ce que la sélection a en commun, pour nommer l'archive.
     *
     * Le classeur, et lui seul : c'est le rangement, donc précisément ce qui réunit des
     * pièces. Les rattacher par leur parent direct donnerait « Avenant » pour l'un et
     * « Client » pour l'autre alors qu'ils sont du même dossier. Dès que la sélection
     * franchit deux classeurs, elle n'a plus de nom propre et « documents » est la
     * réponse honnête.
     *
     * @param list<Document> $documents
     */
    private function nomDeLArchive(array $documents): string
    {
        $classeurs = [];
        foreach ($documents as $document) {
            $classeur = $document->getClasseur();
            if ($classeur === null) {
                return 'documents';
            }
            $classeurs[(int) $classeur->getId()] = (string) $classeur->getNom();
        }

        return \count($classeurs) === 1 ? reset($classeurs) : 'documents';
    }

    /**
     * ATTACHER DES PIÈCES À UNE FICHE, depuis la liste d'une rubrique.
     *
     * POURQUOI CE CIRCUIT EXISTE. Toute entité métier porte désormais une collection
     * « Documents », mais y déposer un fichier obligeait à ouvrir la fiche, gagner
     * l'onglet Documents, et créer les pièces une par une. Le geste naturel est
     * l'inverse : on désigne une ligne, on y dépose ses fichiers. C'est ce que servent
     * ces deux méthodes — le GET rend la boîte, le POST reçoit le lot.
     *
     * FAIL-CLOSED À TROIS VERROUS, et le premier est le moins évident : `{parent}` vient
     * de l'URL, donc de l'extérieur. On ne l'emploie JAMAIS comme nom de champ sans
     * l'avoir confronté à la carte des relations réelles de Document — sans quoi une URL
     * fabriquée à la main choisirait elle-même où atterrit la pièce. Viennent ensuite le
     * droit d'écriture sur Document, et le scoping entreprise de l'objet visé.
     */
    #[Route('/api/attacher/{parent}/{id}', name: 'api.attacher_form', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    public function attacherFormApi(
        string $parent,
        int $id,
        DocumentFichier $documentFichier,
        EntiteLibelle $libelleur,
        WorkspaceAccessResolver $accessResolver,
    ): Response {
        $cible = $this->cibleDAttachement($parent, $id, $documentFichier, Invite::ACCESS_ECRITURE);
        $shortName = $this->nomCourt($cible);

        return $this->render('components/document/_attach_picker.html.twig', [
            'parent'     => $parent,
            'cible'      => $cible,
            'libelle'    => $this->libelleDeLaCible($cible, $libelleur),
            'rubrique'   => $accessResolver->libellesEntites()[$shortName] ?? $shortName,
            'limites'    => FichierAttachePolicy::limitesFront(),
            // Extension => famille de format : la MÊME table que celle qui classe les
            // pièces déjà enregistrées, pour que l'icône du dépôt soit celle de la fiche.
            'famillesParExtension' => $this->famillesParExtension(),
            // Convention partagée avec les autres pickers : le fragment n'embarque son
            // contrôleur Stimulus que lorsqu'il vit seul (cf. _client_picker.html.twig).
            'standalone' => true,
        ]);
    }

    /**
     * Reçoit le lot `fichiers[]` et crée UN Document par fichier, rattaché à la fiche.
     *
     * LE LOT EST VALIDÉ AVANT TOUT DÉBIT, comme le fait l'upload du chat : un fichier
     * refusé est NOMMÉ dans la réponse, jamais écarté en silence — sans quoi
     * l'utilisateur croirait avoir versé cinq pièces là où trois sont arrivées. Les
     * fichiers valides du même lot passent quand même : refuser tout pour un intrus
     * ferait recommencer une manipulation déjà faite.
     *
     * LE MÉTRAGE EST LE MÊME QUE CELUI DU FORMULAIRE. `commitWrite()` débite le
     * propriétaire avant la persistance et lève quand le solde est épuisé (402, rien
     * n'est écrit). L'oublier ici aurait ouvert un chemin d'écriture gratuit à côté d'un
     * chemin payant — deux prix pour le même geste.
     */
    #[Route('/api/attacher/{parent}/{id}', name: 'api.attacher', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    public function attacherApi(string $parent, int $id, Request $request, DocumentFichier $documentFichier, EntiteLibelle $libelleur): Response
    {
        $cible = $this->cibleDAttachement($parent, $id, $documentFichier, Invite::ACCESS_ECRITURE);
        $invite = $this->getInvite();
        $entreprise = $invite->getEntreprise();

        $fichiers = $request->files->all()['fichiers'] ?? [];
        if (!\is_array($fichiers)) {
            $fichiers = [$fichiers];
        }
        $fichiers = array_values(array_filter($fichiers));
        if ($fichiers === []) {
            return $this->json(['error' => 'Aucun fichier reçu.'], Response::HTTP_BAD_REQUEST);
        }

        $setter = 'set' . ucfirst($documentFichier->parentsPossibles()[$this->nomCourt($cible)]);

        $crees = [];
        $refuses = [];
        foreach ($fichiers as $fichier) {
            $motif = $this->motifDeRefus($fichier);
            if ($motif !== null) {
                $refuses[] = ['nom' => $fichier->getClientOriginalName(), 'motif' => $motif];
                continue;
            }

            $document = (new Document())->setNom($fichier->getClientOriginalName());
            $document->setFichier($fichier);
            $document->{$setter}($cible);
            $document->setEntreprise($entreprise);
            $document->setInvite($invite);

            try {
                $this->workspaceMutationService->commitWrite($document, $entreprise, $this->getUser());
            } catch (InsufficientTokensException $e) {
                // Le solde s'épuise EN COURS DE LOT : on garde ce qui a déjà été débité
                // et enregistré, et on le dit. Tout annuler ferait perdre des pièces
                // pourtant payées ; se taire laisserait croire le lot complet.
                $this->em->flush();

                return $this->json([
                    'crees'    => $crees,
                    'refuses'  => $refuses,
                    'error'    => $e->getMessage(),
                ], Response::HTTP_PAYMENT_REQUIRED);
            }

            $this->em->persist($document);
            $crees[] = $fichier->getClientOriginalName();
        }

        $this->em->flush();

        return $this->json([
            'crees'   => $crees,
            'refuses' => $refuses,
            'cible'   => $this->libelleDeLaCible($cible, $libelleur),
        ]);
    }

    /**
     * Les pièces DE CETTE FICHE, pour relecture.
     *
     * Attacher sans pouvoir relire serait un aller sans retour : l'utilisateur verse un
     * fichier et n'a plus aucun endroit où le retrouver depuis la liste. Le gabarit est
     * celui du picker de documents du SOA, dont le contrôleur Stimulus est un socle nu —
     * seule la source des lignes change.
     */
    #[Route('/api/de/{parent}/{id}', name: 'api.liste_de', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    public function listeDeApi(
        string $parent,
        int $id,
        DocumentFichier $documentFichier,
        SoaPoliceDocumentsCollector $collector,
        WorkspaceAccessResolver $accessResolver,
        EntiteLibelle $libelleur,
    ): Response {
        $cible = $this->cibleDAttachement($parent, $id, $documentFichier, Invite::ACCESS_LECTURE);
        $shortName = $this->nomCourt($cible);
        $champ = $documentFichier->parentsPossibles()[$shortName];
        $libelle = $this->libelleDeLaCible($cible, $libelleur);
        $rubrique = $accessResolver->libellesEntites()[$shortName] ?? $shortName;

        return $this->render('components/soa/_documents_picker.html.twig', [
            'titre'              => sprintf('Documents de « %s »', $libelle),
            'contexteNom'        => $libelle,
            'items'              => $collector->decrire(
                $this->documentRepository->findBy([$champ => $cible], ['id' => 'DESC']),
                $rubrique,
            ),
            'downloadUrlPattern' => '/admin/document/api/%did%/download',
        ]);
    }

    /**
     * L'objet visé, résolu et re-vérifié — ou une 404/403 qui n'apprend rien.
     *
     * `$parent` vient de l'URL : il n'est un nom de champ qu'APRÈS confrontation à la
     * carte des relations de Document, jamais avant.
     */
    private function cibleDAttachement(string $parent, int $id, DocumentFichier $documentFichier, int $niveau): object
    {
        if (!$this->mayAccessEntity(Document::class, $niveau)) {
            throw $this->createAccessDeniedException('Accès refusé.');
        }

        $shortName = array_search($parent, $documentFichier->parentsPossibles(), true);
        if ($shortName === false) {
            throw $this->createNotFoundException('Rattachement inconnu.');
        }

        $cible = $this->em->find('App\\Entity\\' . $shortName, $id);
        if ($cible === null) {
            throw $this->createNotFoundException('Enregistrement introuvable.');
        }

        // Le cloisonnement par cabinet : sans lui, un identifiant dicté au hasard
        // rattacherait une pièce au dossier d'une autre entreprise.
        $entreprise = $this->getInvite()->getEntreprise();
        if (method_exists($cible, 'getEntreprise')
            && $cible->getEntreprise() !== null
            && $cible->getEntreprise()->getId() !== $entreprise?->getId()) {
            throw $this->createNotFoundException('Enregistrement introuvable.');
        }

        return $cible;
    }

    /**
     * La table extension => famille, retournée à plat pour le navigateur.
     *
     * Le serveur la tient par famille (une famille, ses extensions) ; le picker, lui,
     * part d'un nom de fichier et cherche sa famille. On retourne donc la table plutôt
     * que d'en écrire une seconde dans le JavaScript — deux tables du même classement
     * finiraient par ranger le même fichier dans deux cases.
     *
     * @return array<string, string>
     */
    private function famillesParExtension(): array
    {
        $plat = [];
        foreach (SoaPoliceDocumentsCollector::familles() as $famille => $extensions) {
            foreach ($extensions as $extension) {
                $plat[$extension] = $famille;
            }
        }

        return $plat;
    }

    /** Nom court de la classe RÉELLE (une entité chargée peut arriver en proxy). */
    private function nomCourt(object $entite): string
    {
        return (new \ReflectionClass($this->em->getClassMetadata($entite::class)->getName()))->getShortName();
    }

    /**
     * Ce que l'utilisateur lit en tête de la boîte : la fiche qu'il a désignée.
     *
     * On passe par EntiteLibelle, la source unique déjà employée par l'assistant et par
     * la résolution des références. Une liste de getters écrite ici aurait nommé un
     * Risque par son CODE (« RCA7 ») là où tout le reste de l'application l'appelle
     * « RC Automobile » — et l'utilisateur aurait douté d'avoir désigné la bonne ligne.
     */
    private function libelleDeLaCible(object $cible, EntiteLibelle $libelleur): string
    {
        $classe = $this->em->getClassMetadata($cible::class)->getName();
        $libelle = trim($libelleur->libelle($cible, $libelleur->displayField($classe)));

        return $libelle !== ''
            ? $libelle
            : $this->nomCourt($cible) . ' #' . (method_exists($cible, 'getId') ? $cible->getId() : '?');
    }

    /**
     * Pourquoi ce fichier est refusé, ou null s'il passe.
     *
     * On réutilise les bornes des pièces jointes du chat — mêmes formats, même plafond
     * de dix mégaoctets. Pas leur nombre maximal, en revanche : « cinq fichiers » est une
     * règle de CONVERSATION, pas une règle de dossier.
     */
    private function motifDeRefus(UploadedFile $fichier): ?string
    {
        if (!$fichier->isValid()) {
            return 'téléversement incomplet';
        }
        if ($fichier->getSize() > FichierAttachePolicy::MAX_SIZE_BYTES) {
            return sprintf('dépasse %s', DocumentFichier::tailleLisible(FichierAttachePolicy::MAX_SIZE_BYTES));
        }
        $extension = strtolower($fichier->getClientOriginalExtension());
        if (!\in_array($extension, FichierAttachePolicy::EXTENSIONS, true)) {
            return sprintf('format « %s » non accepté', $extension !== '' ? $extension : 'inconnu');
        }

        return null;
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
        return $this->renderViewOrListComponent(Document::class, $request, true);
    }
}
