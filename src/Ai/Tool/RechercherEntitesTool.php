<?php

namespace App\Ai\Tool;

use App\Ai\AiText;
use App\Ai\Scope\AiScope;
use App\Entity\Cotation;
use App\Service\Workspace\WorkspaceAccessResolver;
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

    /** Profondeur maximale du chemin de relations exploré pour lieA (père → fils → petit-fils). */
    private const MAX_PROFONDEUR_LIEN = 3;

    public function __construct(
        private readonly WorkspaceAccessResolver $accessResolver,
        private readonly JSBDynamicSearchService $searchService,
        private readonly EntiteLexique $lexique,
        private readonly EntiteLibelle $libelleur,
        private readonly EntityManagerInterface $em,
        private readonly PortefeuilleCritereFactory $portefeuilleCritere,
        private readonly IndicatorCalculationHelper $indicatorHelper,
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
                'statutPaiement' => [
                    'type' => 'string',
                    'enum' => array_keys(TranchePaiementScope::VALEURS),
                    'description' => 'TRANCHE uniquement : restreint à un statut de paiement — '
                        . 'impayees, echues, a_echoir, partiellement, payees, retro_a_payer, '
                        . 'commission_exigible. Mêmes règles que les filtres rapides de la rubrique.',
                ],
                'validation' => [
                    'type' => 'string',
                    'enum' => array_keys(CotationSouscriptionScope::VALEURS),
                    'description' => 'COTATION uniquement : restreint à un statut de souscription — '
                        . 'souscrites (transformées en police, au moins un avenant), en_attente '
                        . '(non transformées). Mêmes règles que les filtres rapides de la rubrique.',
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
                        . 'les tâches du client 82 → entite=Tache et lieA={entite: "Client", id: 82}. '
                        . "L'id s'obtient d'une fiche attachée ou d'une première recherche.",
                    'properties' => [
                        'entite' => [
                            'type' => 'string',
                            'enum' => $this->lexique->nomsCourts(),
                            'description' => "Nom court de l'enregistrement de rattachement.",
                        ],
                        'id' => [
                            'type' => 'integer',
                            'description' => "Identifiant de l'enregistrement de rattachement.",
                        ],
                    ],
                    'required' => ['entite', 'id'],
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
        } elseif ($shortName === 'Tranche' && ($s = TranchePaiementScope::detecterDepuisTexte($normalized)) !== null) {
            $args['statutPaiement'] = $s;
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
            $lienId = (int) ($lieA['id'] ?? 0);
            $lienFqcn = 'App\\Entity\\' . $lienType;
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
        $criteresRubrique = AvenantEcheanceScope::critereRecherche($shortName, $args['echeance'] ?? null)
            + TranchePaiementScope::critereRecherche($shortName, $args['statutPaiement'] ?? null)
            + CotationSouscriptionScope::critereRecherche($shortName, $args['validation'] ?? null)
            + PisteTransformationScope::critereRecherche($shortName, $args['transformation'] ?? null);
        $filtreRubrique = null;
        if (isset($criteresRubrique[AvenantEcheanceScope::CRITERION_KEY])) {
            $filtreRubrique = AvenantEcheanceScope::libelle((string) $criteresRubrique[AvenantEcheanceScope::CRITERION_KEY]['value']);
        } elseif (isset($criteresRubrique[TranchePaiementScope::CRITERION_KEY])) {
            $filtreRubrique = TranchePaiementScope::libelle((string) $criteresRubrique[TranchePaiementScope::CRITERION_KEY]['value']);
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
            $items[] = $item;
        }

        return AiToolResult::ok(array_filter([
            'entite'       => $shortName,
            'libelle'      => $labels[$shortName],
            'filtre'       => $filtre !== '' ? $filtre : null,
            'filtreIgnore' => ($filtre !== '' && $displayField === null) ? true : null,
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
     * TOUS les chemins de relations *-vers-un reliant $fqcn à $cibleFqcn dans la
     * profondeur permise (MAX_PROFONDEUR_LIEN), chemins SIMPLES (sans repasser par
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
        $chemins = [];
        // DFS sur les chemins simples : chaque état porte sa propre liste de classes visitées
        // (pas de visite globale) pour explorer les chemins alternatifs, tout en évitant les
        // cycles à l'intérieur d'un même chemin.
        $pile = [[$fqcn, [], [$fqcn => true]]];

        while ($pile !== []) {
            [$classe, $chemin, $visites] = array_pop($pile);
            if (\count($chemin) >= self::MAX_PROFONDEUR_LIEN) {
                continue;
            }
            $metadata = $this->em->getClassMetadata($classe);
            foreach ($metadata->getAssociationNames() as $name) {
                if (!$metadata->isSingleValuedAssociation($name)) {
                    continue;
                }
                $target = $metadata->getAssociationTargetClass($name);
                $nouveauChemin = [...$chemin, $name];
                if ($target === $cibleFqcn) {
                    $chemins[] = implode('.', $nouveauChemin);
                    continue; // cible atteinte : inutile de la dépasser
                }
                if (!isset($visites[$target])) {
                    $pile[] = [$target, $nouveauChemin, $visites + [$target => true]];
                }
            }
        }

        return array_values(array_unique($chemins));
    }
}
