<?php

namespace App\Ai\Telemetrie;

use App\Ai\AiRequest;
use App\Ai\Mutation\OutilsDePlan;
use Psr\Log\LoggerInterface;

/**
 * Télémétrie des tokens consommés par l'assistant IA, sur son canal Monolog
 * dédié (« assistant_tokens », une ligne JSON par enregistrement).
 *
 * POURQUOI. L'API generateContent est SANS ÉTAT : chaque tour de function
 * calling réexpédie l'intégralité du contexte (prompt système + déclarations
 * d'outils + historique). Le palier gratuit plafonnant les tokens d'ENTRÉE à
 * 250 000 par minute et par modèle, une poignée de tours suffit à saturer — et
 * l'on ne savait pas dire lequel des deux leviers (moins de tours, ou un
 * contexte plus léger) valait l'effort. Ce journal existe pour trancher sur des
 * chiffres plutôt que sur une intuition.
 *
 * Deux granularités, parce que les deux questions sont différentes :
 *  - TOUR    : ce que coûte un aller-retour, et comment ce coût se répartit
 *              entre prompt système, déclarations d'outils et historique ;
 *  - MESSAGE : ce que coûte une question de l'utilisateur au total, en combien
 *              de tours, et comment elle s'est terminée (cf. les ISSUE_*).
 *
 * Le pic réellement fatal se lit en agrégeant les lignes TOUR par minute
 * glissante, toutes conversations et tous invités confondus : le quota est
 * partagé, pas individuel. C'est le travail d'app:assistant:tokens:rapport.
 *
 * COÛT NUL : rien n'est demandé au fournisseur. Les tokens proviennent du bloc
 * usageMetadata que la réponse porte déjà, les octets d'un strlen local.
 */
final class JournalTokens
{
    /** Le modèle a rendu sa réponse (avec ou sans outil). */
    public const ISSUE_REPONSE = 'reponse';
    /**
     * Boucle arrêtée faute de débit disponible sur la minute, avant le 429
     * (cf. GeminiAiEngine + BudgetDebit). Nom conservé depuis l'époque du cap
     * par message : les journaux d'avant et d'après doivent rester comparables,
     * c'est tout l'intérêt de la campagne.
     */
    public const ISSUE_BUDGET_ATTEINT = 'budget_atteint';
    /** 429 du fournisseur : quota d'entrée par minute épuisé. Levé hors du moteur. */
    public const ISSUE_QUOTA_FOURNISSEUR = 'quota_fournisseur';
    /** Prompt ou réponse bloqué par les garde-fous du fournisseur. */
    public const ISSUE_BLOCAGE_SECURITE = 'blocage_securite';
    /** MAX_TOOL_ROUNDS atteint sans que le modèle conclue. */
    public const ISSUE_TOURS_EPUISES = 'tours_epuises';
    /** Échec technique autre (réseau, clé, 5xx). */
    public const ISSUE_ECHEC_TECHNIQUE = 'echec_technique';

    /**
     * La phase de compréhension a jugé la demande ambiguë : le message s'arrête sur
     * une reformulation à confirmer, sans planification ni rédaction.
     *
     * Issue à part entière, et c'est tout l'intérêt : c'est elle qui dira si le
     * comprenant rend service ou s'il est devenu un questionneur compulsif. Au-delà
     * d'environ 15 % des messages, le réglage est mauvais.
     */
    public const ISSUE_CLARIFICATION = 'clarification';

    /**
     * Corrélation. Une requête HTTP = un message de l'utilisateur : l'état
     * ci-dessous vit donc le temps d'un message et rattache ses lignes « tour »
     * à sa ligne « message ». Sans lui, deux utilisateurs écrivant en même
     * temps produiraient des tours entrelacés et inexploitables.
     *
     * Il sert aussi au contrôleur : quand le moteur meurt sur un 429, personne
     * ne sait plus combien de tours avaient déjà été payés — ces compteurs, si.
     */
    private ?string $messageId = null;
    private int $toursEmis = 0;
    private int $cumulEntree = 0;
    private int $cumulSortie = 0;

    public function __construct(
        // Canal dédié : MonologBundle câble ici le logger « assistant_tokens »
        // d'après le nom de l'argument (convention <channel>Logger).
        private readonly LoggerInterface $assistantTokensLogger,
        // Les outils d'écriture sont DÉRIVÉS du code (marqueur AiToolProduisantUnPlan) :
        // la classification en parcours ne peut donc pas oublier un outil ajouté plus
        // tard, contrairement à une liste recopiée ici.
        private readonly OutilsDePlan $outilsDePlan,
    ) {
    }

    /**
     * PARCOURS auquel appartient un message, dérivé de sa séquence d'outils.
     *
     * Sans cette clé, l'avant/après du chantier n'est pas lisible : la campagne du
     * 2026-08-08/09 a montré que quatre groupes se comportent très différemment —
     * un seul (la saisie) portait 71 % des tokens et 12 des 13 saturations. Agréger
     * tous les messages ensemble noierait précisément ce qu'on cherche à corriger.
     *
     * @param list<string> $sequenceOutils
     */
    private function parcours(array $sequenceOutils): string
    {
        if ($sequenceOutils === []) {
            return 'aucun';
        }
        // Le chemin rapide se reconnaît à son outil : un seul appel, tout le parcours.
        if (in_array('saisir_proposition', $sequenceOutils, true)) {
            return 'saisie_directe';
        }
        if (array_intersect($sequenceOutils, $this->outilsDePlan->noms()) !== []) {
            return 'saisie_guidee';
        }
        // Préparation d'une saisie qui n'a pas abouti à un plan dans ce message.
        if (array_intersect($sequenceOutils, ['inventaire_champs', 'parcours_saisie']) !== []) {
            return 'saisie_amorcee';
        }

        return 'lecture';
    }

    /** Ouvre la mesure d'un nouveau message utilisateur (appelé par le moteur). */
    public function nouveauMessage(): void
    {
        $this->messageId = bin2hex(random_bytes(6));
        $this->toursEmis = 0;
        $this->cumulEntree = 0;
        $this->cumulSortie = 0;
    }

    /**
     * Un aller-retour HTTP avec le fournisseur.
     *
     * @param array<string, int>    $tokens  entree / sortie / cache
     * @param array<string, int>    $octets  system / outils / historique
     * @param list<string>          $outils  outils appelés par le modèle à ce tour
     */
    public function tour(
        AiRequest $request,
        string $moteur,
        string $modele,
        int $tour,
        array $tokens,
        array $octets,
        array $outils = [],
    ): void {
        ++$this->toursEmis;
        $this->cumulEntree += $tokens['entree'] ?? 0;
        $this->cumulSortie += $tokens['sortie'] ?? 0;

        $this->assistantTokensLogger->info('tour', $this->identite($request) + [
            'evenement'        => 'tour',
            'moteur'           => $moteur,
            'modele'           => $modele,
            'tour'             => $tour,
            'tokensEntree'     => $tokens['entree'] ?? 0,
            'tokensSortie'     => $tokens['sortie'] ?? 0,
            // > 0 quand le cache implicite du fournisseur s'est activé. Ces
            // tokens comptent MALGRÉ TOUT dans le quota (le cache allège la
            // facture, jamais la limite de débit) : la colonne sert à mesurer
            // l'économie financière, pas à espérer un desserrement du plafond.
            'tokensCache'      => $tokens['cache'] ?? 0,
            'octetsSysteme'    => $octets['systeme'] ?? 0,
            'octetsOutils'     => $octets['outils'] ?? 0,
            'octetsHistorique' => $octets['historique'] ?? 0,
            'outils'           => $outils,
        ]);
    }

    /**
     * La décision d'AIGUILLAGE d'un message : quelle trousse, d'où vient la
     * décision, ce qu'elle a coûté et combien de temps elle a pris.
     *
     * La décision est prise par le SERVEUR (tokens et millisecondes à zéro) :
     * l'interroger coûterait un troisième appel, que la règle interdit. Cette ligne
     * reste néanmoins utile — sa part de « lecture » dit l'économie réellement
     * réalisée sur le terrain, et c'est elle qui dira si la sélection déterministe
     * se trompe souvent.
     */
    public function routage(
        AiRequest $request,
        string $moteur,
        string $trousse,
        string $origine,
        int $tokens,
        int $millisecondes,
    ): void {
        $this->assistantTokensLogger->info('routage', $this->identite($request) + [
            'evenement'     => 'routage',
            'moteur'        => $moteur,
            'trousse'       => $trousse,
            'origine'       => $origine,
            'tokens'        => $tokens,
            'millisecondes' => $millisecondes,
        ]);
    }

    /**
     * La phase de COMPRÉHENSION d'un message : ce qu'elle a conclu, d'où venait la
     * décision, ce qu'elle a coûté et combien de temps elle a pris.
     *
     * Ligne jumelle de {@see routage()}, et pour la même raison : une décision prise
     * avant le travail ne laisse aucune trace dans les tours, et sans elle on ne
     * saurait pas distinguer un fil qui coule (beaucoup de « court-circuit », zéro
     * token) d'un comprenant en panne silencieuse (beaucoup de « repli », zéro token
     * lui aussi). Ce sont ces deux colonnes — clarté et origine — qui diront si le
     * troisième appel valait la peine d'être ajouté.
     */
    public function comprehension(
        AiRequest $request,
        string $modele,
        string $clarte,
        string $origine,
        int $tokens,
        int $millisecondes,
    ): void {
        $this->assistantTokensLogger->info('comprehension', $this->identite($request) + [
            'evenement'     => 'comprehension',
            'modele'        => $modele,
            'clarte'        => $clarte,
            'origine'       => $origine,
            'tokens'        => $tokens,
            'millisecondes' => $millisecondes,
        ]);
    }

    /**
     * Le moteur a PATIENTÉ avant de relancer un tour, le temps que la fenêtre
     * d'une minute se libère, plutôt que d'abandonner une chaîne déjà payée.
     *
     * À surveiller de près : quelques attentes brèves valent bien mieux qu'un
     * refus, mais une attente fréquente ou longue signale que le plafond est
     * réellement trop bas pour l'usage — c'est le signal qui doit déclencher la
     * bascule vers le palier payant, pas une impression.
     */
    public function attente(
        AiRequest $request,
        string $moteur,
        string $modele,
        int $tour,
        int $secondes,
        int $tokensEstimes,
    ): void {
        $this->assistantTokensLogger->info('attente', $this->identite($request) + [
            'evenement'     => 'attente',
            'moteur'        => $moteur,
            'modele'        => $modele,
            'tour'          => $tour,
            'secondes'      => $secondes,
            'tokensEstimes' => $tokensEstimes,
        ]);
    }

    /**
     * Bilan d'UN message de l'utilisateur, tous tours confondus.
     *
     * @param list<string>         $sequenceOutils outils enchaînés, dans l'ordre
     * @param array<string, mixed> $complement     détail libre (ex. quota violé sur un 429)
     */
    public function message(
        AiRequest $request,
        string $moteur,
        string $modele,
        string $issue,
        int $tours,
        int $cumulEntree,
        int $cumulSortie = 0,
        array $sequenceOutils = [],
        array $complement = [],
    ): void {
        $this->assistantTokensLogger->info('message', $this->identite($request) + [
            'evenement'      => 'message',
            'moteur'         => $moteur,
            'modele'         => $modele,
            'issue'          => $issue,
            'tours'          => $tours,
            'cumulEntree'    => $cumulEntree,
            'cumulSortie'    => $cumulSortie,
            'sequenceOutils' => $sequenceOutils,
            'parcours'       => $this->parcours($sequenceOutils),
        ] + ($complement !== [] ? ['complement' => $complement] : []));
    }

    /**
     * Bilan d'un message que le moteur n'a PAS pu conclure lui-même : le 429 du
     * fournisseur et les pannes techniques remontent jusqu'au contrôleur, qui
     * n'a aucune idée du nombre de tours déjà consommés. Les compteurs internes
     * le savent — et ces tours-là ont bel et bien été payés, donc ils doivent
     * figurer dans la campagne.
     *
     * @param array<string, mixed> $complement ex. le quota violé, lu dans le corps du 429
     */
    public function messageInterrompu(
        AiRequest $request,
        string $moteur,
        string $modele,
        string $issue,
        array $complement = [],
    ): void {
        $this->message(
            $request,
            $moteur,
            $modele,
            $issue,
            $this->toursEmis,
            $this->cumulEntree,
            $this->cumulSortie,
            complement: $complement,
        );
    }

    /**
     * Identité de la requête. L'horodatage est posé ICI plutôt que laissé au
     * formateur : le rapport calcule des fenêtres d'une minute, il lui faut une
     * date explicite et stable, indépendante de la configuration de Monolog.
     * L'horloge applicative est fixée au boot du noyau (APP_TIMEZONE).
     *
     * @return array<string, int|string|null>
     */
    private function identite(AiRequest $request): array
    {
        $scope = $request->scope;

        return [
            'horodatage'   => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'messageId'    => $this->messageId,
            'conversation' => $scope->conversation?->getId(),
            'invite'       => $scope->invite->getId(),
            'entreprise'   => $scope->entreprise->getId(),
        ];
    }
}
