<?php

namespace App\Ai\Tool;

use App\Ai\AiText;
use App\Ai\FicheNormaliseur;
use App\Ai\Scope\AiScope;
use App\Entity\Client;
use App\Entity\ConditionPartage;
use App\Entity\Invite;
use App\Entity\Partenaire;
use App\Entity\Risque;
use App\Repository\RisqueRepository;
use App\Service\Saturation\SaturationService;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Services\JSBDynamicSearchService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * LE CATALOGUE DES RISQUES DU CABINET, en entier : la matière du CONSEIL.
 *
 * L'INCIDENT DU 2026-09-16. « J'ai un client spécialisé dans la construction, quels
 * risques lui proposer ? » → « aucun élément dans Risques avec filtre construction ».
 * « Que couvre l'assurance maladie ? » → repli générique. Ket n'avait qu'une recherche
 * par LIBELLÉ : un secteur d'activité ne ressemble au nom d'aucun risque, et la
 * DESCRIPTION — qui dit ce que le risque couvre — n'était jamais lue.
 *
 * RESTITUTION TOTALE, SANS PLAFOND NI TRONCATURE. Conseiller, c'est comparer : Ket doit
 * voir TOUS les risques configurés, chacun avec sa fiche complète (champs saisis et
 * indicateurs calculés, la même que lire_fiche) et les conditions de partage qui le
 * visent. Un catalogue partiel se raconterait comme un catalogue complet. Le
 * rapprochement sémantique (« construction » → tous risques chantier, RC décennale…)
 * est laissé au modèle, qui lit les descriptions ; un LIKE ne sait pas le faire.
 *
 * PÉRIMÈTRE = ENTREPRISE, pas portefeuille : un catalogue se propose à tout client, il
 * ne s'attribue à personne. FAIL-CLOSED : lecture des Risques exigée ; les conditions
 * de partage et le client ne sont restitués que si l'invité peut aussi les lire.
 *
 * PAS DANS LA COMPRÉHENSION (constaté le 2026-09-16 sur un vrai cabinet) : le comprenant
 * lisait le catalogue, puis RÉPONDAIT à la question dans sa reformulation (« le taux de
 * la Caution est de 15 % ») — et la planification relisait tout derrière lui. Une
 * question de conseil n'a aucune ambiguïté de référence à lever : le catalogue est la
 * matière de la réponse, pas de la compréhension.
 */
final class CatalogueDesRisquesTool implements AiToolInterface
{
    /** Nombre maximal de candidats restitués sur un nom de client ambigu. */
    private const MAX_CANDIDATS = 6;

    /** Mots trop génériques pour départager deux risques. */
    private const MOTS_VIDES = [
        'assurance', 'assurances', 'assurence', 'couverture', 'couvertures', 'couvre', 'risque', 'risques',
        'garantie', 'garanties', 'contre', 'pour', 'avec', 'dans', 'quel', 'quelle', 'quels', 'quelles',
        'type', 'types', 'produit', 'produits', 'police', 'polices', 'client', 'est-ce', 'cette', 'votre',
    ];

    private const FORMULES = [
        ConditionPartage::FORMULE_ASSIETTE_AU_MOINS_EGALE_AU_SEUIL => 'Assiette au moins égale au seuil',
        ConditionPartage::FORMULE_ASSIETTE_INFERIEURE_AU_SEUIL     => 'Assiette inférieure au seuil',
        ConditionPartage::FORMULE_NE_SAPPLIQUE_PAS_SEUIL           => 'Sans seuil',
    ];

    public function __construct(
        private readonly WorkspaceAccessResolver $accessResolver,
        private readonly RisqueRepository $risqueRepository,
        private readonly FicheNormaliseur $ficheNormaliseur,
        private readonly SaturationService $saturation,
        private readonly JSBDynamicSearchService $searchService,
        private readonly EntiteLibelle $libelleur,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function name(): string
    {
        return 'catalogue_des_risques';
    }

    public function description(): string
    {
        return 'Restitue le catalogue COMPLET des risques configurés au cabinet (risque = couverture '
            . 'd\'assurance = type d\'assurance = garantie = produit) : pour chacun, sa fiche entière — '
            . 'DESCRIPTION de ce qu\'il couvre, branche, taux de commission CONFIGURÉ, taxation, '
            . 'indicateurs de production et de sinistralité — et les conditions de partage qui le visent. '
            . 'À appeler pour CONSEILLER : « que couvre X », « quels risques proposer à un client du '
            . 'secteur Y / exposé à Z », « quelle assurance pour… », « quel est le taux de commission '
            . 'du risque X ». Optionnel : « risque » place en tête le(s) risque(s) nommé(s) ; « client » '
            . 'indique ceux qu\'il a déjà souscrits.';
    }

    public function aiguillage(): string
    {
        return '« que couvre / quelle assurance / quels risques (couvertures, types d\'assurance) proposer » '
            . 'à un client selon son activité, son secteur ou ce qui l\'expose, ou le taux configuré '
            . 'd\'un risque : lis le catalogue complet et fonde ton conseil sur les DESCRIPTIONS du cabinet.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'besoin' => [
                    'type' => 'string',
                    'description' => 'Le besoin exprimé : activité, secteur ou exposition du client '
                        . '(« entreprise de construction », « flotte de camions »). Repris tel quel.',
                ],
                'risque' => [
                    'type' => 'string',
                    'description' => 'Nom (même approximatif) d\'un risque précis : placé en tête du catalogue.',
                ],
                'client' => [
                    'type' => 'string',
                    'description' => 'Nom du client conseillé : marque les risques qu\'il a déjà souscrits.',
                ],
                'idClient' => [
                    'type' => 'integer',
                    'description' => 'Identifiant du client (prioritaire sur « client »).',
                ],
            ],
            'required' => [],
        ];
    }

    public function match(string $question, AiScope $scope): ?array
    {
        $normalized = AiText::normalize($question);
        if (preg_match('/\bque couvre(?:nt)?\s+(?:l[\' ]?|la |le |les )?(.{2,60}?)(?:\s*\?|$)/', $normalized, $m)) {
            return ['risque' => trim($m[1])];
        }
        if (preg_match(
            '/\b(quel(?:le)?s?\s+(?:risques?|assurances?|couvertures?|garanties?|types? d[\' ]?assurances?)\s+(?:proposer|conseiller|pour|lui)|specialise dans|secteur d[\' ]?activite|catalogue des risques)\b/',
            $normalized,
        )) {
            return ['besoin' => trim($question)];
        }

        return null;
    }

    public function execute(array $args, AiScope $scope): AiToolResult
    {
        if (!$this->accessResolver->canRead($scope->invite, 'Risque')) {
            return AiToolResult::horsPerimetre('Risques');
        }

        $risques = $this->risqueRepository->findCatalogueForEntreprise($scope->entreprise);

        $souscrits = null;
        $clientLibelle = null;
        $idClient = (int) ($args['idClient'] ?? 0);
        $nomClient = trim((string) ($args['client'] ?? ''));
        if (($idClient > 0 || $nomClient !== '') && $this->accessResolver->canRead($scope->invite, 'Client')) {
            $resolution = $this->resoudreClient($idClient, $nomClient, $scope);
            if ($resolution instanceof AiToolResult) {
                return $resolution;
            }
            if ($resolution instanceof Client) {
                $souscrits = $this->saturation->risquesSouscrits($resolution, $scope->entreprise);
                $clientLibelle = (string) $resolution->getNom();
            }
        }

        $conditionsParRisque = $this->accessResolver->canRead($scope->invite, 'ConditionPartage')
            ? $this->conditionsParRisque($scope)
            : null;
        $termes = $this->termes((string) ($args['risque'] ?? ''));

        $catalogue = [];
        foreach ($risques as $risque) {
            $entree = $this->entree($risque);
            if (($conditionsParRisque[$risque->getId()] ?? []) !== []) {
                $entree['conditionsDePartage'] = $conditionsParRisque[$risque->getId()];
            }
            if ($souscrits !== null) {
                $entree['dejaSouscritParLeClient'] = isset($souscrits[$risque->getId()]);
            }
            $entree['_score'] = $this->score($termes, $risque);
            $catalogue[] = $entree;
        }

        // Tri STABLE : les risques nommés en tête, le reste dans l'ordre alphabétique du
        // catalogue. Aucun risque n'est retiré.
        $correspondances = [];
        if ($termes !== []) {
            usort($catalogue, static fn (array $a, array $b): int => $b['_score'] <=> $a['_score']);
            foreach ($catalogue as $entree) {
                if ($entree['_score'] > 0) {
                    $correspondances[] = $entree['nom'];
                }
            }
        }
        foreach ($catalogue as &$entree) {
            unset($entree['_score']);
        }
        unset($entree);

        $data = [
            'nbRisques'         => \count($catalogue),
            'catalogueComplet'  => true,
            'uniteDesTaux'      => 'points de % (15 = 15 %)',
            'lectureDesTaux'    => 'tauxCommissionConfigure est le taux CONTRACTUEL du risque, celui qui se '
                . 'facture et qui s\'annonce. production.tauxCommissionMoyenConstate est une moyenne observée sur les '
                . 'polices souscrites : elle ne dit rien du taux convenu.',
        ];
        if (trim((string) ($args['besoin'] ?? '')) !== '') {
            $data['besoin'] = trim((string) $args['besoin']);
        }
        if ($termes !== []) {
            $data['risqueDemande'] = trim((string) $args['risque']);
            $data['correspondances'] = $correspondances;
        }
        if ($clientLibelle !== null) {
            $data['client'] = $clientLibelle;
        }
        $data['risques'] = $catalogue;
        $data['note'] = $catalogue === []
            ? 'Le cabinet n\'a configuré AUCUN risque : dis-le, et invite à renseigner le catalogue (rubrique Risques).'
            : 'Tu as sous les yeux TOUT le catalogue du cabinet. Fonde ton conseil D\'ABORD sur les descriptions '
                . 'et cite-les ; donne le taux de commission CONFIGURÉ (fiche.tauxCommissionConfigure), jamais la '
                . 'moyenne constatée. Une couverture utile au client mais absente du catalogue se signale comme '
                . '« non configurée au cabinet », jamais comme proposable. Signale un risque sans description. '
                . ($souscrits !== null ? 'Ne propose pas comme nouveau un risque déjà souscrit par ce client. ' : '')
                . ($termes !== [] && $correspondances === []
                    ? 'Aucun risque ne porte le nom demandé : dis-le et nomme les plus proches par leur description.'
                    : '');

        return AiToolResult::ok($data);
    }

    /**
     * L'entrée d'UN risque : sa fiche enrichie ENTIÈRE (même source que lire_fiche),
     * débarrassée de ses seules REDITES.
     *
     * Mesuré sur un vrai cabinet (57 risques) : 129 Ko, dont la moitié en doublons —
     * description et nom répétés dans la fiche, note de lecture des taux recopiée 57
     * fois, arbre client → portefeuille → gestionnaire sous chaque piste, et une
     * vingtaine d'indicateurs à 0 sur chaque risque sans production. Or le quota du
     * fournisseur se compte en tokens d'ENTRÉE par minute, et ce résultat repart à la
     * planification PUIS à la rédaction. On ne retire donc AUCUNE information : on
     * cesse de la répéter.
     *
     * @return array<string, mixed>
     */
    private function entree(Risque $risque): array
    {
        $fiche = $this->ficheNormaliseur->ficheEnrichie($risque);
        $description = trim((string) $risque->getDescription());

        $entree = [
            'id'   => $risque->getId(),
            'nom'  => (string) $risque->getNomComplet(),
            'code' => $risque->getCode(),
        ];
        $entree += $description === '' ? ['sansDescription' => true] : ['description' => $description];
        $entree['branche'] = $fiche['brancheString'] ?? null;
        $entree['imposable'] = $risque->isImposable();
        $entree['tauxCommissionConfigure'] = $risque->getPourcentageCommissionSpecifiqueHT()
            ?? 'non configuré sur ce risque';

        // Les pistes, par ce qui les identifie : leur libellé porte déjà le client.
        $pistes = [];
        foreach (is_array($fiche['pistes'] ?? null) ? $fiche['pistes'] : [] as $piste) {
            $pistes[] = array_filter([
                'id'       => $piste['id'] ?? null,
                'nom'      => $piste['nom'] ?? null,
                'client'   => $piste['client']['nom'] ?? null,
                'exercice' => $piste['exercice'] ?? null,
            ], static fn (mixed $v): bool => $v !== null);
        }

        // Tout le reste de la fiche, sauf ce qui vient d'être dit autrement.
        $dejaDit = [
            'id', 'code', 'nomComplet', 'description', 'branche', 'brancheString', 'imposable', 'pistes',
            'pourcentageCommissionSpecifiqueHT', 'tauxCommissionConfigure', 'lectureDesTaux', 'detailCalcul',
        ];
        $production = [];
        $autres = [];
        foreach ($fiche as $cle => $valeur) {
            if (\in_array($cle, $dejaDit, true)) {
                continue;
            }
            if (is_int($valeur) || is_float($valeur)) {
                $production[$cle] = $valeur;
            } elseif ($cle === 'tauxSPInterpretation') {
                $production[$cle] = $valeur;
            } else {
                $autres[$cle] = $valeur;
            }
        }

        $sansProduction = true;
        foreach ($production as $cle => $valeur) {
            if ((is_int($valeur) || is_float($valeur)) && (float) $valeur !== 0.0) {
                $sansProduction = false;
                break;
            }
        }
        // Un risque jamais produit : tous ses indicateurs valent 0, une phrase les dit tous.
        $entree['production'] = $sansProduction
            ? 'Aucune piste, aucune police, aucun sinistre : tous les montants sont à 0.'
            : $production;
        if ($pistes !== []) {
            $entree['pistes'] = $pistes;
        }

        return $entree + $autres;
    }

    /** @return AiToolResult|Client|null un refus/choix à restituer, le client résolu, ou null */
    private function resoudreClient(int $id, string $nom, AiScope $scope): AiToolResult|Client|null
    {
        $displayField = $this->libelleur->displayField(Client::class);
        if ($id > 0) {
            $criteria = ['id' => $id];
        } elseif ($displayField !== null) {
            $criteria = [$displayField => ['operator' => 'LIKE', 'value' => $nom, 'mode' => 'contains']];
        } else {
            return null;
        }

        $result = $this->searchService->search(Client::class, $criteria, $scope->entreprise, null, 1, self::MAX_CANDIDATS);
        $entities = ($result['status']['code'] ?? 500) === 200 ? $result['data'] : [];
        if ($entities === []) {
            return AiToolResult::introuvable(sprintf('Client « %s »', $nom !== '' ? $nom : '#' . $id));
        }
        if (\count($entities) > 1) {
            return AiToolResult::ok([
                'entite'    => 'Client',
                'ambigu'    => true,
                'candidats' => array_map(
                    fn (object $e): array => ['id' => $e->getId(), 'libelle' => $this->libelleur->libelle($e, $displayField)],
                    $entities,
                ),
                'note'      => 'Plusieurs clients portent ce nom : demande lequel, puis rappelle l\'outil avec idClient.',
            ]);
        }

        return $entities[0];
    }

    /**
     * Conditions de partage qui CIBLENT un risque, lues côté PROPRIÉTAIRE de la relation
     * (ConditionPartage.produits) et en une seule requête pour tout le catalogue : le côté
     * inverse n'est pas synchronisé en mémoire, et une requête par risque ne passerait pas
     * l'échelle d'un catalogue entier.
     *
     * @return array<int, list<array<string, mixed>>> id du risque => conditions
     */
    private function conditionsParRisque(AiScope $scope): array
    {
        /** @var ConditionPartage[] $conditions */
        $conditions = $this->em->createQueryBuilder()
            ->select('c', 'r')
            ->from(ConditionPartage::class, 'c')
            ->join('c.produits', 'r')
            ->andWhere('c.entreprise = :entreprise')
            ->setParameter('entreprise', $scope->entreprise)
            ->orderBy('c.nom', 'ASC')
            ->getQuery()
            ->getResult();

        $parRisque = [];
        foreach ($conditions as $condition) {
            $ligne = $this->condition($condition);
            foreach ($condition->getProduits() as $risque) {
                $parRisque[$risque->getId()][] = $ligne;
            }
        }

        return $parRisque;
    }

    /** @return array<string, mixed> */
    private function condition(ConditionPartage $condition): array
    {
        $beneficiaire = $condition->getBeneficiaire();

        return array_filter([
            'id'           => $condition->getId(),
            'nom'          => $condition->getNom(),
            'taux'         => $condition->getTaux(),
            'formule'      => self::FORMULES[$condition->getFormule()] ?? null,
            'seuil'        => $condition->getSeuil(),
            'critere'      => match ($condition->getCritereRisque()) {
                ConditionPartage::CRITERE_EXCLURE_TOUS_CES_RISQUES => 'Ce risque est EXCLU du partage',
                ConditionPartage::CRITERE_INCLURE_TOUS_CES_RISQUES => 'Ce risque est INCLUS dans le partage',
                default                                            => null,
            },
            'beneficiaire' => match (true) {
                $beneficiaire instanceof Partenaire => 'Partenaire « ' . $beneficiaire->getNom() . ' »',
                $beneficiaire instanceof Invite     => 'Agent interne « ' . $beneficiaire . ' »',
                default                             => null,
            },
            'propreAUneAffaire' => $condition->getPiste() !== null ? true : null,
        ], static fn (mixed $v): bool => $v !== null && $v !== '');
    }

    /** @return list<string> termes significatifs, normalisés */
    private function termes(string $texte): array
    {
        $termes = [];
        foreach (preg_split('/[^a-z0-9]+/', AiText::normalize($texte)) ?: [] as $mot) {
            if (\strlen($mot) >= 3 && !\in_array($mot, self::MOTS_VIDES, true)) {
                $termes[] = $mot;
            }
        }

        return array_values(array_unique($termes));
    }

    /**
     * Pertinence d'un risque pour les termes demandés. Le NOM et le CODE pèsent plus que
     * la description ; la comparaison se fait sur les cinq premières lettres pour
     * tolérer une faute de frappe ou un pluriel (« maladies », « incendi »).
     *
     * @param list<string> $termes
     */
    private function score(array $termes, Risque $risque): int
    {
        if ($termes === []) {
            return 0;
        }

        $nom = AiText::normalize($risque->getNomComplet() . ' ' . $risque->getCode());
        $description = AiText::normalize((string) $risque->getDescription());
        $score = 0;
        foreach ($termes as $terme) {
            $racine = preg_quote(substr($terme, 0, 5), '/');
            if (preg_match('/\b' . $racine . '/', $nom)) {
                $score += 3;
            } elseif (preg_match('/\b' . $racine . '/', $description)) {
                $score += 1;
            }
        }

        return $score;
    }
}
