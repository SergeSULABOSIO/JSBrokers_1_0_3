<?php

namespace App\Ai\Tool;

use App\Ai\AiText;
use App\Ai\Presentation\Colonnes;
use App\Ai\Scope\AiScope;
use App\Entity\Invite;
use App\Repository\InviteRepository;
use App\Service\RetroAgent\RapportProductionAgentBuilder;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Services\Search\CotationSouscriptionScope;

/**
 * Outil de données : LES RÉTROCOMMISSIONS DES AGENTS INTERNES — qui a droit à quoi, ce
 * qui a été versé, ce qui reste dû, et sur quelle affaire.
 *
 * Deux modes :
 *  - PAR AGENT (défaut) : une ligne par agent bénéficiaire — dû, payé, solde. La réponse à
 *    « quels agents ont des rétrocommissions dues ? ».
 *  - PAR LIGNE (agentId requis) : le rapport de production détaillé d'UN agent, affaire par
 *    affaire, de la prime du client jusqu'au solde — exactement le tableau de l'écran.
 *
 * ── IL NE CALCULE RIEN ──────────────────────────────────────────────────────────────
 * Il appelle RapportProductionAgentBuilder, le même service que la route du workspace, qui
 * lui-même ne fait que lire IndicatorCalculationHelper. Un chiffre annoncé par Ket est donc,
 * au centime, celui que le courtier lit à l'écran. C'est la seule façon de tenir la parité
 * sans entretenir deux jeux de formules.
 *
 * ── LA GARDE D'ACCÈS EST PARTICULIÈRE, ET DÉLIBÉRÉMENT ──────────────────────────────
 * L'entité Invite relève de la GESTION DES INVITÉS : sa lecture exige normalement
 * canManageInvites(). Mais l'exigence métier est qu'un agent retrouve SES rétrocommissions
 * depuis SON compte. D'où, à l'identique du contrôleur :
 *
 *      SOI-MÊME, TOUJOURS.  UN AUTRE AGENT, SEULEMENT SI GESTIONNAIRE D'INVITÉS.
 *
 * Un invité ordinaire ne voit donc jamais la rémunération d'un collègue, et le mode « tous
 * les agents » se réduit pour lui à lui-même. Fail-closed, et miroir exact d'estDisponible().
 *
 * ── LE PIÈGE À NE JAMAIS COMMETTRE ──────────────────────────────────────────────────
 * L'agent BÉNÉFICIAIRE n'est pas le GESTIONNAIRE de l'affaire. Un agent peut apporter dix
 * affaires et n'en gérer aucune. Rien ici ne lit Piste::invite pour trouver un bénéficiaire.
 */
final class RetrocommissionsAgentTool implements AiToolInterface, AiToolConditionnel
{
    /** Agents restitués par appel en mode « par agent » (sortie compacte). */
    private const MAX_AGENTS = 25;

    public function __construct(
        private readonly WorkspaceAccessResolver $accessResolver,
        private readonly InviteRepository $inviteRepository,
        private readonly RapportProductionAgentBuilder $rapportBuilder,
    ) {
    }

    public function name(): string
    {
        return 'retrocommissions_agent';
    }

    public function description(): string
    {
        return 'Consulte les RÉTROCOMMISSIONS DES AGENTS INTERNES du cabinet : ce qui leur est '
            . 'DÛ, ce qui a été PAYÉ et le SOLDE restant. Sans agentId, rend une ligne par agent '
            . 'bénéficiaire (la réponse à « quels agents ont des rétrocommissions dues ? »). Avec '
            . 'agentId et detail="par_ligne", rend le rapport de production détaillé de cet '
            . 'agent : une ligne par affaire, avec la prime du client, la commission TTC et HT, '
            . 'les taxes, la commission pure, l\'assiette partageable, la rétrocommission du '
            . 'partenaire externe, celle de l\'agent, ce qui lui a été versé, le solde et le '
            . 'montant EXIGIBLE. Le paramètre statut filtre les affaires SOUSCRITES (défaut, '
            . 'seules celles dont la rétro est réellement due), celles EN ATTENTE de validation '
            . 'du client (projections, jamais un dû) ou les CADUQUES. '
            . 'À appeler dès que la question porte sur la rémunération d\'un agent sur les '
            . 'affaires qu\'il a apportées. ATTENTION : l\'agent bénéficiaire n\'est PAS le '
            . 'gestionnaire de l\'affaire — il peut l\'avoir apportée puis en confier le suivi à '
            . 'quelqu\'un d\'autre. Ne pas confondre avec la rétrocommission d\'un PARTENAIRE '
            . 'externe, qui se facture par note de crédit et suit un tout autre circuit. Pour '
            . 'ENREGISTRER un versement, utiliser signaler_reversement_retro_agent.';
    }

    public function aiguillage(): string
    {
        return 'La rémunération interne des agents sur les affaires qu\'ils apportent : « quels agents ont des '
            . 'rétrocommissions dues ? », « combien reste-t-il à verser à Alice ? », « détaille la production '
            . 'de tel agent affaire par affaire ». Distinct des rétrocommissions de PARTENAIRES externes.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'agentId' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'Identifiant de l\'agent (un invité). Omets-le pour obtenir '
                        . 'tous les agents bénéficiaires du cabinet.',
                ],
                'detail' => [
                    'type' => 'string',
                    'enum' => ['par_agent', 'par_ligne'],
                    'description' => 'par_agent (défaut) = une ligne par agent ; par_ligne = le '
                        . 'rapport détaillé affaire par affaire (agentId requis).',
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
     * Chemin simulé : question sur ce qui est dû à un agent. « rétrocommission » seul ne
     * suffit pas — c'est aussi le mot des partenaires ; on exige donc la mention d'un
     * agent, d'un apporteur ou du caractère interne.
     */
    public function match(string $question, AiScope $scope): ?array
    {
        $normalized = AiText::normalize($question);

        $parleDeRetro = preg_match('/\b(retrocom\w*|retro\s*commission\w*|commission\w*)\b/', $normalized);
        $parleDAgent = preg_match('/\b(agents?|apporteur\w*|interne\w*|collaborateur\w*)\b/', $normalized);
        if (!$parleDeRetro || !$parleDAgent) {
            return null;
        }

        $args = [];
        if (preg_match('/\bagent\s*(?:n[°o]?\s*)?#?(\d+)\b/u', $normalized, $m)) {
            $args['agentId'] = (int) $m[1];
            if (preg_match('/\b(detail\w*|ligne\s*par\s*ligne|affaire\s*par\s*affaire)\b/', $normalized)) {
                $args['detail'] = 'par_ligne';
            }
        }
        if (($statut = CotationSouscriptionScope::detecterDepuisTexte($normalized)) !== null) {
            $args['statut'] = $statut;
        }

        return $args;
    }

    /**
     * Miroir exact de la garde d'execute() : tout invité peut au moins consulter SES
     * propres rétrocommissions, l'outil est donc toujours disponible. C'est le PÉRIMÈTRE
     * qui se resserre, pas la disponibilité — décrire un outil indisponible pour finir par
     * ne rendre que ses propres chiffres serait plus déroutant que de l'offrir.
     */
    public function estDisponible(AiScope $scope): bool
    {
        return true;
    }

    public function execute(array $args, AiScope $scope): AiToolResult
    {
        $statut = (string) ($args['statut'] ?? CotationSouscriptionScope::STATUT_SOUSCRITES);
        if (!CotationSouscriptionScope::estValide($statut)) {
            $statut = CotationSouscriptionScope::STATUT_SOUSCRITES;
        }

        $agentId = (int) ($args['agentId'] ?? 0);
        $detail = (string) ($args['detail'] ?? 'par_agent');

        if ($agentId > 0) {
            $agent = $this->agentDuPerimetre($agentId, $scope);
            if ($agent === null) {
                return AiToolResult::introuvable('Agent #' . $agentId);
            }
            if (!$this->peutConsulter($agent, $scope)) {
                return AiToolResult::horsPerimetre('Rétrocommissions d\'un autre agent');
            }

            return $detail === 'par_ligne'
                ? $this->parLigne($agent, $statut, $scope)
                : $this->parAgent([$agent], $statut, $scope);
        }

        return $this->parAgent($this->agentsVisibles($scope), $statut, $scope);
    }

    // ===================== Modes =====================

    /**
     * Une ligne par agent : dû, payé, solde, exigible. Les agents sans aucune
     * rétrocommission sont écartés — ils n'ont rien à dire dans ce tableau.
     *
     * @param Invite[] $agents
     */
    private function parAgent(array $agents, string $statut, AiScope $scope): AiToolResult
    {
        $items = [];
        foreach (array_slice($agents, 0, self::MAX_AGENTS) as $agent) {
            $rapport = $this->rapportBuilder->build($agent, $scope->entreprise, $statut);
            $totaux = $rapport['totaux'];
            if ($totaux['nbLignes'] === 0 && $totaux['retroAgentDue'] <= 0.0) {
                continue;
            }

            $items[] = [
                'agentId'   => $agent->getId(),
                'agent'     => $agent->getNom(),
                'affaires'  => $totaux['nbLignes'],
                'due'       => $totaux['retroAgentDue'],
                'payee'     => $totaux['retroAgentPayee'],
                'solde'     => $totaux['retroAgentSolde'],
                'exigible'  => $totaux['retroAgentExigible'],
                'commissionPure'  => $totaux['commissionPure'],
                'retroPartenaire' => $totaux['retroPartenaire'],
            ];
        }

        return AiToolResult::ok(array_filter([
            'statut' => CotationSouscriptionScope::libelle($statut),
            'items'  => $items,
            'presentation' => $items === [] ? null : Colonnes::de([
                'agent'    => Colonnes::TEXTE,
                'affaires' => Colonnes::NOMBRE,
                'due'      => Colonnes::MONTANT,
                'payee'    => Colonnes::MONTANT,
                'solde'    => Colonnes::MONTANT,
                'exigible' => Colonnes::MONTANT,
            ]),
            'note' => self::NOTE_METIER,
            'perimetre' => $this->libellePerimetre($scope),
        ], static fn ($v) => $v !== null && $v !== []));
    }

    /** Le rapport détaillé d'un agent : une ligne par affaire, comme à l'écran. */
    private function parLigne(Invite $agent, string $statut, AiScope $scope): AiToolResult
    {
        $rapport = $this->rapportBuilder->build($agent, $scope->entreprise, $statut);

        $items = [];
        foreach ($rapport['lignes'] as $ligne) {
            $items[] = [
                'avenantId'   => $ligne['avenant']?->getId(),
                'client'      => $ligne['client'],
                'police'      => $ligne['reference'],
                'risque'      => $ligne['risque'],
                'assureur'    => $ligne['assureur'],
                // Le GESTIONNAIRE : rappel explicite qu'il n'est pas le bénéficiaire.
                'gestionnaire'    => $ligne['gestionnaire'],
                'prime'           => $ligne['prime'],
                'commissionTtc'   => $ligne['commissionTtc'],
                'commissionHt'    => $ligne['commissionHt'],
                'taxeAssureur'    => $ligne['taxeAssureur'],
                'taxeCourtier'    => $ligne['taxeCourtier'],
                'commissionPure'  => $ligne['commissionPure'],
                'partageable'     => $ligne['partageable'],
                'retroPartenaire' => $ligne['retroPartenaire'],
                'due'             => $ligne['retroAgentDue'],
                'payee'           => $ligne['retroAgentPayee'],
                'solde'           => $ligne['retroAgentSolde'],
                'exigible'        => $ligne['retroAgentExigible'],
                'condition'       => $ligne['conditionNom'],
                'taux'            => $ligne['conditionTaux'],
                'eligibilite'     => $ligne['eligibiliteLibelle'],
            ];
        }

        return AiToolResult::ok(array_filter([
            'agent'  => $agent->getNom(),
            'statut' => CotationSouscriptionScope::libelle($statut),
            'projection' => $rapport['projection'] ? true : null,
            'items'  => $items,
            'presentation' => $items === [] ? null : Colonnes::de([
                'client'   => Colonnes::TEXTE,
                'police'   => Colonnes::TEXTE,
                'prime'    => Colonnes::MONTANT,
                'commissionPure' => Colonnes::MONTANT,
                'due'      => Colonnes::MONTANT,
                'payee'    => Colonnes::MONTANT,
                'solde'    => Colonnes::MONTANT,
            ]),
            // Rôles des colonnes PROMOUVABLES : chaque ligne en porte une vingtaine, six se
            // lisent d'un coup d'œil. Le modèle peut en afficher une autre à la demande,
            // avec le bon format, sans second appel.
            'colonnesDisponibles' => $items === [] ? null : self::ROLES_LIGNE,
            'colonnesDisponiblesNote' => $items === [] ? null : 'Clés déjà présentes dans chaque '
                . 'ligne : ajoute-en UNE au tableau si l\'utilisateur la demande, avec le format '
                . 'de son rôle.',
            'totaux' => $items === [] ? null : [
                'prime'           => $rapport['totaux']['prime'],
                'commissionPure'  => $rapport['totaux']['commissionPure'],
                'retroPartenaire' => $rapport['totaux']['retroPartenaire'],
                'due'             => $rapport['totaux']['retroAgentDue'],
                'payee'           => $rapport['totaux']['retroAgentPayee'],
                'solde'           => $rapport['totaux']['retroAgentSolde'],
                'exigible'        => $rapport['totaux']['retroAgentExigible'],
            ],
            'note' => self::NOTE_METIER,
        ], static fn ($v) => $v !== null && $v !== []));
    }

    /** Rôles de présentation des clés promouvables d'une ligne détaillée. */
    private const ROLES_LIGNE = [
        'gestionnaire'    => Colonnes::TEXTE,
        'risque'          => Colonnes::TEXTE,
        'assureur'        => Colonnes::TEXTE,
        'commissionTtc'   => Colonnes::MONTANT,
        'commissionHt'    => Colonnes::MONTANT,
        'taxeAssureur'    => Colonnes::MONTANT,
        'taxeCourtier'    => Colonnes::MONTANT,
        'partageable'     => Colonnes::MONTANT,
        'retroPartenaire' => Colonnes::MONTANT,
        'exigible'        => Colonnes::MONTANT,
        'condition'       => Colonnes::TEXTE,
        'taux'            => Colonnes::POURCENTAGE,
        'eligibilite'     => Colonnes::STATUT,
    ];

    /**
     * La règle du métier que ces chiffres obéissent, à joindre à toute restitution. Trois
     * confusions y sont désamorcées d'avance, chacune coûteuse.
     */
    private const NOTE_METIER = 'RÉTROCOMMISSION D\'AGENT INTERNE (pas de partenaire externe) : '
        . 'assiette = ce qui RESTE au cabinet, soit la commission pure (HT moins la taxe due par '
        . 'le courtier) MOINS les rétrocommissions des partenaires externes. Le taux de la '
        . 'condition de partage s\'y applique. '
        . 'L\'agent BÉNÉFICIAIRE n\'est PAS le gestionnaire de l\'affaire : il peut l\'avoir '
        . 'apportée puis en confier le suivi à un collègue — ne jamais déduire l\'un de l\'autre. '
        . '« due » naît à la souscription ; « exigible » n\'est réclamable qu\'une fois la '
        . 'commission encaissée par le cabinet — ne propose jamais de verser un montant non '
        . 'exigible. Les affaires « en attente » sont des PROJECTIONS : aucun montant n\'y est dû. '
        . 'Ce circuit ne passe par AUCUNE note de débit ou de crédit : le versement est direct, '
        . 'comptabilisé en charges de personnel (SYSCOHADA 6611).';

    // ===================== Périmètre =====================

    /**
     * Les agents que l'invité connecté a le droit de voir : tous les bénéficiaires du
     * cabinet s'il gère les invités, lui seul sinon.
     *
     * @return Invite[]
     */
    private function agentsVisibles(AiScope $scope): array
    {
        if (!$this->accessResolver->canManageInvites($scope->invite)) {
            return [$scope->invite];
        }

        return $this->inviteRepository->findBy(['entreprise' => $scope->entreprise], ['nom' => 'ASC']);
    }

    /** L'agent doit exister DANS l'entreprise du scope : scoping strict. */
    private function agentDuPerimetre(int $id, AiScope $scope): ?Invite
    {
        return $this->inviteRepository->findOneBy(['id' => $id, 'entreprise' => $scope->entreprise]);
    }

    /** Soi-même toujours ; un autre agent seulement si gestionnaire d'invités. */
    private function peutConsulter(Invite $agent, AiScope $scope): bool
    {
        return $agent->getId() === $scope->invite->getId()
            || $this->accessResolver->canManageInvites($scope->invite);
    }

    private function libellePerimetre(AiScope $scope): string
    {
        return $this->accessResolver->canManageInvites($scope->invite)
            ? 'Tous les agents du cabinet'
            : 'Vos propres rétrocommissions';
    }
}
