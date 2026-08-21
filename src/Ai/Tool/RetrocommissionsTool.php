<?php

namespace App\Ai\Tool;

use App\Ai\AiText;
use App\Ai\Presentation\Colonnes;
use App\Ai\Scope\AiScope;
use App\Entity\Invite;
use App\Entity\Partenaire;
use App\Repository\InviteRepository;
use App\Repository\PartenaireRepository;
use App\Repository\ReversementRetroAgentRepository;
use App\Service\Retro\AgentRetro;
use App\Service\Retro\BeneficiaireRetro;
use App\Service\Retro\PartenaireRetro;
use App\Service\Retro\RapportProductionBuilder;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Services\Canvas\Indicator\IndicatorCalculationHelper;
use App\Services\Canvas\Indicator\TrancheIndicatorStrategy;
use App\Services\Search\CotationSouscriptionScope;
use DateTimeImmutable;

/**
 * LES RÉTROCOMMISSIONS — qui a droit à quoi, ce qui a été versé, ce qui reste dû, et sur
 * quelle affaire. Agent interne ET partenaire externe, par le même outil.
 *
 * ── POURQUOI UN SEUL OUTIL POUR LES DEUX ────────────────────────────────────────────
 * L'agent avait son rapport, le partenaire n'avait rien — alors qu'il se sert AVANT lui
 * sur la même commission. Deux outils jumeaux auraient tenu la parité à la main, et
 * l'auraient perdue au premier ajout de colonne. Ici, la parité est STRUCTURELLE : les
 * deux camps traversent le même RapportProductionBuilder, et ce qui les distingue est
 * cantonné dans BeneficiaireRetro.
 *
 * ── IL NE CALCULE RIEN ──────────────────────────────────────────────────────────────
 * Tout vient d'IndicatorCalculationHelper, la source unique qui alimente les fiches et les
 * écrans. Un chiffre annoncé par Ket est donc, au centime, celui que le courtier lit.
 *
 * ── UN BÉNÉFICIAIRE SE DÉSIGNE PAR SON NOM ──────────────────────────────────────────
 * L'outil précédent n'acceptait qu'un `agentId`. Or l'entité Invite est volontairement
 * absente du vocabulaire de l'assistant — elle relève de la gestion des invités — si bien
 * que le modèle n'avait AUCUN moyen d'obtenir cet identifiant : « la rétrocommission due à
 * l'agent Serge SULA » finissait en question de clarification, Ket cherchant ce nom parmi
 * les seuls « Intermédiaires » qu'elle sût nommer. L'outil résout donc lui-même ce qu'on
 * lui dicte, avec SA garde d'accès.
 *
 * ── DEUX GARDES, PARCE QUE DEUX RÉGIMES ─────────────────────────────────────────────
 *   AGENT      : soi-même toujours ; un collègue seulement si gestionnaire d'invités.
 *   PARTENAIRE : droit de lecture ordinaire sur la rubrique « Intermédiaires ».
 * Un invité ordinaire ne voit donc jamais la rémunération d'un collègue, et le mode « tous
 * les bénéficiaires » se réduit pour lui à lui-même. Fail-closed, dans execute().
 *
 * ── LE PIÈGE À NE JAMAIS COMMETTRE ──────────────────────────────────────────────────
 * Le BÉNÉFICIAIRE n'est pas le GESTIONNAIRE. On peut apporter dix affaires et n'en gérer
 * aucune. Rien ici ne lit Piste::invite pour trouver un bénéficiaire.
 */
final class RetrocommissionsTool implements AiToolInterface
{
    /** Bénéficiaires restitués par appel en mode « par bénéficiaire » (sortie compacte). */
    private const MAX_BENEFICIAIRES = 25;

    /** Lignes restituées : le rendu du chat plafonne de toute façon le tableau. */
    private const MAX_LIGNES = 25;

    private const AXES = ['client', 'risque', 'assureur', 'mois', 'condition'];

    private const MOIS = [
        1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril', 5 => 'Mai', 6 => 'Juin',
        7 => 'Juillet', 8 => 'Août', 9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre',
    ];

    public function __construct(
        private readonly WorkspaceAccessResolver $accessResolver,
        private readonly InviteRepository $inviteRepository,
        private readonly PartenaireRepository $partenaireRepository,
        private readonly ReversementRetroAgentRepository $reversements,
        private readonly RapportProductionBuilder $rapportBuilder,
        private readonly IndicatorCalculationHelper $helper,
        private readonly TrancheIndicatorStrategy $strategieTranche,
    ) {
    }

    public function name(): string
    {
        return 'retrocommissions';
    }

    public function description(): string
    {
        return 'Consulte les RÉTROCOMMISSIONS dues aux bénéficiaires du partage de commission : '
            . 'AGENTS INTERNES du cabinet et PARTENAIRES externes (intermédiaires). Ce qui leur est '
            . 'DÛ, ce qui a été PAYÉ, le SOLDE et ce qui est EXIGIBLE. '
            . 'Le bénéficiaire se désigne par son NOM (« Serge SULA », « SUNU Courtage ») ou son '
            . 'identifiant ; sans bénéficiaire, rend une ligne par bénéficiaire — la réponse à « à '
            . 'qui dois-je de la rétrocommission ? ». '
            . 'detail="par_ligne" rend le décompte affaire par affaire : prime du client, commission '
            . 'TTC et HT, taxes, commission pure, assiette partageable, rétrocommission, versé, '
            . 'solde, exigible, condition appliquée, taux, origine du taux et assiette — de quoi '
            . 'JUSTIFIER un montant contesté. detail="par_axe" ventile ces mêmes montants par '
            . 'client, risque, assureur, mois ou condition. '
            . 'du/au bornent la période sur la date d\'effet des polices. Le paramètre statut filtre '
            . 'les affaires SOUSCRITES (défaut, seules celles dont la rétro est due), celles EN '
            . 'ATTENTE de validation (projections, jamais un dû) ou les CADUQUES. '
            . 'ATTENTION : le bénéficiaire n\'est PAS le gestionnaire de l\'affaire. Pour '
            . 'ENREGISTRER un versement à un agent, utiliser signaler_reversement_retro_agent.';
    }

    public function aiguillage(): string
    {
        return 'La rémunération de qui apporte les affaires — AGENT interne du cabinet comme '
            . 'PARTENAIRE externe : « le décompte de la rétrocommission due à l\'agent Serge SULA », '
            . '« combien reste-t-il à verser à Alice ? », « détaille la production de SUNU affaire par '
            . 'affaire », « répartis-moi sa rétro par assureur », « à qui dois-je de la '
            . 'rétrocommission ? ». Un NOM suffit, tu n\'as pas d\'identifiant à chercher ailleurs — '
            . 'et un agent interne n\'est pas un intermédiaire externe : cet outil connaît les deux.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'beneficiaire' => [
                    'type' => 'string',
                    'description' => 'Nom du bénéficiaire tel que dicté par l\'utilisateur, ou son '
                        . 'identifiant. Omis : tous les bénéficiaires visibles.',
                ],
                'type' => [
                    'type' => 'string',
                    'enum' => [BeneficiaireRetro::TYPE_AGENT, BeneficiaireRetro::TYPE_PARTENAIRE],
                    'description' => 'agent = collaborateur interne du cabinet ; partenaire = '
                        . 'intermédiaire externe. Omis : déduit du nom, et la question est posée '
                        . 'seulement si le nom désigne les deux.',
                ],
                'detail' => [
                    'type' => 'string',
                    'enum' => ['par_beneficiaire', 'par_ligne', 'par_axe'],
                    'description' => 'par_beneficiaire (défaut) = une ligne par bénéficiaire ; '
                        . 'par_ligne = le décompte affaire par affaire ; par_axe = ventilation '
                        . '(axe requis). Les deux derniers exigent un bénéficiaire.',
                ],
                'axe' => [
                    'type' => 'string',
                    'enum' => self::AXES,
                    'description' => 'Axe de ventilation pour detail=par_axe.',
                ],
                'du' => [
                    'type' => 'string',
                    'description' => 'Début de période (AAAA-MM-JJ), sur la date d\'effet de la police.',
                ],
                'au' => [
                    'type' => 'string',
                    'description' => 'Fin de période (AAAA-MM-JJ), incluse.',
                ],
                'statut' => [
                    'type' => 'string',
                    'enum' => array_keys(CotationSouscriptionScope::VALEURS),
                    'description' => 'Statut de souscription des affaires retenues. Défaut : '
                        . 'souscrites — les seules dont la rétrocommission est réellement due.',
                ],
            ],
            'required' => [],
        ];
    }

    /**
     * Chemin simulé. « rétrocommission » suffit ici : l'outil couvre désormais les deux
     * camps, il n'y a plus à exiger la mention d'un agent pour se distinguer d'un
     * homonyme partenaire.
     */
    public function match(string $question, AiScope $scope): ?array
    {
        $normalized = AiText::normalize($question);
        if (!preg_match('/\b(retrocom\w*|retro\s*commission\w*)\b/', $normalized)) {
            return null;
        }

        $args = [];

        // LE NOM DICTÉ, pour le moteur SIMULÉ seulement.
        //
        // Le moteur réel remplit `beneficiaire` en comprenant la phrase ; le simulé, lui,
        // n'a que des motifs. Sans cette extraction, « la rétro de l'agent Serge SULA »
        // rendait la liste de TOUS les agents en dev et en test — réponse plausible et
        // hors sujet, la pire espèce. On lit la casse sur la question BRUTE : un nom propre
        // porte des majuscules, et c'est le seul indice fiable dont dispose une regex.
        $nom = '/\b(?:agents?|apporteurs?|partenaires?|interm[eé]diaires?)\s+'
            . '((?:[A-ZÀ-Þ][\p{L}\'’-]*)(?:\s+[A-ZÀ-Þ][\p{L}\'’-]*)*)/u';
        $verse = '/\b(?:verser|payer|regler|régler|due?s?|doit|dois)\s+(?:à|a)\s+'
            . '((?:[A-ZÀ-Þ][\p{L}\'’-]*)(?:\s+[A-ZÀ-Þ][\p{L}\'’-]*)*)/u';
        foreach ([$nom, $verse] as $motif) {
            if (preg_match($motif, $question, $m)) {
                $args['beneficiaire'] = trim($m[1]);
                break;
            }
        }

        if (preg_match('/\b(agents?|apporteur\w*|interne\w*|collaborateur\w*)\b/', $normalized)) {
            $args['type'] = BeneficiaireRetro::TYPE_AGENT;
        } elseif (preg_match('/\b(partenaires?|intermediaires?)\b/', $normalized)) {
            $args['type'] = BeneficiaireRetro::TYPE_PARTENAIRE;
        }
        if (preg_match('/\b(detail\w*|ligne\s*par\s*ligne|affaire\s*par\s*affaire)\b/', $normalized)) {
            $args['detail'] = 'par_ligne';
        }
        foreach (self::AXES as $axe) {
            if (preg_match('/\bpar\s+' . $axe . '\w*\b/', $normalized)) {
                $args['detail'] = 'par_axe';
                $args['axe'] = $axe;
                break;
            }
        }
        if (($statut = CotationSouscriptionScope::detecterDepuisTexte($normalized)) !== null) {
            $args['statut'] = $statut;
        }

        return $args;
    }

    public function execute(array $args, AiScope $scope): AiToolResult
    {
        $statut = (string) ($args['statut'] ?? CotationSouscriptionScope::STATUT_SOUSCRITES);
        if (!CotationSouscriptionScope::estValide($statut)) {
            $statut = CotationSouscriptionScope::STATUT_SOUSCRITES;
        }
        [$du, $au] = $this->periode($args);

        $terme = trim((string) ($args['beneficiaire'] ?? ''));
        $type = $this->typeDemande($args);
        $detail = (string) ($args['detail'] ?? 'par_beneficiaire');

        if ($terme === '') {
            if ($detail !== 'par_beneficiaire') {
                return AiToolResult::introuvable(
                    'Bénéficiaire non précisé',
                    'Le décompte détaillé porte sur UN bénéficiaire : rappelle l\'outil avec son nom.',
                );
            }

            return $this->parBeneficiaire($this->tousLesBeneficiaires($type, $scope), $statut, $du, $au, $scope);
        }

        $candidats = $this->resoudre($terme, $type, $scope);
        if ($candidats === []) {
            return AiToolResult::introuvable(
                'Bénéficiaire « ' . $terme . ' »',
                'Aucun agent interne ni partenaire externe de ce nom. Vérifie l\'orthographe, ou '
                . 'demande la liste complète en rappelant cet outil SANS bénéficiaire.',
            );
        }
        if (count($candidats) > 1) {
            return AiToolResult::ok([
                'ambigu' => [
                    'champ' => 'beneficiaire',
                    'probleme' => 'Plusieurs bénéficiaires portent ce nom',
                    'valeurs' => array_map(
                        static fn (BeneficiaireRetro $b) => $b->nom() . ' (' . $b->type() . ' #' . $b->id() . ')',
                        $candidats,
                    ),
                ],
            ]);
        }

        $beneficiaire = $candidats[0];
        if (!$this->peutConsulter($beneficiaire, $scope)) {
            return AiToolResult::horsPerimetre('Rétrocommissions de ' . $beneficiaire->nom());
        }

        return match ($detail) {
            'par_ligne' => $this->parLigne($beneficiaire, $statut, $du, $au, $scope),
            'par_axe'   => $this->parAxe($beneficiaire, (string) ($args['axe'] ?? 'client'), $statut, $du, $au, $scope),
            default     => $this->parBeneficiaire([$beneficiaire], $statut, $du, $au, $scope),
        };
    }

    // ===================== Modes =====================

    /**
     * Une ligne par bénéficiaire : dû, payé, solde, exigible. Ceux qui n'ont aucune
     * rétrocommission sont écartés — ils n'ont rien à dire dans ce tableau.
     *
     * @param list<BeneficiaireRetro> $beneficiaires
     */
    private function parBeneficiaire(array $beneficiaires, string $statut, ?DateTimeImmutable $du, ?DateTimeImmutable $au, AiScope $scope): AiToolResult
    {
        $items = [];
        foreach (array_slice($beneficiaires, 0, self::MAX_BENEFICIAIRES) as $beneficiaire) {
            $totaux = $this->rapportBuilder->build($beneficiaire, $scope->entreprise, $statut, $du, $au)['totaux'];
            if ($totaux['nbLignes'] === 0 && $totaux['due'] <= 0.0) {
                continue;
            }

            $items[] = [
                'beneficiaireId' => $beneficiaire->id(),
                'beneficiaire'   => $beneficiaire->nom(),
                'type'           => $beneficiaire->type(),
                'affaires'       => $totaux['nbLignes'],
                'due'            => $totaux['due'],
                'payee'          => $totaux['payee'],
                'solde'          => $totaux['solde'],
                'exigible'       => $totaux['exigible'],
                'commissionPure' => $totaux['commissionPure'],
            ];
        }

        return AiToolResult::ok(array_filter([
            'statut' => CotationSouscriptionScope::libelle($statut),
            'periode' => $this->libellePeriode($du, $au),
            'items'  => $items,
            'totalItems' => count($items),
            'presentation' => $items === [] ? null : Colonnes::de([
                'beneficiaire' => Colonnes::TEXTE,
                'type'         => Colonnes::STATUT,
                'affaires'     => Colonnes::NOMBRE,
                'due'          => Colonnes::MONTANT,
                'payee'        => Colonnes::MONTANT,
                'solde'        => Colonnes::MONTANT,
                'exigible'     => Colonnes::MONTANT,
            ]),
            'note' => self::NOTE_DES_DEUX_CIRCUITS,
            'perimetre' => $this->libellePerimetre($scope),
        ], static fn ($v) => $v !== null && $v !== []));
    }

    /** Le décompte détaillé d'un bénéficiaire : une ligne par affaire, comme à l'écran. */
    private function parLigne(BeneficiaireRetro $beneficiaire, string $statut, ?DateTimeImmutable $du, ?DateTimeImmutable $au, AiScope $scope): AiToolResult
    {
        $rapport = $this->rapportBuilder->build($beneficiaire, $scope->entreprise, $statut, $du, $au);

        $items = [];
        foreach (array_slice($rapport['lignes'], 0, self::MAX_LIGNES) as $ligne) {
            $items[] = [
                'avenantId'   => $ligne['avenant']?->getId(),
                'client'      => $ligne['client'],
                'police'      => $ligne['reference'],
                'risque'      => $ligne['risque'],
                'assureur'    => $ligne['assureur'],
                // Le GESTIONNAIRE : rappel explicite qu'il n'est pas le bénéficiaire.
                'gestionnaire'    => $ligne['gestionnaire'],
                'prime'           => $ligne['prime'],
                // Où en est l'argent EN AMONT du partage : c'est ce qui décide de
                // l'exigibilité, et la première question de qui conteste son solde.
                'primePayee'      => $ligne['primePayee'],
                'primeSolde'      => $ligne['primeSolde'],
                'commissionTtc'   => $ligne['commissionTtc'],
                'commissionEncaissee' => $ligne['commissionEncaissee'],
                'commissionSolde'     => $ligne['commissionSolde'],
                'commissionHt'    => $ligne['commissionHt'],
                'taxeAssureur'    => $ligne['taxeAssureur'],
                'taxeCourtier'    => $ligne['taxeCourtier'],
                'commissionPure'  => $ligne['commissionPure'],
                'partageable'     => $ligne['partageable'],
                'retroPartenaire' => $ligne['retroPartenaire'],
                'due'             => $ligne['due'],
                'payee'           => $ligne['payee'],
                'solde'           => $ligne['solde'],
                'exigible'        => $ligne['exigible'],
                // La chaîne de calcul : de quoi JUSTIFIER, et non seulement affirmer.
                'condition'       => $ligne['conditionNom'],
                'taux'            => $ligne['conditionTaux'],
                'origineDuTaux'   => $ligne['conditionOrigine'],
                'assiette'        => $ligne['assiette'],
                'seuilFranchi'    => $ligne['seuilFranchi'],
                'uniteMesure'     => $ligne['uniteMesure'],
                'eligibilite'     => $ligne['eligibiliteLibelle'],
            ];
        }

        return AiToolResult::ok(array_filter([
            'beneficiaire' => $beneficiaire->nom(),
            'type'   => $beneficiaire->type(),
            'statut' => CotationSouscriptionScope::libelle($statut),
            'periode' => $this->libellePeriode($du, $au),
            'projection' => $rapport['projection'] ? true : null,
            'items'  => $items,
            'totalItems' => count($rapport['lignes']),
            'presentation' => $items === [] ? null : Colonnes::de([
                'client'   => Colonnes::TEXTE,
                'police'   => Colonnes::TEXTE,
                'prime'    => Colonnes::MONTANT,
                'commissionPure' => Colonnes::MONTANT,
                'due'      => Colonnes::MONTANT,
                'payee'    => Colonnes::MONTANT,
                'solde'    => Colonnes::MONTANT,
            ]),
            // Rôles des colonnes PROMOUVABLES : chaque ligne en porte une vingtaine, sept
            // se lisent d'un coup d'œil. Le modèle peut en afficher une autre à la
            // demande, avec le bon format, sans second appel.
            'colonnesDisponibles' => $items === [] ? null : self::ROLES_LIGNE,
            'colonnesDisponiblesNote' => $items === [] ? null : 'Clés déjà présentes dans chaque '
                . 'ligne : ajoute-en UNE au tableau si l\'utilisateur la demande, avec le format '
                . 'de son rôle. Pour JUSTIFIER un montant, cite assiette, taux et origineDuTaux.',
            'totaux' => $items === [] ? null : [
                'prime'           => $rapport['totaux']['prime'],
                'primePayee'      => $rapport['totaux']['primePayee'],
                'commissionEncaissee' => $rapport['totaux']['commissionEncaissee'],
                'commissionPure'  => $rapport['totaux']['commissionPure'],
                'retroPartenaire' => $rapport['totaux']['retroPartenaire'],
                'due'             => $rapport['totaux']['due'],
                'payee'           => $rapport['totaux']['payee'],
                'solde'           => $rapport['totaux']['solde'],
                'exigible'        => $rapport['totaux']['exigible'],
            ],
            'note' => $beneficiaire->note(),
        ], static fn ($v) => $v !== null && $v !== []));
    }

    /**
     * Les MÊMES lignes, regroupées. On n'y recalcule rien : agréger des lignes déjà
     * produites est la seule façon de garantir que la ventilation et le détail disent la
     * même chose.
     */
    private function parAxe(BeneficiaireRetro $beneficiaire, string $axe, string $statut, ?DateTimeImmutable $du, ?DateTimeImmutable $au, AiScope $scope): AiToolResult
    {
        if (!in_array($axe, self::AXES, true)) {
            $axe = 'client';
        }

        $rapport = $this->rapportBuilder->build($beneficiaire, $scope->entreprise, $statut, $du, $au);

        $groupes = [];
        foreach ($rapport['lignes'] as $ligne) {
            $cle = $this->valeurDAxe($ligne, $axe);
            $groupes[$cle] ??= ['libelle' => $cle, 'affaires' => 0, 'due' => 0.0, 'payee' => 0.0, 'solde' => 0.0, 'exigible' => 0.0];
            $groupes[$cle]['affaires']++;
            foreach (['due', 'payee', 'solde', 'exigible'] as $colonne) {
                $groupes[$cle][$colonne] += (float) $ligne[$colonne];
            }
        }

        uasort($groupes, static fn (array $a, array $b) => $b['due'] <=> $a['due']);
        $items = array_map(
            static fn (array $g) => array_map(static fn ($v) => is_float($v) ? round($v, 2) : $v, $g),
            array_values($groupes),
        );

        return AiToolResult::ok(array_filter([
            'beneficiaire' => $beneficiaire->nom(),
            'type'   => $beneficiaire->type(),
            'axe'    => $axe,
            'statut' => CotationSouscriptionScope::libelle($statut),
            'periode' => $this->libellePeriode($du, $au),
            'items'  => $items,
            'totalItems' => count($items),
            'presentation' => $items === [] ? null : Colonnes::de([
                'libelle'  => Colonnes::TEXTE,
                'affaires' => Colonnes::NOMBRE,
                'due'      => Colonnes::MONTANT,
                'payee'    => Colonnes::MONTANT,
                'solde'    => Colonnes::MONTANT,
                'exigible' => Colonnes::MONTANT,
            ]),
            'graphiqueConseille' => $items === [] ? null : 'Cette ventilation se lit bien en '
                . 'graphique : un « bar » (ou « line » sur l\'axe mois) avec libelle en labels et '
                . 'due en série, légende obligatoire.',
            'note' => $beneficiaire->note(),
        ], static fn ($v) => $v !== null && $v !== []));
    }

    /** @param array<string, mixed> $ligne */
    private function valeurDAxe(array $ligne, string $axe): string
    {
        if ($axe === 'mois') {
            $debut = $ligne['debut'] ?? null;
            if (!$debut instanceof \DateTimeInterface) {
                return 'Sans date d\'effet';
            }

            return self::MOIS[(int) $debut->format('n')] . ' ' . $debut->format('Y');
        }

        if ($axe === 'condition') {
            return (string) ($ligne['conditionNom'] ?? $ligne['conditionOrigine'] ?? 'Sans condition');
        }

        return (string) ($ligne[$axe] ?? 'N/A');
    }

    // ===================== Résolution =====================

    /**
     * Le nom dicté devient un bénéficiaire — c'est à l'outil de le faire, jamais à
     * l'utilisateur de fournir un identifiant qu'il ne connaît pas.
     *
     * Une correspondance EXACTE l'emporte sur une correspondance partielle : « SUNU » ne
     * doit pas être écrasé par « SUNU IARD RDC » quand les deux existent.
     *
     * @return list<BeneficiaireRetro>
     */
    private function resoudre(string $terme, ?string $type, AiScope $scope): array
    {
        $agents = $type === BeneficiaireRetro::TYPE_PARTENAIRE ? [] : $this->chercherAgents($terme, $scope);
        $partenaires = $type === BeneficiaireRetro::TYPE_AGENT ? [] : $this->chercherPartenaires($terme, $scope);

        return array_merge(
            array_map(fn (Invite $a) => $this->agentRetro($a), $agents),
            array_map(fn (Partenaire $p) => $this->partenaireRetro($p), $partenaires),
        );
    }

    /** @return list<Invite> */
    private function chercherAgents(string $terme, AiScope $scope): array
    {
        if (ctype_digit($terme)) {
            $agent = $this->inviteRepository->findOneBy(['id' => (int) $terme, 'entreprise' => $scope->entreprise]);

            return $agent !== null ? [$agent] : [];
        }

        $candidats = $this->inviteRepository->createQueryBuilder('i')
            ->andWhere('i.entreprise = :e')->setParameter('e', $scope->entreprise)
            ->andWhere('LOWER(i.nom) LIKE :t')->setParameter('t', '%' . mb_strtolower($terme) . '%')
            ->orderBy('i.nom', 'ASC')
            ->getQuery()->getResult();

        return $this->exactDAbord($candidats, $terme, static fn (Invite $i) => (string) $i->getNom());
    }

    /** @return list<Partenaire> */
    private function chercherPartenaires(string $terme, AiScope $scope): array
    {
        if (!$this->accessResolver->canRead($scope->invite, 'Partenaire')) {
            return [];
        }

        if (ctype_digit($terme)) {
            $partenaire = $this->partenaireRepository->findOneBy(['id' => (int) $terme, 'entreprise' => $scope->entreprise]);

            return $partenaire !== null ? [$partenaire] : [];
        }

        $candidats = $this->partenaireRepository->createQueryBuilder('p')
            ->andWhere('p.entreprise = :e')->setParameter('e', $scope->entreprise)
            ->andWhere('LOWER(p.nom) LIKE :t')->setParameter('t', '%' . mb_strtolower($terme) . '%')
            ->orderBy('p.nom', 'ASC')
            ->getQuery()->getResult();

        return $this->exactDAbord($candidats, $terme, static fn (Partenaire $p) => (string) $p->getNom());
    }

    /**
     * @template T of object
     *
     * @param list<T>          $candidats
     * @param callable(T):string $nom
     *
     * @return list<T>
     */
    private function exactDAbord(array $candidats, string $terme, callable $nom): array
    {
        $exacts = array_values(array_filter(
            $candidats,
            static fn ($c) => mb_strtolower(trim($nom($c))) === mb_strtolower(trim($terme)),
        ));

        return $exacts !== [] ? $exacts : array_values($candidats);
    }

    /** @return list<BeneficiaireRetro> */
    private function tousLesBeneficiaires(?string $type, AiScope $scope): array
    {
        $beneficiaires = [];

        if ($type !== BeneficiaireRetro::TYPE_PARTENAIRE) {
            $agents = $this->accessResolver->canManageInvites($scope->invite)
                ? $this->inviteRepository->findBy(['entreprise' => $scope->entreprise], ['nom' => 'ASC'])
                : [$scope->invite];
            foreach ($agents as $agent) {
                $beneficiaires[] = $this->agentRetro($agent);
            }
        }

        if ($type !== BeneficiaireRetro::TYPE_AGENT
            && $this->accessResolver->canRead($scope->invite, 'Partenaire')) {
            foreach ($this->partenaireRepository->findBy(['entreprise' => $scope->entreprise], ['nom' => 'ASC']) as $partenaire) {
                $beneficiaires[] = $this->partenaireRetro($partenaire);
            }
        }

        return $beneficiaires;
    }

    private function agentRetro(Invite $agent): AgentRetro
    {
        return new AgentRetro($agent, $this->helper, $this->reversements);
    }

    private function partenaireRetro(Partenaire $partenaire): PartenaireRetro
    {
        return new PartenaireRetro($partenaire, $this->helper, $this->strategieTranche);
    }

    // ===================== Périmètre =====================

    /** Soi-même toujours ; un autre agent seulement si gestionnaire d'invités. */
    private function peutConsulter(BeneficiaireRetro $beneficiaire, AiScope $scope): bool
    {
        if ($beneficiaire->type() === BeneficiaireRetro::TYPE_PARTENAIRE) {
            return $this->accessResolver->canRead($scope->invite, 'Partenaire');
        }

        return $beneficiaire->id() === $scope->invite->getId()
            || $this->accessResolver->canManageInvites($scope->invite);
    }

    private function libellePerimetre(AiScope $scope): string
    {
        $agents = $this->accessResolver->canManageInvites($scope->invite)
            ? 'tous les agents du cabinet'
            : 'vos propres rétrocommissions';
        $partenaires = $this->accessResolver->canRead($scope->invite, 'Partenaire')
            ? ' et tous les intermédiaires externes'
            : '';

        return ucfirst($agents) . $partenaires;
    }

    // ===================== Période =====================

    /** @return array{0: ?DateTimeImmutable, 1: ?DateTimeImmutable} */
    private function periode(array $args): array
    {
        return [$this->borne($args['du'] ?? null), $this->borne($args['au'] ?? null, true)];
    }

    /**
     * Une borne illisible est IGNORÉE plutôt que devinée : filtrer sur une date inventée
     * écarterait des affaires sans que personne ne s'en aperçoive.
     */
    private function borne(mixed $valeur, bool $finDeJournee = false): ?DateTimeImmutable
    {
        if (!is_string($valeur) || trim($valeur) === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('Y-m-d', trim($valeur));

        return $date === false
            ? null
            : $date->setTime($finDeJournee ? 23 : 0, $finDeJournee ? 59 : 0, $finDeJournee ? 59 : 0);
    }

    private function libellePeriode(?DateTimeImmutable $du, ?DateTimeImmutable $au): ?string
    {
        if ($du === null && $au === null) {
            return null;
        }

        return trim(($du !== null ? 'du ' . $du->format('d/m/Y') : '')
            . ($au !== null ? ' au ' . $au->format('d/m/Y') : ''));
    }

    private function typeDemande(array $args): ?string
    {
        $type = (string) ($args['type'] ?? '');

        return in_array($type, [BeneficiaireRetro::TYPE_AGENT, BeneficiaireRetro::TYPE_PARTENAIRE], true)
            ? $type
            : null;
    }

    /** Rôles de présentation des clés promouvables d'une ligne détaillée. */
    private const ROLES_LIGNE = [
        'gestionnaire'    => Colonnes::TEXTE,
        'risque'          => Colonnes::TEXTE,
        'assureur'        => Colonnes::TEXTE,
        'commissionTtc'   => Colonnes::MONTANT,
        'primePayee'      => Colonnes::MONTANT,
        'primeSolde'      => Colonnes::MONTANT,
        'commissionEncaissee' => Colonnes::MONTANT,
        'commissionSolde'     => Colonnes::MONTANT,
        'commissionHt'    => Colonnes::MONTANT,
        'taxeAssureur'    => Colonnes::MONTANT,
        'taxeCourtier'    => Colonnes::MONTANT,
        'partageable'     => Colonnes::MONTANT,
        'retroPartenaire' => Colonnes::MONTANT,
        'exigible'        => Colonnes::MONTANT,
        'assiette'        => Colonnes::MONTANT,
        'condition'       => Colonnes::TEXTE,
        'taux'            => Colonnes::POURCENTAGE,
        'origineDuTaux'   => Colonnes::TEXTE,
        'uniteMesure'     => Colonnes::TEXTE,
        'eligibilite'     => Colonnes::STATUT,
    ];

    /**
     * La règle des DEUX circuits, à joindre au tableau qui les mélange. Trois confusions
     * y sont désamorcées d'avance, chacune coûteuse.
     */
    private const NOTE_DES_DEUX_CIRCUITS = 'DEUX CIRCUITS DISTINCTS. Le PARTENAIRE externe se '
        . 'sert le premier, sur la commission pure des revenus partageables ; il se facture par '
        . 'NOTE DE CRÉDIT (SYSCOHADA 632), et son payé se déduit au prorata des règlements, à la '
        . 'maille de la tranche. L\'AGENT interne partage ensuite le RELIQUAT — la commission pure '
        . 'moins ce qui est parti chez les partenaires ; il est payé par virement direct, sans '
        . 'aucune note (SYSCOHADA 6611), à la maille de l\'avenant. '
        . 'Dans les deux cas, le bénéficiaire APPORTE l\'affaire : il n\'en est pas le '
        . 'gestionnaire. « due » naît à la souscription ; « exigible » n\'est réclamable qu\'une '
        . 'fois le cabinet lui-même encaissé — ne propose jamais de verser un montant non exigible.';
}
