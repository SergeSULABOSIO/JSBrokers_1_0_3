<?php

namespace App\Ai\Tool;

use App\Ai\AiText;
use App\Ai\Scope\AiScope;
use App\Entity\DemandeConge;
use App\Entity\Invite;
use App\Repository\DemandeCongeRepository;
use App\Service\Conge\CalculateurSolde;
use App\Service\Conge\DemandeCongePolicy;
use App\Service\Conge\ResolveurDAgent;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Services\Search\CongeStatutScope;

/**
 * LA PHOTO D'ENSEMBLE DES CONGÉS — la réponse à « où en suis-je ? ».
 *
 * ── UN SEUL APPEL, ET NON QUATRE ────────────────────────────────────────────────────
 * Solde, demandes en cours, prochaines absences et file d'attente du valideur reviennent
 * ENSEMBLE. Découpés en quatre outils, ils auraient coûté quatre tours de function
 * calling pour une seule question — et l'API du fournisseur étant sans mémoire, ces
 * quatre tours auraient réexpédié tout le contexte à chaque fois. Le découpage technique
 * ne doit pas se retrouver sur la facture du cabinet.
 *
 * ── AUCUNE RÈGLE MÉTIER ICI ─────────────────────────────────────────────────────────
 * L'outil valide ses paramètres, appelle les services et met en forme. Le solde vient de
 * CalculateurSolde, les droits de DemandeCongePolicy, le vocabulaire des statuts de
 * CongeStatutScope — les mêmes que l'écran. Rien à corriger deux fois.
 *
 * ── LE PÉRIMÈTRE EST CELUI DE L'ÉCRAN ───────────────────────────────────────────────
 * Un collaborateur qui n'est pas valideur ne voit que SES demandes, ici comme dans la
 * rubrique. Demander le solde d'un collègue lui est refusé — les données de congé sont
 * des données personnelles.
 */
final class CongesTool implements AiToolInterface
{
    /** Nombre de demandes rendues par section : au-delà, on noie la réponse. */
    private const MAX_LIGNES = 15;

    /** Fenêtre des « prochaines absences » de l'équipe, en jours. */
    private const HORIZON_EQUIPE = 60;

    public function __construct(
        private readonly CalculateurSolde $calculateurSolde,
        private readonly DemandeCongePolicy $policy,
        private readonly DemandeCongeRepository $demandeRepository,
        private readonly ResolveurDAgent $resolveurAgent,
        private readonly WorkspaceAccessResolver $accessResolver,
    ) {
    }

    public function name(): string
    {
        return 'conges';
    }

    public function description(): string
    {
        return "Photo d'ensemble des CONGÉS d'un collaborateur : solde de l'exercice (acquis, dont "
            . 'report, consommé, engagé, disponible), ses demandes en cours, ses prochaines absences, '
            . "et — pour un valideur — la file des demandes en attente de décision ainsi que les "
            . "absences de l'équipe à venir. À appeler pour « mes congés », « mon solde de congés », "
            . '« combien de jours me reste-t-il », « qui est en congé », « quelles demandes attendent '
            . "ma validation », « les congés de X ». Un seul appel suffit : tout revient ensemble. "
            . "Sans paramètre, répond pour l'utilisateur connecté et l'exercice en cours.";
    }

    public function aiguillage(): string
    {
        return 'Congés, absences, solde de jours, jours restants, qui est absent, demandes à valider. '
            . "Appelle-moi UNE FOIS : je renvoie le solde, les demandes et la file d'attente ensemble. "
            . "Restitue les nombres tels que je les donne, sans les recalculer.";
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'agent' => [
                    'type' => 'string',
                    'description' => "Nom du collaborateur concerné. Omets-le pour l'utilisateur connecté.",
                ],
                'exercice' => [
                    'type' => 'integer',
                    'description' => "Année civile de référence. Par défaut, l'année en cours.",
                ],
                'statut' => [
                    'type' => 'string',
                    'enum' => array_keys(CongeStatutScope::VALEURS),
                    'description' => 'Ne renvoyer que les demandes de ce statut.',
                ],
            ],
        ];
    }

    public function match(string $question, AiScope $scope): ?array
    {
        $normalized = AiText::normalize($question);

        if (preg_match('/\bconges?\b/', $normalized)
            || preg_match('/\babsences?\b/', $normalized)
            || preg_match('/\bjours? (restants?|de conge)\b/', $normalized)) {
            return [];
        }

        return null;
    }

    public function execute(array $args, AiScope $scope): AiToolResult
    {
        // SÉCURITÉ DANS L'OUTIL, JAMAIS DANS LE PROMPT (fail-closed).
        if (!$this->accessResolver->canRead($scope->invite, 'DemandeConge')) {
            return AiToolResult::horsPerimetre('Congés');
        }

        $estValideur = $this->policy->estValideur($scope->invite);
        $resolution = $this->resolveurAgent->resoudre(
            $scope->entreprise,
            isset($args['agent']) ? (string) $args['agent'] : null,
            $scope->invite,
        );

        if ($resolution['introuvable']) {
            return AiToolResult::introuvable(
                sprintf('collaborateur « %s »', trim((string) ($args['agent'] ?? ''))),
                "Aucun collaborateur de ce nom dans ce cabinet. Dis-le en une phrase et demande "
                . "l'orthographe exacte. N'invente pas de solde.",
            );
        }

        if ($resolution['ambigu'] !== []) {
            return AiToolResult::ok([
                'pret' => false,
                'ambigu' => array_map(static fn (Invite $i) => (string) $i->getNom(), $resolution['ambigu']),
                'note' => "Plusieurs collaborateurs portent ce nom. Demande LEQUEL, en une ligne, "
                    . 'puis rappelle-moi au message suivant avec le nom complet.',
            ]);
        }

        /** @var Invite $agent */
        $agent = $resolution['agent'];

        // LE PÉRIMÈTRE DE L'ÉCRAN, À L'IDENTIQUE. Un collaborateur qui n'est pas valideur
        // ne voit que ses propres demandes : les congés sont des données personnelles, et
        // l'assistant n'est pas une porte dérobée.
        if ($agent->getId() !== $scope->invite->getId() && !$estValideur) {
            return AiToolResult::horsPerimetre('Congés des autres collaborateurs');
        }

        $exercice = isset($args['exercice']) ? (int) $args['exercice'] : null;
        $solde = $this->calculateurSolde->pour($agent, $exercice);

        $statuts = isset($args['statut']) && CongeStatutScope::estValide((string) $args['statut'])
            ? [(string) $args['statut']]
            : [];

        $data = [
            'agent' => (string) $agent->getNom(),
            'estValideur' => $estValideur,
            'solde' => $solde->toArray(),
            'demandes' => array_map(
                $this->resumer(...),
                $this->demandeRepository->pourAgent($agent, $statuts, self::MAX_LIGNES),
            ),
            'prochainesAbsences' => array_map(
                $this->resumer(...),
                $this->prochainesAbsences($agent),
            ),
        ];

        // LA FILE D'ATTENTE N'A DE SENS QUE POUR QUI PEUT DÉCIDER. La montrer à un
        // collaborateur ordinaire lui exposerait les absences de toute l'équipe.
        if ($estValideur) {
            $data['fileDAttente'] = array_map(
                fn (DemandeConge $d) => $this->resumerPourValideur($d, $scope->invite),
                $this->demandeRepository->fileDAttente($scope->entreprise, self::MAX_LIGNES),
            );
            $data['equipeAbsenteProchainement'] = array_map(
                $this->resumer(...),
                $this->demandeRepository->absencesApprouveesSurPeriode(
                    $scope->entreprise,
                    new \DateTimeImmutable('today'),
                    (new \DateTimeImmutable('today'))->modify(sprintf('+%d days', self::HORIZON_EQUIPE)),
                    $agent,
                ),
            );
        }

        $data['note'] = "Les nombres ci-dessus sont ceux de l'écran : restitue-les tels quels, sans "
            . "arrondir ni recalculer. Le « disponible » a déjà l'engagé déduit — ne le soustrais pas "
            . 'une seconde fois.';

        return AiToolResult::ok($data);
    }

    /**
     * Les absences approuvées de l'agent qui n'ont pas encore commencé, ou qui courent
     * encore : c'est ce que « mes prochaines absences » veut dire.
     *
     * @return DemandeConge[]
     */
    private function prochainesAbsences(Invite $agent): array
    {
        $aujourdhui = new \DateTimeImmutable('today');

        return array_values(array_filter(
            $this->demandeRepository->pourAgent($agent, [DemandeConge::STATUT_APPROUVEE], self::MAX_LIGNES),
            static fn (DemandeConge $d) => $d->getDateFin() !== null && $d->getDateFin() >= $aujourdhui,
        ));
    }

    /** @return array<string, mixed> */
    private function resumer(DemandeConge $demande): array
    {
        return [
            'id' => $demande->getId(),
            'agent' => (string) ($demande->getAgent()?->getNom() ?? ''),
            'type' => (string) ($demande->getTypeAbsence() ?? ''),
            'du' => $demande->getDateDebut()?->format('d/m/Y'),
            'au' => $demande->getDateFin()?->format('d/m/Y'),
            'jours' => $demande->nbJoursFloat(),
            'statut' => CongeStatutScope::libelle($demande->getStatut()),
            'decompte' => $demande->estDecomptee(),
        ];
    }

    /**
     * Pour le valideur, une ligne de file porte EN PLUS le solde de celui qui demande :
     * approuver dix jours à quelqu'un qui n'en a plus que trois se répare mal.
     *
     * @return array<string, mixed>
     */
    private function resumerPourValideur(DemandeConge $demande, Invite $acteur): array
    {
        $resume = $this->resumer($demande);
        $agent = $demande->getAgent();

        $resume['soldeDisponibleDeLAgent'] = $agent !== null
            ? $this->calculateurSolde->pour($agent, $demande->getExercice())->disponible()
            : null;
        // NUL NE VALIDE SA PROPRE DEMANDE. Le dire ici évite au modèle de proposer un
        // geste qui sera de toute façon refusé — et une proposition refusée derrière un
        // bouton se lit comme une panne.
        $resume['vousPouvezDecider'] = $this->policy->peutDecider($acteur, $demande);

        return $resume;
    }
}
