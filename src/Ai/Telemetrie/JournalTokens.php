<?php

namespace App\Ai\Telemetrie;

use App\Ai\AiRequest;
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
    /** Boucle arrêtée par le budget d'entrée, avant le 429 (cf. GeminiAiEngine). */
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
    ) {
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
