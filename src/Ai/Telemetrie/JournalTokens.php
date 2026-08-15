<?php

namespace App\Ai\Telemetrie;

use App\Ai\AiRequest;
use App\Ai\Mutation\OutilsDePlan;
use App\Ai\Trousse\Phase;
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
     * Corrélation. UN TRAITEMENT = un message de l'utilisateur : l'état ci-dessous
     * vit donc le temps d'un message et rattache ses lignes « tour » à sa ligne
     * « message ». Sans lui, deux messages traités coup sur coup produiraient des
     * tours entrelacés et inexploitables.
     *
     * ⚠️ « UN TRAITEMENT », ET NON PLUS « UNE REQUÊTE HTTP ». La nuance n'était
     * pas visible tant que chaque message naissait d'une requête neuve, qui
     * apportait son conteneur neuf. Un worker, lui, VIT : il enchaîne les
     * messages dans le même processus, donc avec le même service. C'est pourquoi
     * le handler appelle nouveauMessage() en tête de chaque tâche — sans quoi les
     * compteurs de la précédente contamineraient le récapitulatif de la suivante.
     *
     * Ces compteurs servent aussi au rattrapage d'échec : quand le moteur meurt
     * sur un 429, personne ne sait plus combien de tours avaient déjà été payés.
     */
    private ?string $messageId = null;
    private int $toursEmis = 0;
    private int $cumulEntree = 0;
    private int $cumulSortie = 0;

    /**
     * FIL D'ACTIVITÉ — l'abonné qui suit le message PENDANT qu'il se déroule.
     *
     * POURQUOI ICI, et pas dans le moteur. Le navigateur restait aveugle de bout en
     * bout : un fetch bloquant de vingt à quarante secondes, et un « Ket réfléchit… »
     * figé pendant que trois appels au modèle et une exécution d'outils s'enchaînaient.
     * Une attente aussi longue sans explication se lit comme une panne.
     *
     * Ce journal est le SEUL endroit par où passent déjà toutes les mesures : y
     * greffer l'abonné évite de recâbler quoi que ce soit ailleurs, et surtout donne
     * la bonne dégradation sans écrire une seule garde — un moteur qui ne sollicite
     * pas le journal (le simulé, Anthropic) n'émet rien, et l'affichage retombe de
     * lui-même sur l'indicateur d'avant.
     *
     * Nul par défaut : hors requête en flux, tout ce mécanisme est inerte.
     */
    private ?\Closure $echo = null;

    /**
     * Compteurs du FIL, délibérément distincts de $cumulEntree/$cumulSortie.
     *
     * Les seconds servent la campagne de mesure et sont relus par messageInterrompu() :
     * les mêler à un affichage fausserait des chiffres sur lesquels on tranche des
     * décisions d'architecture. Ceux-ci ne servent qu'à écrire une ligne à l'écran.
     */
    private int $cumulIa = 0;
    /** Coût du dernier appel au modèle, pas encore montré à l'utilisateur. */
    private int $dernierAppel = 0;
    /** @var list<array{cle: string, jetons: int}> les étapes déjà annoncées, pour le récapitulatif */
    private array $etapes = [];
    private int $appels = 0;

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
        $this->cumulIa = 0;
        $this->dernierAppel = 0;
        $this->etapes = [];
        $this->appels = 0;
    }

    /**
     * Branche (ou débranche, avec null) l'abonné du fil d'activité.
     *
     * SETTER, et non un argument de constructeur : le journal est un service partagé
     * que quarante appelants reçoivent sans rien demander. Le passer au constructeur
     * obligerait à le décrire partout, et casserait au passage les tests qui
     * l'instancient à la main.
     *
     * @param \Closure(array{cle: string, tokensEtape: int, tokensCumul: int}): void|null $abonne
     */
    public function ecouter(?\Closure $abonne): void
    {
        $this->echo = $abonne;
    }

    /**
     * Une phase DÉMARRE.
     *
     * Seul point d'accroche « avant » de ce journal, et il est indispensable : tout le
     * reste ne se mesure qu'APRÈS le retour du fournisseur. Annoncer la rédaction
     * depuis sa propre ligne « tour » l'annoncerait une fois FINIE — or c'est
     * précisément pendant qu'elle tourne que l'utilisateur attend sans rien voir.
     */
    public function debutDePhase(Phase $phase): void
    {
        $this->annoncer($phase->libelle());
    }

    /**
     * Ce que le message aura coûté, tel qu'on peut le montrer sous la réponse.
     *
     * Null quand rien n'a été annoncé : le moteur simulé et Anthropic ne passent pas
     * par ici, et il vaut mieux ne rien afficher qu'afficher des zéros.
     *
     * @return array{appels: int, jetonsIa: int, etapes: list<array{cle: string, jetons: int}>}|null
     */
    public function recapitulatif(): ?array
    {
        if ($this->etapes === []) {
            return null;
        }

        // Le DERNIER appel du message n'est suivi d'aucune annonce — plus rien ne
        // commence après la rédaction. Son coût reviendrait donc à l'étape courante,
        // et sans cette ligne le détail ne totaliserait pas le montant affiché.
        $etapes = $this->etapes;
        if ($this->dernierAppel > 0) {
            $etapes[array_key_last($etapes)]['jetons'] += $this->dernierAppel;
        }

        return [
            'appels'   => $this->appels,
            'jetonsIa' => $this->cumulIa,
            'etapes'   => $etapes,
        ];
    }

    /**
     * Pousse une étape vers l'abonné, et la retient pour le récapitulatif.
     *
     * Le coût annoncé est celui du DERNIER appel au modèle, remis à zéro aussitôt :
     * sans cela, une étape qui ne consomme rien (l'exécution locale des outils)
     * réafficherait le montant de la précédente, et l'utilisateur croirait payer deux
     * fois la même chose.
     */
    private function annoncer(string $cle): void
    {
        $jetons = $this->dernierAppel;
        $this->dernierAppel = 0;

        // ATTRIBUTION. Ce qui vient d'être payé l'a été par l'étape PRÉCÉDENTE :
        // une phase s'annonce avant de partir, et son coût n'est connu qu'au retour.
        // Le récapitulatif doit donc reculer d'un cran, sinon il affiche « rédige la
        // réponse — 0 jeton » pour un appel qui en a coûté six mille (constaté en
        // conditions réelles le 2026-08-14 : 46 220 jetons facturés, 39 752 répartis).
        if ($jetons > 0 && $this->etapes !== []) {
            $this->etapes[array_key_last($this->etapes)]['jetons'] += $jetons;
        }
        $this->etapes[] = ['cle' => $cle, 'jetons' => 0];

        if ($this->echo === null) {
            return;
        }

        // Le fil EN DIRECT, lui, annonce ce qui vient d'être payé à l'instant : c'est
        // la seule chose qu'on puisse dire honnêtement quand l'étape qui commence
        // n'a encore rien coûté.
        ($this->echo)([
            'cle'         => $cle,
            'tokensEtape' => $jetons,
            'tokensCumul' => $this->cumulIa,
        ]);
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

        // FIL D'ACTIVITÉ. Entrée ET sortie : l'utilisateur veut savoir ce que l'échange
        // a coûté, pas ce qu'a coûté la moitié montante.
        ++$this->appels;
        $this->dernierAppel = ($tokens['entree'] ?? 0) + ($tokens['sortie'] ?? 0);
        $this->cumulIa += $this->dernierAppel;

        // Des outils sont partis : Symfony va les exécuter localement, sans rien
        // demander à personne. C'est le silence le plus long du message, et le seul
        // qu'on puisse nommer honnêtement — d'où une étape à lui, qui n'est PAS une
        // phase (Phase déclare qu'il n'y en aura jamais une quatrième).
        if ($outils !== []) {
            $this->annoncer('outils');
        }
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

        // FIL D'ACTIVITÉ. Comprehenseur::facturer() ne remonte que les tokens
        // d'ENTRÉE : le total affiché sous-estime cette phase d'une centaine de
        // jetons. On le signale plutôt que de le corriger — inventer une sortie
        // qu'on n'a pas mesurée serait pire qu'un écart connu.
        if ($tokens > 0) {
            ++$this->appels;
            $this->dernierAppel = $tokens;
            $this->cumulIa += $tokens;
        }

        // Demande jugée ambiguë : le message s'arrête sur une reformulation à
        // confirmer. Ni planification ni rédaction ne suivront — l'utilisateur doit
        // lire autre chose que « prépare le travail… » avant que la ligne s'efface.
        if ($clarte !== 'claire') {
            $this->annoncer('clarification');
        }
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
