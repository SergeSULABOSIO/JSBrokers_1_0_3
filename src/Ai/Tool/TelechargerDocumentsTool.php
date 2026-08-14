<?php

namespace App\Ai\Tool;

use App\Ai\Action\TypeAction;
use App\Ai\Document\ContexteDeDocument;
use App\Ai\Presentation\Colonnes;
use App\Ai\Resolution\CritereLieA;
use App\Ai\Scope\AiScope;
use App\Entity\Document;
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
 * CE QU'IL FAIT MAINTENANT. Il CHERCHE (par rattachement, par nom, ou par identifiants),
 * puis il rend trois choses de front :
 *
 *  1. une LIGNE par fichier — n°, nom, format, poids, date de mise en ligne,
 *     rattachement — avec sa déclaration de présentation, pour que le tableau numéroté
 *     soit le MÊME qu'il soit rendu par le modèle ou par le repli PHP ;
 *  2. le CONTEXTE complet de chaque fichier ({@see ContexteDeDocument}) : la fiche du
 *     document et celle de l'objet dont il provient, indicateurs calculés compris ;
 *  3. une DIRECTIVE d'interface qui fait apparaître les boutons — un par fichier, plus
 *     une archive ZIP dès qu'il y en a deux.
 *
 * POURQUOI DES BOUTONS ET PAS DES LIENS DANS LE TEXTE. Le chat n'affiche aucun lien :
 * son allowlist de sanitisation ne connaît ni la balise `a` ni l'attribut `href`. Un
 * lien écrit par le modèle serait donc dégradé en texte mort. Le téléchargement passe
 * par la directive d'interface, et par elle seule.
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
    ) {
    }

    public function name(): string
    {
        return 'telecharger_documents';
    }

    public function description(): string
    {
        return "Retrouve des DOCUMENTS enregistrés en base (entité Document : documents d'un avenant, "
            . "d'un client, d'une cotation, d'un sinistre, preuves de paiement…) et affiche à "
            . "l'utilisateur des boutons de téléchargement, avec pour chaque fichier son format, sa "
            . 'taille, sa date de mise en ligne et le dossier dont il provient. Cherche par '
            . 'rattachement (lieA), par nom, ou par identifiants. SANS AUCUN CRITÈRE, rend TOUS les '
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
            . 'besoin des identifiants. Moi seul rends le format, la taille, la date et un bouton de '
            . 'téléchargement ; ne dis JAMAIS que tu ne peux pas fournir de fichier ou de lien.';
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
        if ($ids !== []) {
            $trouves = $this->parIdentifiants(\array_slice($ids, 0, $limite), $scope);
            $total = count($trouves);
        } else {
            $criteria = $resolution->criteria;
            if ($nom !== '') {
                $criteria['nom'] = ['operator' => 'LIKE', 'value' => $nom, 'mode' => 'contains'];
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
            $pourUi[] = [
                'id'        => $ligne['id'],
                'nom'       => $ligne['fichier'],
                'format'    => $ligne['format'],
                'taille'    => $ligne['octets'],
                'chargeLe'  => $ligne['chargeLe'],
                'rattacheA' => $ligne['rattacheA'],
                'url'       => $this->urlGenerator->generate('admin.assistantia.api.document.download', [
                    'idEntreprise' => $scope->entreprise->getId(),
                    'idDocument'   => $ligne['id'],
                ]),
            ];

            $lignes[] = [
                'n°'        => ++$numero,
                'nom'       => $ligne['nom'],
                'format'    => $ligne['format'],
                'taille'    => $ligne['taille'],
                'chargeLe'  => $ligne['chargeLe'],
                'rattacheA' => $ligne['rattacheA'],
                'classeur'  => $ligne['classeur'],
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
            'presentation' => Colonnes::de([
                'n°'        => Colonnes::IDENTIFIANT,
                'nom'       => Colonnes::TEXTE,
                'format'    => Colonnes::TEXTE,
                'taille'    => Colonnes::TEXTE,
                'chargeLe'  => Colonnes::DATE,
                'rattacheA' => Colonnes::TEXTE,
                'classeur'  => Colonnes::TEXTE,
            ], []),
            'contexte' => $contextes,
            'note'     => count($pourUi) === 1
                ? 'UN seul fichier : présente-le en une phrase (nom, format, taille, dossier d\'origine), '
                    . 'sans tableau. Un bouton de téléchargement sécurisé est affiché sous ta réponse — '
                    . 'invite l\'utilisateur à cliquer.'
                : sprintf(
                    '%d fichiers : présente-les dans un tableau NUMÉROTÉ reprenant exactement les colonnes '
                    . 'de « fichiers ». Un bouton de téléchargement par ligne et un bouton « Tout télécharger » '
                    . 'sont affichés sous ta réponse — invite l\'utilisateur à cliquer.',
                    count($pourUi),
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
