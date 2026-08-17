<?php

namespace App\Ai\Tool;

use App\Ai\Action\TypeAction;
use App\Ai\Document\ContexteDeDocument;
use App\Ai\Resolution\CritereLieA;
use App\Ai\Scope\AiScope;
use App\Entity\Document;
use App\Service\Document\DescenteDesDocuments;
use Doctrine\ORM\EntityManagerInterface;
use App\Service\Document\DocumentFichier;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Services\JSBDynamicSearchService;
use App\Services\Search\PortefeuilleCritereFactory;
use App\Services\Search\PortefeuilleScope;
use App\Token\TokenAccountService;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * RETROUVER un document de la base, et le REMETTRE avec son contexte.
 *
 * CE QUI MANQUAIT. Cet outil savait déjà afficher des boutons de téléchargement — à
 * condition qu'on lui DONNE les identifiants, et il ne restituait du document que son
 * libellé. Autrement dit : il fallait déjà savoir ce qu'on cherchait, et on recevait un
 * fichier sans savoir d'où il sortait. « Donne-moi les documents de la police KIN AVIA »
 * réclamait donc un tour d'outil préalable que l'architecture n'accorde pas, et se
 * soldait le plus souvent par un « je ne peux pas fournir de lien » — le refus que la
 * description de cet outil interdit pourtant explicitement.
 *
 * CE QU'IL FAIT MAINTENANT. Il CHERCHE (par rattachement — et alors il DESCEND tout le
 * dossier —, par nom, ou par identifiants), puis il rend trois choses de front :
 *
 *  1. une DIRECTIVE d'interface qui dessine LE tableau — n°, nom, format, taille, niveau
 *     — avec un bouton de téléchargement par ligne, plus une archive dès deux fichiers ;
 *  2. les mêmes lignes pour le MODÈLE, non pour qu'il les recopie mais pour qu'il sache
 *     ce qui a été trouvé et puisse en citer une dans sa phrase ;
 *  3. le CONTEXTE complet de chaque fichier ({@see ContexteDeDocument}) : la fiche du
 *     document et celle de l'objet dont il provient, indicateurs calculés compris.
 *
 * UN SEUL TABLEAU, ET C'EST CELUI DE L'INTERFACE. Le chat n'affiche aucun lien : son
 * allowlist de sanitisation ne connaît ni la balise `a` ni l'attribut `href`, et un lien
 * écrit par le modèle serait dégradé en texte mort. Seul un panneau peut donc porter un
 * bouton — d'où la directive. Et puisque ce panneau montre déjà tout, demander au modèle
 * d'écrire le même tableau en markdown le faisait afficher DEUX fois : la note lui
 * demande une phrase, et l'outil ne déclare volontairement aucune `presentation`.
 *
 * FAIL-CLOSED, à trois verrous : droit de lecture sur Document, scoping entreprise par
 * le service de recherche, et re-vérification complète au CLIC par la route de
 * téléchargement. L'outil ne diffuse aucun binaire : il ne fait que surfacer une URL
 * qui se défendra elle-même.
 */
final class TelechargerDocumentsTool implements AiToolInterface
{
    /** Au-delà, la liste cesse d'être lisible et l'archive cesse d'être raisonnable. */
    private const LIMITE_DEFAUT = 20;
    private const LIMITE_MAX = 50;

    public function __construct(
        private readonly WorkspaceAccessResolver $accessResolver,
        private readonly JSBDynamicSearchService $searchService,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly CritereLieA $critereLieA,
        private readonly ContexteDeDocument $contexte,
        private readonly DocumentFichier $documentFichier,
        private readonly TokenAccountService $tokenAccountService,
        // Fabrique PARTAGÉE avec le contrôleur de liste : le périmètre que Ket applique
        // est celui que la rubrique affiche, au critère près.
        private readonly PortefeuilleCritereFactory $portefeuilleCritere,
        // LE MOTEUR : tout ce qui pend sous une fiche, quelle que soit la profondeur.
        private readonly DescenteDesDocuments $descente,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function name(): string
    {
        return 'telecharger_documents';
    }

    public function description(): string
    {
        return "LE moteur de recherche de FICHIERS, à tous les niveaux du dossier. Donne-lui une fiche "
            . "(lieA) et il rend TOUT ce qui pend en dessous, aussi profond que ça aille : les fichiers "
            . "d'un CLIENT, ce sont les siens PLUS ceux de ses pistes, de leurs cotations et de leurs "
            . "polices ; ceux d'une PISTE partent de la piste et descendent ; ceux d'une COTATION "
            . "partent de la cotation. Ne cherche donc JAMAIS niveau par niveau et ne pose pas de "
            . "question de profondeur : un seul appel suffit, le serveur descend tout seul. Chaque "
            . "ligne porte son NIVEAU — la rubrique d'où sort le fichier. Cherche aussi par "
            . 'nom, ou par identifiants. SANS AUCUN CRITÈRE, rend TOUS les '
            . "fichiers du portefeuille de l'utilisateur — c'est la bonne réponse à « la liste / le "
            . 'tableau des fichiers de mon portefeuille », « tous mes documents ». À utiliser dès que '
            . "l'utilisateur veut voir, lister, télécharger, récupérer ou consulter des FICHIERS "
            . '(par opposition à des données) : cet outil est le seul qui rende le format, la taille, '
            . "la date de mise en ligne et un bouton de téléchargement. N'utilise PAS rechercher_entites "
            . "pour une demande de fichiers — il ne rend qu'une liste, sans téléchargement possible. "
            . 'Différent de telecharger_fichiers, qui ne concerne QUE les pièces jointes de la '
            . 'conversation en cours. Ne prétends JAMAIS ne pas pouvoir fournir de téléchargement.';
    }

    public function aiguillage(): string
    {
        return 'TOUTE demande portant sur des FICHIERS : « la liste / le tableau des fichiers de mon '
            . 'portefeuille », « tous mes documents », « télécharge / récupère / donne-moi le(s) document(s) » '
            . 'd\'une police, d\'un client, d\'une cotation, d\'un sinistre. Appelle-moi SANS argument pour tout '
            . 'le portefeuille, ou donne-moi le rattachement (lieA={entite:"Avenant", nom:"…"}) — tu n\'as pas '
            . 'besoin des identifiants. JE DESCENDS TOUT LE DOSSIER : « les fichiers du client X » me suffit '
            . 'pour rendre aussi ceux de ses pistes, cotations et polices — n\'enchaîne pas plusieurs appels '
            . 'pour explorer les niveaux, et ne demande pas à l\'utilisateur jusqu\'où chercher. Moi seul rends '
            . 'le format, la taille, le niveau et un bouton de téléchargement ; ne dis JAMAIS que tu ne peux '
            . 'pas fournir de fichier ou de lien.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'lieA' => [
                    'type' => 'object',
                    'description' => "Restreint aux documents rattachés à une fiche précise. Ex. les documents "
                        . 'de la police KIN AVIA → lieA={entite: "Avenant", nom: "KIN AVIA"} ; les documents du '
                        . 'client Kibali → lieA={entite: "Client", nom: "Kibali"}. Donne "id" si tu le connais, '
                        . 'sinon "nom" — le serveur résout le nom lui-même.',
                    'properties' => [
                        'entite' => ['type' => 'string', 'description' => "Nom court de l'entité de rattachement (Avenant, Client, Cotation, NotificationSinistre…)."],
                        'id'     => ['type' => 'integer', 'description' => "Identifiant de la fiche de rattachement."],
                        'nom'    => ['type' => 'string', 'description' => "Nom de la fiche de rattachement, si l'id est inconnu."],
                    ],
                ],
                'nom' => [
                    'type' => 'string',
                    'description' => 'Filtre sur le nom du document (correspondance partielle).',
                ],
                'ids' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer', 'minimum' => 1],
                    'description' => 'Identifiants précis de Documents, quand tu les connais déjà.',
                ],
                'limite' => [
                    'type' => 'integer',
                    'description' => 'Nombre maximum de documents à proposer (défaut ' . self::LIMITE_DEFAUT
                        . ', maximum ' . self::LIMITE_MAX . ').',
                ],
                'perimetre' => PortefeuilleScope::proprieteSchema(),
            ],
        ];
    }

    /**
     * Chemin simulé : verbes de téléchargement + mention d'un document. Sans critère
     * extractible, on renvoie un appel SANS argument — l'outil listera alors les
     * documents du périmètre, ce qui vaut mieux qu'une non-réponse.
     *
     * La résolution d'un rattachement dicté n'est PAS simulée : elle demande une
     * compréhension que les mots-clés n'ont pas.
     */
    public function match(string $question, AiScope $scope): ?array
    {
        $normalized = \App\Ai\AiText::normalize($question);

        if (!preg_match('/\b(telecharge[rz]?|telechargement|recupere[rz]?|download|obtenir|consulter)\b/', $normalized)) {
            return null;
        }
        if (!preg_match('/\b(document[s]?|piece[s]? jointe[s]?|fichier[s]?)\b/', $normalized)) {
            return null;
        }
        // Les pièces jointes de la CONVERSATION ont leur propre outil : ne pas le doubler.
        if (preg_match('/\b(piece[s]? jointe[s]?|joint[s]?)\b/', $normalized)) {
            return null;
        }

        return [];
    }

    public function execute(array $args, AiScope $scope): AiToolResult
    {
        // FAIL-CLOSED : lecture requise sur Document (même contrat que la rubrique).
        if (!$this->accessResolver->canRead($scope->invite, 'Document')) {
            return AiToolResult::horsPerimetre('Documents');
        }

        $limite = (int) ($args['limite'] ?? self::LIMITE_DEFAUT);
        $limite = max(1, min(self::LIMITE_MAX, $limite > 0 ? $limite : self::LIMITE_DEFAUT));

        $ids = array_values(array_unique(array_filter(
            array_map('intval', (array) ($args['ids'] ?? [])),
            static fn (int $id) => $id > 0,
        )));
        $nom = trim((string) ($args['nom'] ?? ''));

        // Rattachement : même résolution que rechercher_entites, au mot près.
        $resolution = $this->critereLieA->resoudre($args['lieA'] ?? null, Document::class, $scope);
        if ($resolution->estRefus()) {
            return $resolution->refus;
        }

        // PÉRIMÈTRE : par défaut celui de l'écran — le portefeuille de l'invité —, par la
        // fabrique PARTAGÉE avec le contrôleur de liste, donc le même critère, le même SQL
        // et les mêmes enregistrements que la rubrique Documents. Élargi seulement sur
        // demande explicite.
        //
        // C'est ce qui rend « donne-moi les fichiers de tout mon portefeuille » légitime
        // et complet, même sans autre critère : la demande ne déverse pas l'entreprise
        // entière, elle rend le périmètre de celui qui la formule.
        //
        // DÉLIBÉRÉMENT HORS du mode par identifiants : demander un document par son
        // identifiant, c'est déjà l'avoir vu quelque part. Lui appliquer en plus le filtre
        // de portefeuille produirait un « introuvable » incompréhensible sur une pièce que
        // l'utilisateur a sous les yeux. Le scoping ENTREPRISE, lui, s'applique toujours.
        $perimetreEntreprise = PortefeuilleScope::estEntreprise($args['perimetre'] ?? null);

        // DEUX MODES, jamais mélangés. Donner des identifiants, c'est déjà savoir
        // exactement ce qu'on veut : le rattachement et le nom n'y ajouteraient rien
        // qu'une occasion de se contredire. Les combiner n'a aucun usage réel.
        // LE DOSSIER, ET NON LA SEULE LIGNE. « Les fichiers du client Jean de Dieu » ne
        // désigne pas les pièces posées sur la ligne « client » : cela désigne tout ce
        // qui pend sous lui — ses pistes, leurs cotations, leurs polices. Filtrer
        // Document par un critère de rattachement ne rendait que le premier niveau, et
        // l'assistant répondait « un seul fichier » à qui savait qu'il y en avait
        // d'autres plus bas. On DESCEND donc depuis la fiche nommée.
        //
        // Les identifiants explicites gardent leur chemin : demander un document par son
        // id, c'est déjà l'avoir vu — il n'y a pas de dossier à explorer.
        $descente = null;
        $niveaux = [];
        if ($ids !== []) {
            $trouves = $this->parIdentifiants(\array_slice($ids, 0, $limite), $scope);
            $total = count($trouves);
        } elseif ($resolution->lien !== null && ($racine = $this->racineDuDossier($resolution->lien, $scope)) !== null) {
            $descente = $this->descente->depuis($racine, $this->accessResolver->libellesEntites());
            $total = count($descente['documents']);
            $trouves = array_map(
                static fn (array $t) => $t['document'],
                \array_slice($descente['documents'], 0, $limite),
            );
            // Le niveau voyage à côté du document : la ligne du tableau le réclame, et
            // c'est la première chose que cherche quelqu'un qui retrouve un fichier
            // dans une liste — d'où il sort.
            $niveaux = [];
            foreach ($descente['documents'] as $t) {
                $niveaux[(int) $t['document']->getId()] = $t['niveau'];
            }
        } else {
            $criteria = $resolution->criteria;
            if ($nom !== '') {
                // DEUX NOMS, ET L'UTILISATEUR NE DISTINGUE PAS LES DEUX. « Retrouve-moi
                // CONTRAT-2026 » désigne le plus souvent le nom du FICHIER tel qu'il
                // était sur le poste, pas le libellé saisi dans la fiche du document.
                // Ne chercher que le libellé rendait introuvable un fichier pourtant
                // présent, et poussait à conclure qu'il n'avait jamais été versé.
                // Le nom de stockage préserve le nom d'origine (SmartUniqueNamer n'y
                // ajoute qu'un suffixe), donc la correspondance partielle y fonctionne.
                $criteria[JSBDynamicSearchService::OU_TEXTE_LIBRE] = [
                    'champs' => ['nom', 'nomFichierStocke'],
                    'valeur' => $nom,
                ];
            }
            if (!$perimetreEntreprise) {
                $criteria += $this->portefeuilleCritere->pour('Document', $scope->invite);
            }

            // Scoping entreprise : assuré par le service de recherche, jamais par le prompt.
            $result = $this->searchService->search(Document::class, $criteria, $scope->entreprise, null, 1, $limite);
            if (($result['status']['code'] ?? 500) !== 200) {
                return AiToolResult::introuvable('documents');
            }
            $trouves = $result['data'];
            $total = (int) ($result['totalItems'] ?? count($trouves));
        }

        $lignes = [];
        $pourUi = [];
        $contextes = [];
        $sansFichier = 0;
        $numero = 0;

        foreach ($trouves as $document) {
            if (!$document instanceof Document || $document->getId() === null) {
                continue;
            }
            // Une ligne en base sans binaire sur le disque n'est pas téléchargeable :
            // proposer le bouton produirait un 404 au clic, ce qui est pire que rien.
            if (!$this->documentFichier->existe($document)) {
                ++$sansFichier;
                continue;
            }

            $ligne = $this->contexte->ligne($document);
            // Le NIVEAU : la rubrique de l'enregistrement qui détient la pièce. Il vient
            // de la descente quand il y en a eu une ; sinon on retombe sur le
            // rattachement direct, qui dit la même chose pour une recherche à plat.
            $niveau = $niveaux[$ligne['id']] ?? $ligne['rattacheA'];

            $pourUi[] = [
                'id'        => $ligne['id'],
                'nom'       => $ligne['fichier'],
                'format'    => $ligne['format'],
                'taille'    => $ligne['octets'],
                'chargeLe'  => $ligne['chargeLe'],
                'niveau'    => $niveau,
                'rattacheA' => $ligne['rattacheA'],
                'url'       => $this->urlGenerator->generate('admin.assistantia.api.document.download', [
                    'idEntreprise' => $scope->entreprise->getId(),
                    'idDocument'   => $ligne['id'],
                ]),
            ];

            // LES MÊMES LIGNES POUR LE MODÈLE — pour qu'il SACHE, pas pour qu'il les
            // recopie. Le tableau visible est celui du panneau ci-dessus, qui seul peut
            // porter un bouton : le chat n'affiche aucun lien (son allowlist de
            // sanitisation ne connaît ni `a` ni `href`), et un lien écrit dans un tableau
            // markdown serait du texte mort. Ket a besoin de ces lignes pour compter les
            // fichiers et citer un nom dans sa phrase ; la « note » lui interdit d'en
            // refaire le tableau, qui s'afficherait alors deux fois.
            $lignes[] = [
                'n°'     => ++$numero,
                'nom'    => $ligne['nom'],
                'format' => $ligne['format'],
                'taille' => $ligne['taille'],
                'niveau' => $niveau,
            ];

            $contextes[] = $this->contexte->complet($document);
        }

        if ($pourUi === []) {
            return AiToolResult::introuvable(
                $sansFichier > 0
                    ? sprintf('%d document(s) trouvé(s), mais aucun ne porte de fichier téléchargeable', $sansFichier)
                    : 'aucun document ne correspond',
                'Dis-le simplement, et propose d\'élargir la recherche (autre dossier, autre nom). '
                . 'N\'annonce AUCUN bouton de téléchargement : il n\'y en a pas.',
            );
        }

        // Une lecture d'entités se mètre, comme partout ailleurs.
        $this->tokenAccountService->meterRead(
            Document::class,
            count($pourUi),
            $scope->entreprise,
            $scope->invite->getUtilisateur(),
        );

        $uiAction = ['type' => TypeAction::TELECHARGER_FICHIERS->value, 'fichiers' => $pourUi];

        // L'archive n'a de sens qu'à partir de deux fichiers : proposer « tout
        // télécharger » pour un seul fichier ajouterait un clic et un dossier à ouvrir.
        if (count($pourUi) > 1) {
            $uiAction['zipUrl'] = $this->urlGenerator->generate('admin.assistantia.api.documents.zip', [
                'idEntreprise' => $scope->entreprise->getId(),
                'ids'          => implode(',', array_column($pourUi, 'id')),
            ]);
        }

        $data = [
            'fichiers' => $lignes,
            // Les rôles de colonne, déclarés UNE fois : le modèle et le repli PHP
            // rendent alors le même tableau numéroté. Aucun montant ici — rien à
            // totaliser, et un total de tailles de fichiers n'apprendrait rien.
            // PAS DE DÉCLARATION `presentation` ICI, ET C'EST VOULU. Elle sert à guider le
            // MODÈLE quand c'est lui qui écrit le tableau en markdown. Or le tableau des
            // fichiers est dessiné par l'interface — seul un panneau peut porter un bouton
            // de téléchargement, l'allowlist du chat ne connaissant ni `a` ni `href`. La
            // laisser ici obligerait le modèle, par la règle des colonnes déclarées, à
            // réécrire en prose le tableau qui s'affiche juste en dessous : c'est
            // exactement le doublon qu'on supprime.
            'contexte' => $contextes,
            // UNE PHRASE, PAS UN TABLEAU. Cette note ordonnait au modèle d'écrire un
            // tableau numéroté — que l'interface redessinait ensuite, à l'identique, avec
            // ses boutons. L'utilisateur recevait donc DEUX fois la même liste pour une
            // seule réponse, et le fil se remplissait deux fois plus vite. Le tableau qui
            // reste est celui qui sait porter un bouton ; la prose cède la place.
            'note'     => sprintf(
                '%d fichier(s) trouvé(s) dans TOUT le dossier, niveaux inférieurs compris. '
                . 'N\'ÉCRIS AUCUN TABLEAU et ne liste pas les fichiers un par un : l\'interface '
                . 'affiche déjà, sous ta réponse, le tableau complet (n°, nom, format, taille, '
                . 'niveau) avec un bouton de téléchargement par ligne%s. Écris UNE phrase de '
                . 'synthèse — combien de fichiers, pour quel dossier, et jusqu\'où la recherche est '
                . 'descendue (« du client jusqu\'à sa police ») — puis invite l\'utilisateur à '
                . 'cliquer. Tu peux citer un nom de fichier si la question portait sur lui. '
                . 'N\'écris aucun lien : il serait effacé à l\'affichage.',
                count($pourUi),
                count($pourUi) > 1 ? ', plus un bouton « Tout télécharger »' : '',
            ),
        ];

        // DIRE LE PÉRIMÈTRE, toujours. « 6 fichiers » et « 6 fichiers de VOTRE
        // portefeuille » ne veulent pas dire la même chose à un invité qui en gère un
        // parmi plusieurs, et c'est au serveur de trancher — pas au modèle de deviner.
        $data['perimetre'] = $perimetreEntreprise
            ? PortefeuilleScope::LIBELLE_ENTREPRISE
            : PortefeuilleScope::PERIMETRES[PortefeuilleScope::PERIMETRE_PORTEFEUILLE];

        if ($resolution->ignore) {
            $data['lienIgnore'] = 'Le rattachement demandé n\'a pas pu être appliqué : ces documents ne sont '
                . 'PAS restreints au dossier évoqué. Dis-le avant de les présenter.';
        }
        if ($sansFichier > 0) {
            $data['sansFichier'] = sprintf(
                '%d document(s) trouvé(s) ne portent aucun fichier sur le serveur et ne sont donc pas proposés.',
                $sansFichier,
            );
        }
        if ($total > count($trouves)) {
            $data['tronque'] = sprintf(
                '%d documents correspondent, seuls les %d premiers sont proposés.',
                $total,
                count($trouves),
            );
        }

        return AiToolResult::ok($data, uiAction: $uiAction);
    }

    /**
     * Résolution par identifiants, un par un.
     *
     * UN PAR UN, ET C'EST VOULU. Le service de recherche partagé n'autorise pas
     * l'opérateur `IN` (cf. JSBDynamicSearchService::$allowedOperators), et élargir
     * l'allowlist d'un moteur que TOUTE l'application traverse, pour le confort d'un
     * seul outil, coûterait bien plus cher que ces quelques requêtes : le nombre
     * d'identifiants est plafonné, et ce chemin n'est emprunté que lorsque le modèle
     * les connaît déjà — c'est-à-dire rarement.
     *
     * Chaque identifiant repasse par le scoping entreprise : un id venu du modèle est
     * une donnée d'entrée comme une autre, jamais une autorisation.
     *
     * @param list<int> $ids
     *
     * @return list<Document>
     */
    /**
     * L'enregistrement d'où PART la descente, re-résolu et re-scopé.
     *
     * `CritereLieA` a déjà vérifié le droit de lecture sur la rubrique et résolu le nom
     * dicté en identifiant. Il reste à charger l'objet — et à revérifier son cabinet :
     * un identifiant résolu reste une donnée d'entrée, jamais une autorisation.
     *
     * null quand la racine ne peut pas être chargée : l'appelant retombe alors sur la
     * recherche à plat, qui reste juste — simplement moins profonde.
     *
     * @param array{entite: string, id: int} $lien
     */
    private function racineDuDossier(array $lien, AiScope $scope): ?object
    {
        $fqcn = 'App\\Entity\\' . ($lien['entite'] ?? '');
        if (!class_exists($fqcn)) {
            return null;
        }

        try {
            $racine = $this->em->find($fqcn, (int) ($lien['id'] ?? 0));
        } catch (\Throwable) {
            return null;
        }
        if ($racine === null) {
            return null;
        }
        if (method_exists($racine, 'getEntreprise')
            && $racine->getEntreprise() !== null
            && $racine->getEntreprise()->getId() !== $scope->entreprise->getId()) {
            return null;
        }

        return $racine;
    }

    private function parIdentifiants(array $ids, AiScope $scope): array
    {
        $trouves = [];
        foreach ($ids as $id) {
            $result = $this->searchService->search(Document::class, ['id' => $id], $scope->entreprise, null, 1, 1);
            if (($result['status']['code'] ?? 500) !== 200) {
                continue;
            }
            $document = $result['data'][0] ?? null;
            if ($document instanceof Document) {
                $trouves[] = $document;
            }
        }

        return $trouves;
    }
}
