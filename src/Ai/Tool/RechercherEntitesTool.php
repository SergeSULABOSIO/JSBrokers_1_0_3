<?php

namespace App\Ai\Tool;

use App\Ai\AiText;
use App\Ai\Resolution\CheminsDeRelation;
use App\Ai\Resolution\Reference;
use App\Ai\Resolution\ResolveurDeReferences;
use App\Ai\Scope\AiScope;
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
 * déléguée à JSBDynamicSearchService (scoping entreprise systématique) ;
 * restitution volontairement compacte (id + libellé) pour maîtriser les
 * tokens — les détails d'un enregistrement relèvent d'outils dédiés (ex.
 * indicateur_calcule).
 */
final class RechercherEntitesTool implements AiToolInterface
{
    /** Taille de page fixe côté serveur : maîtrise des tokens restitués au modèle. */
    private const PAGE_SIZE = 20;

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
    ) {
    }

    public function name(): string
    {
        return 'rechercher_entites';
    }

    public function description(): string
    {
        return "Liste ou recherche les enregistrements d'une catégorie de données de l'entreprise "
            . '(clients, avenants, pistes, notes, sinistres…), avec filtre texte optionnel et '
            . 'pagination (' . self::PAGE_SIZE . ' par page). À appeler quand l’utilisateur demande '
            . '« liste », « affiche », « montre-moi », « quels sont »… Le paramètre lieA restreint '
            . 'aux enregistrements LIÉS à une fiche précise, même à plusieurs niveaux de relation '
            . '(ex. les tâches d’une piste, les tâches ou avenants d’un CLIENT via ses pistes) — '
            . 'SEUL moyen fiable de connaître les éléments liés : une fiche ne les contient jamais. '
            . 'lieA accepte un NOM (lieA={entite:"Client", nom:"Kibali"}) autant qu’un id : ne fais '
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
            . 'Les paramètres echeance (Avenant), statutPaiement (Tranche), validation (Cotation) '
            . 'et transformation (Piste) appliquent EXACTEMENT les mêmes règles que les filtres '
            . 'rapides de ces rubriques, tri par urgence inclus : à utiliser dès que la question '
            . 'porte sur une fenêtre d’échéance (« quels avenants échoient dans les 30 jours ? »), '
            . 'un statut de paiement, un statut de souscription (« quelles propositions en attente ? ») '
            . 'ou un statut de transformation (« quelles pistes en cours ? »), afin que la réponse '
            . 'coïncide avec ce que l’utilisateur voit à l’écran. La liste porte par défaut sur le '
            . 'PORTEFEUILLE de l’utilisateur, comme la rubrique affichée (paramètre perimetre). '
            . 'Renvoie l’identifiant et le libellé de chaque enregistrement ; pour une COTATION, '
            . 'chaque item porte aussi son statut (« Souscrite » = déjà liée à un avenant, donc '
            . 'police concrétisée, PAS une simple proposition ; « En attente » = proposition non '
            . 'validée) : appuie-toi dessus, ne suppose jamais qu’une cotation listée est en attente.';
    }

    public function aiguillage(): string
    {
        return '« lesquels / liste / montre-moi » : lister des enregistrements. Aussi pour « montre / liste les '
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
                'entite' => [
                    'type' => 'string',
                    'description' => "Nom court de l'entité à lister (ex. Client, Avenant, Piste).",
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
                'echeance' => [
                    'type' => 'string',
                    'enum' => array_keys(AvenantEcheanceScope::VALEURS),
                    'description' => 'AVENANT uniquement : restreint à une fenêtre d\'échéance — '
                        . 'echus (déjà expirés), sous_30j (échéance dans les 30 prochains jours), '
                        . 'de_31_a_60j, au_dela_60j. Mêmes bornes que les filtres rapides de la '
                        . 'rubrique, résultats triés du plus urgent au moins urgent.',
                ],
                'axes' => TranchePaiementScope::proprieteSchema(
                    'TRANCHE uniquement, mêmes règles que les groupes de chips de la rubrique.'
                ),
                'validation' => [
                    'type' => 'string',
                    'enum' => array_keys(CotationSouscriptionScope::VALEURS),
                    'description' => 'COTATION uniquement : restreint à un statut de souscription — '
                        . 'souscrites (transformées en police, au moins un avenant), en_attente '
                        . '(non transformées, encore en course), caduques (non transformées mais '
                        . 'une autre proposition de la même piste est souscrite = marché perdu, '
                        . 'sans suite). Mêmes règles que les filtres rapides de la rubrique.',
                ],
                'transformation' => [
                    'type' => 'string',
                    'enum' => array_keys(PisteTransformationScope::VALEURS),
                    'description' => 'PISTE uniquement : restreint à un statut de transformation — '
                        . 'transformees (au moins une cotation souscrite/transformée en police), '
                        . 'en_cours (aucune cotation encore transformée). Mêmes règles que les '
                        . 'filtres rapides de la rubrique.',
                ],
                'perimetre' => PortefeuilleScope::proprieteSchema(),
                'lieA' => [
                    'type' => 'object',
                    'description' => 'Restreint aux enregistrements LIÉS à un enregistrement précis, '
                        . 'même indirectement (le chemin de relations est résolu automatiquement) : '
                        . 'les tâches de la piste 42 → entite=Tache et lieA={entite: "Piste", id: 42} ; '
                        . 'les avenants du client « Kibali » → entite=Avenant et '
                        . 'lieA={entite: "Client", nom: "Kibali"}. Donne "id" si tu l\'as (fiche '
                        . 'attachée, résultat précédent) ; SINON donne simplement "nom" — le serveur '
                        . 'résout le nom lui-même, tu n\'as JAMAIS à faire une recherche préalable '
                        . 'pour obtenir un identifiant.',
                    'properties' => [
                        'entite' => [
                            'type' => 'string',
                            'enum' => $this->lexique->nomsCourts(),
                            'description' => "Nom court de l'enregistrement de rattachement.",
                        ],
                        'id' => [
                            'type' => 'integer',
                            'description' => "Identifiant de l'enregistrement de rattachement, si tu le connais déjà.",
                        ],
                        'nom' => [
                            'type' => 'string',
                            'description' => 'À défaut d\'identifiant : le nom dicté par l\'utilisateur '
                                . '(« Kibali », « SUNU », « Mme Marlette »). Résolu par le serveur.',
                        ],
                    ],
                    'required' => ['entite'],
                ],
            ],
            'required' => ['entite'],
        ];
    }

    public function match(string $question, AiScope $scope): ?array
    {
        $normalized = AiText::normalize($question);
        if (!preg_match('/\b(liste[rsz]?|affiche[rsz]?|montre[rsz]?|enumere[rsz]?|quel(?:le)?s sont)\b/', $normalized)) {
            return null;
        }
        // Le paiement d'une PRIME a son outil dédié : sans cette garde, « liste les
        // paiements de prime… » partait sur la rubrique Paiements (trésorerie du courtier).
        if (PaiementPrimeIntent::concerne($normalized)) {
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
        $page = max(1, (int) ($args['page'] ?? 1));
        $displayField = $this->libelleur->displayField($fqcn);

        // Restriction aux enregistrements LIÉS à une fiche (lieA) : le plus
        // court chemin de relations Doctrine vers l'entité de rattachement est
        // détecté par métadonnées, à plusieurs niveaux (ex. Tache → piste →
        // client pour « les tâches du client X ») — le service de recherche
        // joint chaque segment et filtre par identité. FAIL-CLOSED sur
        // l'entité liée aussi (référencer une fiche = la lire). Sans chemin,
        // on liste sans lien et on le signale au modèle (lienIgnore).
        $lien = null;
        $lienIgnore = null;
        $lienCriteria = [];
        $lieA = $args['lieA'] ?? null;
        if (\is_array($lieA) && $lieA !== []) {
            $lienType = (string) ($lieA['entite'] ?? '');
            $lienFqcn = 'App\\Entity\\' . $lienType;

            // NOM PLUTÔT QU'IDENTIFIANT. Le modèle n'a presque jamais l'identifiant : le
            // lui exiger, c'est le condamner à une recherche préalable — donc à un
            // SECOND tour d'outils, que l'architecture interdit (cf. MAX_TOOL_ROUNDS).
            // C'est exactement ce qui se produisait sur « les polices du client Kibali » :
            // le modèle cherchait le client, n'avait plus de tour pour chercher ses
            // polices, et l'utilisateur recevait une non-réponse. Le serveur résout donc
            // le nom lui-même, gratuitement.
            $lienId = (int) ($lieA['id'] ?? 0);
            $nomLien = trim((string) ($lieA['nom'] ?? ''));
            if ($lienId <= 0 && $nomLien !== '' && isset($labels[$lienType]) && class_exists($lienFqcn)) {
                if (!$this->accessResolver->canRead($scope->invite, $lienType)) {
                    return AiToolResult::horsPerimetre($labels[$lienType]);
                }
                $reference = $this->resolveur->resoudre($lienType, $nomLien, $scope);
                if ($reference->estResolue()) {
                    $lienId = (int) $reference->id;
                } else {
                    // On ne devine pas : on rend une question DÉJÀ formulée, avec ses
                    // candidats. Une question précise vaut mieux qu'une liste vide.
                    return AiToolResult::ok([
                        'pret'      => false,
                        'aDemander' => [$reference->question()],
                        'note'      => 'Le rattachement demandé ne se résout pas. Pose la question telle quelle, '
                            . 'en UNE ligne, en PROPOSANT les « valeurs » quand il y en a. N’invente aucun '
                            . 'identifiant, ne relance AUCUN outil et n’annonce pas de liste.',
                    ]);
                }
            }

            if (!isset($labels[$lienType]) || !class_exists($lienFqcn) || $lienId <= 0) {
                $lienIgnore = true;
            } elseif (!$this->accessResolver->canRead($scope->invite, $lienType)) {
                return AiToolResult::horsPerimetre($labels[$lienType]);
            } elseif (($chemins = $this->cheminsVers($fqcn, $lienFqcn)) === []) {
                $lienIgnore = true;
            } else {
                // Plusieurs chemins peuvent relier les deux entités (ex. Avenant → Client via sa
                // cotation OU via sa piste de renouvellement) : on les passe TOUS au moteur, qui
                // matche dès qu'un seul pointe sur la fiche (OR) — sinon le plus court, parfois une
                // relation secondaire nulle, masquait les enregistrements réellement liés.
                $lienCriteria[JSBDynamicSearchService::LIEN_MULTI_CHEMINS] = ['paths' => $chemins, 'id' => $lienId];
                $lien = ['entite' => $lienType, 'id' => $lienId];
            }
        }

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

        $filtreLien = null;
        if ($filtre !== '' && $filtreIdentifiant === null && $lien === null && (int) $result['totalItems'] === 0) {
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

        $items = [];
        foreach ($result['data'] as $entity) {
            $item = [
                'id'      => $entity->getId(),
                'libelle' => $this->libelleur->libelle($entity, $displayField),
            ];
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
            'filtreRubrique' => $filtreRubrique,
            'perimetre'    => PortefeuilleScope::libellePerimetre($perimetreEntreprise, $criterePortefeuille),
            'lien'         => $lien,
            'lienIgnore'   => $lienIgnore,
            'page'         => (int) $result['currentPage'],
            'totalPages'   => (int) $result['totalPages'],
            'totalItems'   => (int) $result['totalItems'],
            'items'        => $items,
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
     * TOUS les chemins de relations *-vers-un reliant $fqcn à $cibleFqcn dans la
     * profondeur permise (CheminsDeRelation::MAX_PROFONDEUR), chemins SIMPLES (sans repasser par
     * une classe déjà traversée) : « piste » (direct), « piste.client », « cotation.piste.client »…
     * Générique pour TOUT couple d'entités du workspace — chaque enfant pointant vers son
     * parent en *-vers-un, les chemins remontent la généalogie père → fils → petit-fils.
     *
     * On renvoie TOUS les chemins, pas seulement le plus court : celui-ci peut emprunter une
     * relation secondaire souvent NULLE (ex. Avenant.pisteDeRenouvellement → Client, len 2)
     * tandis que le vrai lien passe plus profond (Avenant.cotation.piste.client, len 3). L'appelant
     * les combine en OR pour ne manquer aucun enregistrement réellement lié. Seuls les segments
     * *-vers-un sont traversés (un segment collection dupliquerait les lignes paginées).
     *
     * @return string[] Chemins pointillés distincts ([] si aucun dans la profondeur permise).
     */
    private function cheminsVers(string $fqcn, string $cibleFqcn): array
    {
        return $this->chemins->vers($fqcn, $cibleFqcn);
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
}
