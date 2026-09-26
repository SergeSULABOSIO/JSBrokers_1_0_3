<?php

namespace App\Ai\Tool;

use App\Ai\AiText;
use App\Ai\Presentation\ColonnesDeLEcran;
use App\Ai\Resolution\CheminsDeRelation;
use App\Ai\Resolution\CritereLieA;
use App\Ai\Resolution\Reference;
use App\Ai\Resolution\ResolveurDeReferences;
use App\Ai\Scope\AiScope;
use App\Ai\Trousse\AiToolDeComprehension;
use App\Entity\Avenant;
use App\Entity\Cotation;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Services\AvenantRenouvellementResolver;
use App\Services\Canvas\Indicator\IndicatorCalculationHelper;
use App\Services\JSBDynamicSearchService;
use App\Services\Search\AvenantEcheanceScope;
use App\Services\Search\CotationSouscriptionScope;
use App\Services\Search\PisteTransformationScope;
use App\Services\Search\PortefeuilleCritereFactory;
use App\Services\Search\PortefeuilleScope;
use App\Services\Search\TranchePaiementScope;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Liste (ou recherche par texte) les enregistrements d'une rubrique du
 * workspace pour l'entreprise active, avec pagination. Complément naturel de
 * CompterEntitesTool : là où celui-ci répond « combien », celui-ci répond
 * « lesquels ». Peut se RESTREINDRE aux enregistrements liés à une fiche
 * précise (paramètre lieA — ex. les tâches d'une piste, les avenants d'un
 * client), à PLUSIEURS niveaux de relation : le plus court chemin de
 * relations Doctrine entre les deux entités est détecté par métadonnées
 * (BFS), générique pour tout couple d'entités du workspace. Recherche
 * déléguée à JSBDynamicSearchService (scoping entreprise systématique).
 *
 * CHAQUE LIGNE PORTE LES COLONNES DE L'ÉCRAN (cf. ColonnesDeLEcran) : celles que
 * la RUBRIQUE affiche pour cette entité, valeurs calculées comprises, avec leur
 * rôle de présentation. La restitution était auparavant réduite à « id + libellé »
 * pour maîtriser les tokens, et cette économie coûtait cher : prié d'ajouter « une
 * colonne pour le taux », le modèle n'avait aucune donnée, affichait 0 % pour dix
 * partenaires dont les parts valaient 30, 45 et 50 %, puis fabriquait une
 * explication à l'écart. Le juste milieu n'est ni le libellé seul ni la fiche
 * complète (6 000 à 18 000 tokens pour une page) : ce sont les colonnes que le
 * métier a déjà choisies pour l'écran.
 */
final class RechercherEntitesTool implements AiToolInterface, AiToolDeComprehension
{
    /** Taille de page fixe côté serveur : maîtrise des tokens restitués au modèle. */
    private const PAGE_SIZE = 20;

    /** Le mode COMPTE, tel que le schéma l'expose. */
    public const MODE_COMPTE = 'compte';
    /** Le mode LISTE — celui par défaut, et le seul qu'on avait avant la fusion. */
    public const MODE_LISTE = 'liste';


    public function __construct(
        private readonly WorkspaceAccessResolver $accessResolver,
        private readonly JSBDynamicSearchService $searchService,
        private readonly EntiteLexique $lexique,
        private readonly EntiteLibelle $libelleur,
        private readonly EntityManagerInterface $em,
        private readonly PortefeuilleCritereFactory $portefeuilleCritere,
        private readonly IndicatorCalculationHelper $indicatorHelper,
        private readonly AvenantRenouvellementResolver $renouvellementResolver,
        // Source unique « un nom dicté → un identifiant » : c'est elle qui permet à
        // cet outil de comprendre « les polices de Kibali » sans réclamer un second
        // tour au modèle (cf. rattachementDepuisNom).
        private readonly ResolveurDeReferences $resolveur,
        // Source unique du graphe de relations, partagée avec ouvrir_rubrique : la
        // liste affichée à l'écran et celle rendue au chat suivent le MÊME chemin.
        private readonly CheminsDeRelation $chemins,
        // Source unique de la traduction « lieA » → critère de recherche, partagée
        // avec telecharger_documents (cf. CritereLieA).
        private readonly CritereLieA $critereLieA,
        // Les colonnes que la RUBRIQUE affiche pour cette entité, préchargées en lot :
        // la même source, le même coût qu'un écran de liste.
        private readonly ColonnesDeLEcran $colonnesDeLEcran,
    ) {
    }

    public function name(): string
    {
        return 'rechercher_entites';
    }

    public function description(): string
    {
        return "Liste, recherche ou COMPTE les enregistrements d'une catégorie de données de "
            . "l'entreprise (clients, avenants, pistes, notes, sinistres…), avec filtre texte "
            . 'optionnel et pagination (' . self::PAGE_SIZE . ' par page). À appeler quand '
            . 'l’utilisateur demande « liste », « affiche », « montre-moi », « quels sont »… '
            . 'et aussi « combien », « nombre de » : dans ce cas mode=' . self::MODE_COMPTE
            . ', qui rend le NOMBRE au lieu des lignes, avec exactement les mêmes filtres. Le paramètre lieA restreint '
            . 'aux enregistrements LIÉS à une fiche précise, même à plusieurs niveaux de relation '
            . '(ex. les tâches d’une piste, les tâches ou avenants d’un CLIENT via ses pistes) — '
            . 'SEUL moyen fiable de connaître les éléments liés : une fiche ne les contient jamais. '
            . 'lieA accepte un NOM (lieA={entite:"Client", nom:"Dupont"}) autant qu’un id : ne fais '
            . 'JAMAIS une recherche préalable pour obtenir un identifiant, le serveur résout le nom. '
            . 'De même, un « filtre » qui ne correspond à aucun libellé mais au nom d’un '
            . 'rattachement (un client, un assureur) est réinterprété automatiquement, et le '
            . 'champ « filtreInterpreteCommeLien » te dit alors ce qui a réellement été listé : '
            . 'énonce-le. Un filtre entièrement NUMÉRIQUE est aussi cherché comme IDENTIFIANT '
            . '(« 131 » ramène l’enregistrement #131 si aucun libellé ne porte ce texte) : c’est '
            . 'la façon de retrouver un enregistrement dont tu connais l’id — celui d’un plan que '
            . 'tu viens de faire exécuter, par exemple. '
            . 'Chaque POLICE listée porte les DEUX SENS de sa chaîne de renouvellement : '
            . '« suiteDeLaPolice » + « avenantsIssusIds » (ce qu’elle est devenue, avec les '
            . 'identifiants des polices qui lui succèdent) et « origineDeLaPolice » + '
            . '« avenantPrecedentId » (la police qu’elle REMPLACE, vide pour une affaire '
            . 'nouvelle). Utilise-les tels quels pour relier une police renouvelée à son '
            . 'renouvellement : n’essaie JAMAIS de le déduire d’une ressemblance de nom ou de date. '
            . 'Les paramètres echeance (Avenant), axes (Tranche), validation (Cotation) '
            . 'et transformation (Piste) appliquent EXACTEMENT les mêmes règles que les filtres '
            . 'rapides de ces rubriques, tri par urgence inclus : à utiliser dès que la question '
            . 'porte sur une fenêtre d’échéance (« quels avenants échoient dans les 30 jours ? »), '
            . 'un statut de paiement, un statut de souscription (« quelles propositions en attente ? ») '
            . 'ou un statut de transformation (« quelles pistes en cours ? »), afin que la réponse '
            . 'coïncide avec ce que l’utilisateur voit à l’écran. La liste porte par défaut sur le '
            . 'PORTEFEUILLE de l’utilisateur, comme la rubrique affichée (paramètre perimetre). '
            . 'Chaque enregistrement porte son identifiant, son libellé ET LES COLONNES DE LA '
            . 'RUBRIQUE (valeurs calculées comprises : taux, montants, soldes), avec leurs rôles '
            . 'de présentation — tu peux donc composer un tableau riche sans autre appel. '
            . 'Pour une COTATION, '
            . 'chaque item porte aussi son statut (« Souscrite » = déjà liée à un avenant, donc '
            . 'police concrétisée, PAS une simple proposition ; « En attente » = proposition non '
            . 'validée) : appuie-toi dessus, ne suppose jamais qu’une cotation listée est en attente.';
    }

    public function aiguillage(): string
    {
        return '« lesquels / liste / montre-moi » : lister des enregistrements. '
            . '« combien / nombre de » : LE MÊME outil avec mode=' . self::MODE_COMPTE . ', jamais un autre. '
            . 'Aussi pour « montre / liste les '
            . 'polices non renouvelables (ou « à ne pas renouveler ») » avec echeance: non_renouvelables — le '
            . 'CINQUIÈME groupe d\'échéance, celui des décisions, aligné avec le chip du même nom dans la '
            . 'rubrique Avenants. Ces polices ne sont PAS un retard et n\'entrent dans AUCUNE des quatre '
            . 'fenêtres de dates.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'mode' => [
                    'type' => 'string',
                    'enum' => [self::MODE_LISTE, self::MODE_COMPTE],
                    'description' => 'liste (défaut) = les enregistrements ; compte = leur NOMBRE, '
                        . 'mêmes filtres, sans les lignes.',
                ],
                'entite' => [
                    'type' => 'string',
                    'description' => "Nom court de l'entité à lister ou compter (ex. Client, Avenant, Piste).",
                    'enum' => $this->lexique->nomsCourts(),
                ],
                'filtre' => [
                    'type' => 'string',
                    'description' => 'Texte recherché dans le libellé des enregistrements (optionnel).',
                ],
                'page' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'Numéro de page à restituer (défaut : 1).',
                ],
                'echeance' => AvenantEcheanceScope::proprieteSchema(),
                'axes' => TranchePaiementScope::proprieteSchema(
                    'TRANCHE uniquement, mêmes règles que les groupes de chips de la rubrique.'
                ),
                'validation' => CotationSouscriptionScope::proprieteSchema(),
                'transformation' => PisteTransformationScope::proprieteSchema(),
                'perimetre' => PortefeuilleScope::proprieteSchema(),
                'lieA' => $this->lexique->lieASchema(),
            ],
            'required' => ['entite'],
        ];
    }

    public function match(string $question, AiScope $scope): ?array
    {
        $normalized = AiText::normalize($question);

        // DEUX FAMILLES DE VERBES, UN SEUL OUTIL. « liste les clients » et « combien de
        // clients ? » posent la même question au serveur ; seule la forme de la réponse
        // change. Les deux aiguillages vivaient dans deux outils dont les match() étaient
        // identiques à trois lignes près — c'est cette gémellité qui a fini par produire
        // des noms d'outils inventés, le modèle hésitant entre deux portes vers la même
        // pièce.
        $veutCompter = (bool) preg_match('/\b(combien|nombre|compte[sz]?)\b/', $normalized);
        $veutLister = (bool) preg_match(
            '/\b(liste[rsz]?|affiche[rsz]?|montre[rsz]?|enumere[rsz]?|quel(?:le)?s sont)\b/',
            $normalized,
        );
        if (!$veutCompter && !$veutLister) {
            return null;
        }

        // Le paiement d'une PRIME a son outil dédié : sans cette garde, « liste les
        // paiements de prime… » partait sur la rubrique Paiements (trésorerie du courtier).
        if (PaiementPrimeIntent::concerne($normalized)) {
            return null;
        }

        // « compte » est aussi le SUBSTANTIF d'un relevé : « où en est le compte du client
        // X ? » demande une position financière, pas un dénombrement. Sans cette garde, on
        // l'emportait sur lire_soa — les outils sont essayés dans l'ordre du conteneur — et
        // on répondait par un nombre de clients. La garde ne vaut QUE pour la famille du
        // comptage : « liste les comptes clients » reste une liste.
        if ($veutCompter && !$veutLister && ReleveDeCompteIntent::concerne($normalized)) {
            return null;
        }

        $shortName = $this->lexique->matchEntite($normalized);
        if ($shortName === null) {
            return null;
        }

        // La liste doit coïncider avec ce que l'utilisateur voit dans la rubrique : si la
        // question exprime une fenêtre d'échéance ou un statut de paiement, on applique le
        // MÊME critère que le chip correspondant (sources uniques : les classes de scope).
        $args = ['entite' => $shortName];
        // LA LISTE L'EMPORTE en cas d'ambiguïté (« liste-moi combien il y en a ») : rendre
        // les lignes quand on attendait un nombre se rattrape d'un regard, l'inverse non.
        if ($veutCompter && !$veutLister) {
            $args['mode'] = self::MODE_COMPTE;
        }
        if ($shortName === 'Avenant' && ($f = AvenantEcheanceScope::detecterDepuisTexte($normalized)) !== null) {
            $args['echeance'] = $f;
        } elseif ($shortName === 'Tranche' && ($s = TranchePaiementScope::versNomsCourts(TranchePaiementScope::detecterAxesDepuisTexte($normalized))) !== []) {
            $args['axes'] = $s;
        } elseif ($shortName === 'Cotation' && ($v = CotationSouscriptionScope::detecterDepuisTexte($normalized)) !== null) {
            $args['validation'] = $v;
        } elseif ($shortName === 'Piste' && ($t = PisteTransformationScope::detecterDepuisTexte($normalized)) !== null) {
            $args['transformation'] = $t;
        }

        // Le périmètre par défaut est celui de l'écran (portefeuille de l'invité) : seule une
        // demande explicite d'élargissement est détectée ici.
        if (($p = PortefeuilleScope::detecterPerimetreDepuisTexte($normalized)) !== null) {
            $args['perimetre'] = $p;
        }

        return $args;
    }

    /**
     * Cet appel demande-t-il un NOMBRE plutôt qu'une liste ?
     *
     * ⚠ LE DÉFAUT EST LA LISTE, et il le restera. Un `mode` absent, mal orthographié ou
     * rendu dans une autre langue doit produire une liste : c'est le comportement
     * d'avant la fusion, donc celui qui ne surprend personne. Rendre un nombre là où
     * l'utilisateur attendait des lignes serait une régression silencieuse ; l'inverse
     * se voit tout de suite et se rattrape au tour suivant.
     *
     * @param array<string, mixed> $args
     */
    private static function estUnCompte(array $args): bool
    {
        return self::MODE_COMPTE === strtolower(trim((string) ($args['mode'] ?? '')));
    }

    public function execute(array $args, AiScope $scope): AiToolResult
    {
        $shortName = (string) ($args['entite'] ?? '');
        $labels = $this->accessResolver->libellesEntites();
        if (!isset($labels[$shortName])) {
            return AiToolResult::introuvable($shortName);
        }

        // FAIL-CLOSED : sans droit de lecture explicite, les données n'existent
        // pas pour l'assistant.
        if (!$this->accessResolver->canRead($scope->invite, $shortName)) {
            return AiToolResult::horsPerimetre($labels[$shortName]);
        }

        $fqcn = 'App\\Entity\\' . $shortName;
        if (!class_exists($fqcn)) {
            return AiToolResult::introuvable($shortName);
        }

        $filtre = trim((string) ($args['filtre'] ?? ''));

        // UN MONTANT N'EST PAS UN NOM. Le 2026-09-19, à « quel assureur ? » posé sur une
        // réponse citant « une prime totale de 2 784,61 $ », Ket a cherché les avenants
        // dont le LIBELLÉ contient « 2 784,61 » — et a conclu qu'il n'y avait rien.
        // L'utilisateur, lui, lit « cette donnée n'existe pas » là où il n'y a qu'une
        // recherche absurde. On le dit franchement, en nommant ce qui se cherche : la
        // réponse est alors utilisable, au lieu d'être fausse.
        if (self::estUnMontant($filtre)) {
            return AiToolResult::ok([
                'entite'  => $shortName,
                'libelle' => $labels[$shortName],
                'refus'   => sprintf(
                    'Le filtre « %s » est un MONTANT, pas un nom : la recherche porte sur le libellé '
                    . "d'un enregistrement (nom de client, référence de police, numéro). Reprends avec "
                    . "le nom ou la référence de l'élément visé, ou consulte sa fiche par lire_fiche.",
                    $filtre,
                ),
            ]);
        }

        $page = max(1, (int) ($args['page'] ?? 1));
        $displayField = $this->libelleur->displayField($fqcn);

        // Restriction aux enregistrements LIÉS à une fiche (lieA) : les chemins de
        // relations Doctrine vers l'entité de rattachement sont détectés par
        // métadonnées, à plusieurs niveaux (ex. Tache → piste → client pour « les
        // tâches du client X ») — le service de recherche joint chaque segment et
        // filtre par identité. FAIL-CLOSED sur l'entité liée aussi (référencer une
        // fiche = la lire). Sans chemin, on liste sans lien et on le signale au
        // modèle (lienIgnore) : élargir en silence ferait passer une liste générale
        // pour celle du dossier demandé.
        //
        // La résolution elle-même vit dans CritereLieA : telecharger_documents en a
        // besoin à l'identique, et deux copies auraient divergé au premier correctif.
        $resolution = $this->critereLieA->resoudre($args['lieA'] ?? null, $fqcn, $scope);
        if ($resolution->estRefus()) {
            return $resolution->refus;
        }
        $lien = $resolution->lien;
        $lienIgnore = $resolution->ignore ?: null;
        $lienCriteria = $resolution->criteria;

        // Le filtre texte exige un champ de libellé persisté ; sans lui, on
        // liste sans filtrer et on le signale au modèle (filtreIgnore).
        $criteria = ($filtre !== '' && $displayField !== null)
            ? [$displayField => ['operator' => 'LIKE', 'value' => $filtre, 'mode' => 'contains']]
            : [];

        // Filtres rapides des rubriques (mêmes critères synthétiques que les chips, donc même
        // moteur, mêmes bornes et même tri) : fenêtre d'échéance pour Avenant, statut de
        // paiement pour Tranche. Ignorés si l'entité ne s'y prête pas.
        // Scopé à Tranche : sans cette garde, des `axes` transmis par erreur sur une autre
        // entité annonceraient un filtre que la recherche n'a pas appliqué.
        $axesTranche = $shortName === 'Tranche'
            ? TranchePaiementScope::normaliserAxes(is_array($args['axes'] ?? null) ? $args['axes'] : [])
            : [];
        $criteresRubrique = AvenantEcheanceScope::critereRecherche($shortName, $args['echeance'] ?? null)
            + TranchePaiementScope::critereRecherche($shortName, $axesTranche)
            + CotationSouscriptionScope::critereRecherche($shortName, $args['validation'] ?? null)
            + PisteTransformationScope::critereRecherche($shortName, $args['transformation'] ?? null);
        $filtreRubrique = null;
        if (isset($criteresRubrique[AvenantEcheanceScope::CRITERION_KEY])) {
            $filtreRubrique = AvenantEcheanceScope::libelle((string) $criteresRubrique[AvenantEcheanceScope::CRITERION_KEY]['value']);
        } elseif ($axesTranche !== []) {
            // Combinaison lisible (« Prime impayée · Échues ») : le modèle doit pouvoir
            // dire EXACTEMENT quel filtre il a appliqué, axe par axe.
            $filtreRubrique = TranchePaiementScope::libelleCombinaison($axesTranche);
        } elseif (isset($criteresRubrique[CotationSouscriptionScope::CRITERION_KEY])) {
            $filtreRubrique = CotationSouscriptionScope::libelle((string) $criteresRubrique[CotationSouscriptionScope::CRITERION_KEY]['value']);
        } elseif (isset($criteresRubrique[PisteTransformationScope::CRITERION_KEY])) {
            $filtreRubrique = PisteTransformationScope::libelle((string) $criteresRubrique[PisteTransformationScope::CRITERION_KEY]['value']);
        }

        // PÉRIMÈTRE : par défaut le portefeuille de l'invité, comme la rubrique à l'écran
        // (fabrique partagée avec le contrôleur de liste → même critère, même SQL, mêmes
        // enregistrements). Élargi à l'entreprise seulement sur demande explicite.
        $perimetreEntreprise = PortefeuilleScope::estEntreprise($args['perimetre'] ?? null);
        $criterePortefeuille = $perimetreEntreprise
            ? []
            : $this->portefeuilleCritere->pour($shortName, $scope->invite);

        // ── MODE COMPTE ────────────────────────────────────────────────────────────
        //
        // « Combien de clients ? » et « la liste des clients » posent la MÊME question
        // au serveur : mêmes droits, même périmètre, mêmes filtres, même SQL. Seule la
        // taille de page change. Deux outils pour cela ne se distinguaient que par leur
        // nom — et le modèle ne les distinguait justement pas : les deux seuls noms
        // inventés que le rattrapage ne sauvait pas, « lister_entites » (3 appels) et
        // « lecture_donnees » (1), sont nés de cette hésitation.
        //
        // Le compte se prend AVANT la pagination et AVANT les trois détours de repêchage
        // (identifiant, description, rattachement). C'est voulu : un compte doit dire
        // combien il y a d'enregistrements RÉPONDANT AUX CRITÈRES DEMANDÉS, pas combien
        // une recherche élargie de son propre chef a fini par trouver — sans quoi le
        // nombre annoncé ne correspondrait plus à la question posée.
        if (self::estUnCompte($args)) {
            $compte = $this->searchService->search(
                $fqcn,
                $criteria + $lienCriteria + $criteresRubrique + $criterePortefeuille,
                $scope->entreprise,
                null,
                1,
                1,
            );
            if (($compte['status']['code'] ?? 500) !== 200) {
                return AiToolResult::introuvable($labels[$shortName]);
            }

            return AiToolResult::ok(array_filter([
                'entite'    => $shortName,
                'libelle'   => $labels[$shortName],
                'filtre'    => $filtreRubrique,
                'perimetre' => PortefeuilleScope::libellePerimetre($perimetreEntreprise, $criterePortefeuille),
                'count'     => (int) $compte['totalItems'],
            ], static fn ($v) => $v !== null));
        }

        $result = $this->searchService->search($fqcn, $criteria + $lienCriteria + $criteresRubrique + $criterePortefeuille, $scope->entreprise, null, $page, self::PAGE_SIZE);
        if (($result['status']['code'] ?? 500) !== 200) {
            return AiToolResult::introuvable($labels[$shortName]);
        }

        // LE FILTRE TEXTE NE PORTE QUE SUR LE LIBELLÉ — ET C'EST UN PIÈGE.
        // « Kibali » cherché parmi les Avenants s'écrase sur `referencePolice` et ne
        // ramène rien, alors que ce client a sept polices : son nom ne vit pas sur
        // l'avenant, il vit deux relations plus loin. Le modèle, lui, ne pouvait
        // qu'annoncer « aucune police » ou relancer une recherche — un second tour
        // qu'il n'a pas. Le serveur fait donc ce détour lui-même, et gratuitement :
        // si le terme désigne UN enregistrement rattaché (client, assureur, risque,
        // opportunité…), on relance la recherche sur ce rattachement.
        // UN FILTRE ENTIÈREMENT NUMÉRIQUE EST AUSSI UN IDENTIFIANT — et c'était l'autre
        // moitié de la panne du 2026-08-10. Ket venait de créer l'avenant #131 ; le journal
        // du plan le nommait « #131 » ; interrogée dessus au tour suivant, elle a cherché
        // « 131 » comme un LIBELLÉ, n'a rien trouvé (une référence de police ne s'appelle
        // pas « 131 ») et a répondu « aucun élément ». Le serveur fait donc lui-même le
        // second essai : le libellé d'abord — un vrai numéro de police reste prioritaire —,
        // l'identifiant ensuite, et seulement si le premier n'a rien ramené.
        $filtreIdentifiant = null;
        if ($filtre !== '' && ctype_digit($filtre) && (int) $result['totalItems'] === 0) {
            $parId = $this->searchService->search(
                $fqcn,
                ['id' => (int) $filtre] + $lienCriteria + $criteresRubrique + $criterePortefeuille,
                $scope->entreprise,
                null,
                1,
                self::PAGE_SIZE,
            );
            if (($parId['status']['code'] ?? 500) === 200 && (int) $parId['totalItems'] > 0) {
                $result = $parId;
                // ANNONCÉ, jamais subi : le modèle doit dire ce qu'il a réellement lu.
                $filtreIdentifiant = sprintf(
                    '« %s » ne correspond à aucun libellé de cette rubrique, mais à l’IDENTIFIANT %d : '
                    . 'la liste porte donc sur cet enregistrement précis.',
                    $filtre,
                    (int) $filtre,
                );
            }
        }

        // TROISIÈME ESSAI : LA DESCRIPTION. « construction » ne figure dans le nom d'aucun
        // risque, mais dans la description de celui qui couvre les chantiers (incident du
        // 2026-09-16 : « aucun élément dans Risques avec filtre construction »). Tenté
        // seulement quand l'entité PERSISTE une description distincte de son libellé, et
        // annoncé comme les deux autres détours.
        $filtreDescription = null;
        if ($filtre !== '' && $filtreIdentifiant === null && (int) $result['totalItems'] === 0
            && $displayField !== 'description' && $this->em->getClassMetadata($fqcn)->hasField('description')) {
            $parDescription = $this->searchService->search(
                $fqcn,
                ['description' => ['operator' => 'LIKE', 'value' => $filtre, 'mode' => 'contains']] + $lienCriteria + $criteresRubrique + $criterePortefeuille,
                $scope->entreprise,
                null,
                $page,
                self::PAGE_SIZE,
            );
            if (($parDescription['status']['code'] ?? 500) === 200 && (int) $parDescription['totalItems'] > 0) {
                $result = $parDescription;
                $filtreDescription = sprintf(
                    '« %s » ne figure dans aucun libellé de cette rubrique, mais dans la DESCRIPTION des '
                    . 'enregistrements listés : dis-le.',
                    $filtre,
                );
            }
        }

        $filtreLien = null;
        if ($filtre !== '' && $filtreIdentifiant === null && $filtreDescription === null && $lien === null && (int) $result['totalItems'] === 0) {
            $rattachement = $this->rattachementDepuisNom($fqcn, $filtre, $scope);

            if (isset($rattachement['ambigu'])) {
                return AiToolResult::ok([
                    'pret'      => false,
                    'aDemander' => [$rattachement['ambigu']],
                    'note'      => 'Aucun enregistrement ne porte ce nom, mais PLUSIEURS rattachements y '
                        . 'correspondent. Pose la question telle quelle, en UNE ligne, en proposant les '
                        . '« valeurs ». Ne relance AUCUN outil et n’annonce aucune liste.',
                ]);
            }

            if ($rattachement !== null) {
                $lienCriteria = [JSBDynamicSearchService::LIEN_MULTI_CHEMINS => [
                    'paths' => $rattachement['chemins'],
                    'id'    => $rattachement['id'],
                ]];
                $result = $this->searchService->search(
                    $fqcn,
                    $lienCriteria + $criteresRubrique + $criterePortefeuille,
                    $scope->entreprise,
                    null,
                    $page,
                    self::PAGE_SIZE,
                );
                if (($result['status']['code'] ?? 500) !== 200) {
                    return AiToolResult::introuvable($labels[$shortName]);
                }
                $lien = ['entite' => $rattachement['entite'], 'id' => $rattachement['id']];
                // ANNONCÉ, jamais subi : le modèle doit dire ce qu'il a réellement lu.
                $filtreLien = sprintf(
                    '« %s » ne correspond à aucun libellé de cette rubrique, mais à %s « %s » : '
                    . 'la liste porte donc sur les enregistrements qui lui sont rattachés.',
                    $filtre,
                    mb_strtolower($labels[$rattachement['entite']] ?? $rattachement['entite']),
                    $rattachement['libelle'],
                );
            }
        }

        // LES COLONNES DE L'ÉCRAN, pour chaque ligne. Sans elles, une liste ne portait
        // que `id` + `libelle` : prié d'ajouter « une colonne pour le taux », le modèle
        // n'avait aucune donnée et affichait 0 % partout, alors que la rubrique montrait
        // 30 %. La source est le canevas de liste — ajouter une colonne à un écran
        // l'offre désormais du même coup au chat. Un seul préchargement pour la page.
        $ecran = $this->colonnesDeLEcran->projeter($result['data'], $fqcn);
        // De QUI et de QUEL assureur parle chaque police — en UNE requête pour toute la
        // page, jamais trois par ligne (cf. l'incident des indicateurs de liste).
        $rattachements = $shortName === 'Avenant' ? $this->rattachementsDesAvenants($result['data']) : [];

        $items = [];
        foreach ($result['data'] as $entity) {
            $item = [
                'id'      => $entity->getId(),
                'libelle' => $this->libelleur->libelle($entity, $displayField),
            ] + ($ecran['valeurs'][(int) $entity->getId()] ?? []);
            // Une COTATION porte son statut de souscription (bound = au moins un avenant), sinon
            // le modèle, ne voyant qu'un libellé, prend une cotation déjà transformée en police
            // pour une simple proposition en attente. Même source de vérité que le chip de la
            // rubrique et l'indicateur calculé (CotationSouscriptionScope / isCotationBound). Une
            // cotation SOUSCRITE porte en plus sa référence de police et sa période de couverture
            // (indicateurs calculés) : la preuve CONCRÈTE que la couverture existe, pour que le
            // modèle n'ait pas à conclure « aucun contrat actif » faute d'être allé plus loin.
            if ($entity instanceof Cotation) {
                $bound = $this->indicatorHelper->isCotationBound($entity);
                $item['statut'] = CotationSouscriptionScope::statutLibelle($bound);
                if ($bound) {
                    $item['referencePolice'] = $this->indicatorHelper->getCotationReferencePolice($entity);
                    $item['periodeCouverture'] = $this->indicatorHelper->getCotationPeriodeCouverture($entity);
                } elseif ($this->indicatorHelper->isCotationConcurrenteCaduque($entity)) {
                    // Une AUTRE proposition de la même piste est déjà souscrite : le marché est
                    // attribué, celle-ci a perdu l'affaire. On le dit explicitement pour que le
                    // modèle ne la présente pas comme une opportunité « en attente » à relancer.
                    $item['suivi'] = 'Sans suite — une autre proposition de cette piste est souscrite (marché déjà attribué)';
                }
            }
            // Une POLICE (Avenant) porte le même genre de preuve, pour la même raison : sans elle,
            // le modèle répond « pas encore renouvelée » sur une police dont la piste dérivée a
            // pourtant DÉJÀ donné naissance à un avenant. Source unique partagée avec les
            // indicateurs calculés et le badge des listes (AvenantRenouvellementResolver).
            if ($entity instanceof Avenant) {
                // LE CLIENT ET L'ASSUREUR, sur chaque ligne. Sans eux, à « quel assureur ? »
                // posé sur une liste de polices, Ket répondait « le nom du client rattaché à
                // cet avenant n'est pas directement accessible » — puis improvisait une
                // recherche absurde. Le libellé d'une police nomme son risque, pas ses deux
                // parties : c'est pourtant par elles qu'un courtier la désigne.
                $item += array_filter($rattachements[(int) $entity->getId()] ?? []);
                // ET SON ÉCHÉANCE, dite en toutes lettres. Le 2026-09-19, Ket a présenté
                // comme « échéance lointaine » des contrats expirés depuis huit mois, puis
                // s'est contredite au message suivant en affirmant qu'aucune police n'allait
                // au-delà de 60 jours — ce qui était vrai. Une ligne qui porte « Échu » ne
                // peut plus être qualifiée de lointaine.
                $echeance = AvenantEcheanceScope::classifier($entity->getEndingAt(), new \DateTimeImmutable('today'));
                if ($echeance !== null) {
                    $item['echeance'] = $echeance['libelle'];
                }
                $suite = $this->renouvellementResolver->resoudre($entity);
                $item['statutRenouvellement'] = $suite['statut'];
                if ($suite['avenantsIssus'] !== [] || $suite['pisteDeriveeId'] !== null) {
                    $item['suiteDeLaPolice'] = $suite['phrase'];
                }
                // Les identifiants des polices SUCCESSEURS, en clair et à part de la phrase :
                // « quel est l'id de l'avenant issu du renouvellement de 72 ? » se répond alors
                // sans second tour d'outil, et sans que le modèle ait à extraire un nombre d'une
                // phrase française — ce qu'il a raté en production le 2026-08-10.
                if ($suite['avenantsIssus'] !== []) {
                    $item['avenantsIssusIds'] = array_map(static fn (array $a): int => $a['id'], $suite['avenantsIssus']);
                }
                // ET LE SENS INVERSE : de quelle police celle-ci est-elle la suite ? Sans lui,
                // une police née d'un renouvellement se lit comme une affaire nouvelle.
                $origine = $this->renouvellementResolver->origine($entity);
                if ($origine !== null) {
                    $item['origineDeLaPolice'] = $origine['phrase'];
                    $item['avenantPrecedentId'] = $origine['id'];
                }
                // Décision explicite de ne pas renouveler : la phrase du resolver la porte
                // déjà, mais elle n'accompagne l'item que s'il existe une piste dérivée — or
                // ce marquage n'en crée aucune. Sans cette ligne, une police écartée du
                // pipeline apparaîtrait dans une liste sans que RIEN n'explique pourquoi.
                if ($entity->isNonRenouvelable()) {
                    $item['suiteDeLaPolice'] = $suite['phrase'];
                    $item['nonRenouvelable'] = true;
                }
            }
            $items[] = $item;
        }

        return AiToolResult::ok(array_filter([
            'entite'       => $shortName,
            'libelle'      => $labels[$shortName],
            'filtre'       => $filtre !== '' ? $filtre : null,
            'filtreIgnore' => ($filtre !== '' && $displayField === null) ? true : null,
            'filtreInterpreteCommeIdentifiant' => $filtreIdentifiant,
            'filtreInterpreteCommeLien' => $filtreLien,
            'filtreTrouveDansLaDescription' => $filtreDescription,
            'filtreRubrique' => $filtreRubrique,
            'perimetre'    => PortefeuilleScope::libellePerimetre($perimetreEntreprise, $criterePortefeuille),
            'lien'         => $lien,
            'lienIgnore'   => $lienIgnore,
            'page'         => (int) $result['currentPage'],
            'totalPages'   => (int) $result['totalPages'],
            'totalItems'   => (int) $result['totalItems'],
            'items'        => $items,
            // Les rôles de colonne viennent des UNITÉS du canevas : « % » est un taux
            // (jamais sommé), la monnaie du cabinet est un montant. Sans cette
            // déclaration, un « partPourcentage: 30 » s'afficherait « 30 » et non « 30 % ».
            'presentation' => $ecran['presentation'] ?: null,
        ], static fn ($v) => $v !== null));
    }

    /**
     * Le terme cherché désigne-t-il un enregistrement RATTACHÉ plutôt qu'un libellé
     * de la rubrique ? (« Kibali » parmi les Avenants = le CLIENT Kibali.)
     *
     * On interroge chaque entité atteignable en *-vers-un, dans l'ordre de proximité
     * — un rattachement direct prime sur un rattachement lointain, sans quoi « SUNU »
     * chercherait aussi bien l'assureur de la cotation que celui de la piste. La
     * résolution passe par la source unique (ResolveurDeReferences), donc scopée à
     * l'entreprise et fail-closed sur le droit de lecture.
     *
     * Trois issues, comme partout : un seul rattachement → on l'applique ; plusieurs
     * → on rend la question à poser ; aucun → null, et la liste vide reste vide.
     *
     * @return array{entite: string, id: int, libelle: string, chemins: string[]}|array{ambigu: array<string, mixed>}|null
     */
    private function rattachementDepuisNom(string $fqcn, string $terme, AiScope $scope): ?array
    {
        $trouves = [];
        $candidatsAmbigus = [];

        foreach ($this->cheminsParCible($fqcn) as $cibleFqcn => $chemins) {
            $courtCible = substr($cibleFqcn, strrpos($cibleFqcn, '\\') + 1);
            if (!isset($this->accessResolver->libellesEntites()[$courtCible])) {
                continue;
            }
            // chercher() plutôt que resoudre() : on ne veut PAS l'aperçu du référentiel
            // que la seconde ramène sur un échec — ce serait une requête de liste
            // complète par entité explorée, pour une information dont on n'a que faire
            // ici. La garde de lecture (canRead) est la même dans les deux cas.
            $candidats = $this->resolveur->chercher($courtCible, $terme, $scope);
            if (count($candidats) === 1) {
                $trouves[] = [
                    'entite'     => $courtCible,
                    'id'         => (int) array_key_first($candidats),
                    'libelle'    => (string) reset($candidats),
                    'chemins'    => $chemins,
                    'profondeur' => min(array_map(static fn (string $c) => substr_count($c, '.'), $chemins)),
                ];
                continue;
            }
            // Un nom porté par plusieurs enregistrements de la MÊME entité reste une
            // question à poser : on garde ses candidats pour la formuler.
            foreach ($candidats as $id => $libelle) {
                $candidatsAmbigus[$courtCible . '#' . $id] = $libelle;
            }
        }

        if ($trouves === []) {
            return $candidatsAmbigus === [] ? null : ['ambigu' => [
                'champ'    => 'lieA',
                'libelle'  => 'Enregistrement de rattachement',
                'probleme' => Reference::AMBIGUE,
                'terme'    => $terme,
                'valeurs'  => $candidatsAmbigus,
            ]];
        }

        // Le rattachement le PLUS PROCHE l'emporte : c'est celui que l'utilisateur
        // désigne quand il dit « les avenants de X » sans autre précision.
        usort($trouves, static fn (array $a, array $b) => $a['profondeur'] <=> $b['profondeur']);
        $meilleurs = array_values(array_filter(
            $trouves,
            static fn (array $t) => $t['profondeur'] === $trouves[0]['profondeur'],
        ));

        if (count($meilleurs) > 1) {
            return ['ambigu' => [
                'champ'    => 'lieA',
                'libelle'  => 'Enregistrement de rattachement',
                'probleme' => Reference::AMBIGUE,
                'terme'    => $terme,
                'valeurs'  => array_combine(
                    array_map(static fn (array $t) => $t['entite'] . '#' . $t['id'], $meilleurs),
                    array_map(static fn (array $t) => $t['libelle'] . ' (' . $t['entite'] . ')', $meilleurs),
                ),
            ]];
        }

        unset($meilleurs[0]['profondeur']);

        return $meilleurs[0];
    }

    /**
     * Le MÊME parcours, rendu pour TOUTES les cibles atteignables à la fois.
     *
     * Le parcours lui-même vit désormais dans CheminsDeRelation : il sert aussi à
     * FILTRER la rubrique qu'on ouvre à l'écran (ouvrir_rubrique), et deux
     * implémentations du même graphe auraient fini par montrer à l'écran autre chose
     * que ce que le chat annonce.
     *
     * @return array<string, string[]> FQCN de la cible => chemins pointillés distincts
     */
    private function cheminsParCible(string $fqcn): array
    {
        return $this->chemins->parCible($fqcn);
    }
    /**
     * Le client et l'assureur de chaque police, en UNE requête.
     *
     * @param list<object> $entites
     *
     * @return array<int, array{client: ?string, assureur: ?string}>
     */
    private function rattachementsDesAvenants(array $entites): array
    {
        $ids = [];
        foreach ($entites as $entite) {
            if ($entite instanceof Avenant && $entite->getId() !== null) {
                $ids[] = (int) $entite->getId();
            }
        }
        if ($ids === []) {
            return [];
        }

        $rattachements = [];
        $lignes = $this->em->createQuery(
            'SELECT a.id AS id, cl.nom AS client, ass.nom AS assureur
             FROM App\Entity\Avenant a
             LEFT JOIN a.cotation cot
             LEFT JOIN cot.assureur ass
             LEFT JOIN cot.piste p
             LEFT JOIN p.client cl
             WHERE a.id IN (:ids)'
        )->setParameter('ids', $ids)->getArrayResult();
        foreach ($lignes as $ligne) {
            $rattachements[(int) $ligne['id']] = [
                'client'   => $ligne['client'] ?: null,
                'assureur' => $ligne['assureur'] ?: null,
            ];
        }

        return $rattachements;
    }

    /**
     * Un MONTANT, et non un nom : « 2 784,61 », « 2 784,61 $ », « 1 358,22 ».
     *
     * Un numéro de police en est exclu : il porte des tirets ou des lettres
     * (« 12005-31002-0014-13001-00013061-2024 »). Un identifiant nu (« 42 ») l'est aussi,
     * et c'est voulu : l'outil sait déjà le chercher comme identifiant, détour utile. Ce
     * qu'on écarte ici est la forme propre à l'argent — deux décimales.
     */
    private static function estUnMontant(string $filtre): bool
    {
        $nu = trim((string) preg_replace('/\s|\x{00A0}|\x{202F}|[$€]|USD|CDF|FC/u', '', $filtre));

        return $nu !== '' && preg_match('/^\d+[.,]\d{2}$/', $nu) === 1;
    }

}
