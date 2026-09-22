<?php

namespace App\Ai\Engine;

use App\Ai\AiContextBuilder;
use App\Ai\AiReply;
use App\Ai\AiRequest;
use App\Ai\Comprehension\Comprehenseur;
use App\Ai\Debit\BudgetDebit;
use App\Ai\Fournisseur\MemoireDEpuisement;
use App\Ai\Engine\Socle\DialecteAnthropicDuFil;
use App\Ai\Engine\Socle\OrchestrateurDeMessage;
use App\Ai\Mutation\OutilsDePlan;
use App\Ai\Redaction\RepliPrecis;
use App\Ai\Telemetrie\JournalTokens;
use App\Ai\Tool\ExecuteurDOutils;
use App\Ai\Trousse\Phase;
use App\Ai\Trousse\SelecteurDeTrousse;
use App\Ai\Trousse\Trousse;
use App\Ai\Trousse\TrousseCatalogue;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Moteur réel : API Claude (Anthropic Messages API) via symfony/http-client,
 * avec tool-calling natif.
 *
 * CE FICHIER NE CONTIENT PLUS LE TRAVAIL, seulement le câblage. Comprendre la
 * demande, choisir la trousse, mener les phases, exécuter les outils, mesurer,
 * conclure : tout cela vit dans Socle\OrchestrateurDeMessage, le MÊME que celui
 * du moteur Gemini. Le format du fil et le transport vivent dans
 * Socle\DialecteAnthropicDuFil.
 *
 * CE QUE CE BRANCHEMENT RÉPARE. Tant que ce moteur menait sa propre boucle, il
 * lui manquait onze choses que l'autre avait : les phases (il envoyait le prompt
 * PLEIN et TOUS les outils aux deux appels), la compréhension, le choix de la
 * trousse, la télémétrie, le compteur de débit, le mode Live, les chiffres des
 * outils — donc le garde-fou anti-montant inventé —, la décision en attente, et
 * le rattrapage de l'appel écrit en prose. Aucun de ces écarts n'était visible :
 * ce moteur ne tournait pas. Mais ANTHROPIC_API_KEY est PRIORITAIRE dans
 * AiEngineResolver, et une simple clé posée suffisait à tous les ouvrir d'un coup.
 *
 * SÉCURITÉ : inchangée — le périmètre ne dépend PAS du modèle, chaque outil
 * re-vérifie canRead() dans execute() (fail-closed). Le prompt système ne fait
 * qu'énoncer la politesse du refus ; la garde est dans le code.
 */
final class AnthropicAiEngine implements MoteurDeTexte
{
    /**
     * Plafond de SORTIE par appel.
     *
     * 4 096 auparavant, calé sur Gemini. Deux raisons de le relever ici, aucune
     * de le laisser bas : le plafond de sortie par minute d'Anthropic (400 000)
     * n'est jamais le facteur limitant pour deux ou trois appels par message, et
     * « max_tokens » n'entre PAS dans le calcul de ce plafond — une valeur haute
     * ne coûte donc rien tant que le modèle n'écrit pas jusque-là. Ce qu'on
     * gagne, c'est de ne plus tronquer une page de liste au milieu d'une phrase.
     */
    private const MAX_OUTPUT_TOKENS = 16000;

    /**
     * Attente maximale avant de renoncer à un réessai, sur un délai annoncé par le
     * fournisseur. Même borne que chez Gemini, et pour la même raison : au-delà
     * d'une quinzaine de secondes, l'utilisateur préfère une réponse honnête à un
     * silence.
     */
    private const MAX_ATTENTE_SECONDES = 15;

    /**
     * Attente après un refus de SURCHARGE (5xx / 529), où le fournisseur n'annonce
     * aucun délai. Court : ces épisodes sont brefs, et si celui-ci ne l'est pas, un
     * second échec le dira mieux qu'une longue attente.
     */
    private const ATTENTE_SURCHARGE_SECONDES = 2;

    private readonly OrchestrateurDeMessage $orchestrateur;

    private readonly DialecteAnthropicDuFil $fil;

    private readonly bool $cleEstPosee;

    private readonly ?MemoireDEpuisement $epuisement;

    private readonly string $cleDEpuisement;

    public function __construct(
        HttpClientInterface $httpClient,
        AiContextBuilder $contextBuilder,
        // Même source que le prompt système : les deux doivent être dérivés du même
        // tableau d'outils, sans quoi une consigne peut nommer un outil non déclaré.
        TrousseCatalogue $trousseCatalogue,
        SelecteurDeTrousse $selecteur,
        // Le seul chemin vers le code métier, partagé avec l'autre moteur et avec la
        // phase de compréhension.
        ExecuteurDOutils $executeur,
        #[Autowire(env: 'ANTHROPIC_API_KEY')] string $apiKey,
        #[Autowire(env: 'ANTHROPIC_MODEL')] string $model,
        LoggerInterface $logger,
        JournalTokens $journal,
        BudgetDebit $budget,
        // Rédige en PHP, à coût nul, ce que le modèle n'a pas rédigé.
        RepliPrecis $repliPrecis,
        // Rattrape l'appel d'outil que le modèle a ÉCRIT au lieu de l'émettre.
        AppelDOutilEnTexte $appelEnTexte,
        // Source unique des outils qui produisent un plan.
        OutilsDePlan $outilsDePlan,
        Comprehenseur $comprehenseur,
        // Interrupteur du cache de prompt — voir DialecteAnthropicDuFil pour ce
        // qu'il coûte et ce qu'il rapporte.
        #[Autowire(env: 'bool:ANTHROPIC_CACHE')] bool $cacheActif = true,
        // Injectable pour les tests : ils vérifient la DÉCISION d'attendre, pas la
        // capacité de PHP à dormir. Une suite qui dort n'est plus une suite.
        ?\Closure $dormir = null,
        // LA MÉMOIRE D'ÉPUISEMENT, en dernier et facultative : sans elle, ce moteur
        // n'est jamais « à sec » et se comporte exactement comme avant. C'est ce qui
        // permet aux harnais de test de l'ignorer sans rien perdre du reste.
        ?MemoireDEpuisement $epuisement = null,
    ) {
        $this->cleEstPosee = trim($apiKey) !== '';
        $this->epuisement = $epuisement;
        $this->cleDEpuisement = MemoireDEpuisement::cle('moteur', 'anthropic', $model);
        $this->fil = new DialecteAnthropicDuFil(
            $httpClient,
            // EN DEUX MORCEAUX, contrairement à Gemini : le cache d'Anthropic est
            // explicite, il faut donc savoir où s'arrête l'invariant pour y poser le
            // point de rupture. Gemini, dont le cache est implicite, n'a que faire de
            // cette distinction et reçoit le prompt d'un bloc.
            static fn (AiRequest $r, Trousse $t, Phase $p): array => $contextBuilder->promptSystemeEnDeux($r, $t, $p),
            $trousseCatalogue,
            $apiKey,
            $model,
            $logger,
            self::MAX_OUTPUT_TOKENS,
            self::MAX_ATTENTE_SECONDES,
            self::ATTENTE_SURCHARGE_SECONDES,
            $cacheActif,
            $dormir,
            $epuisement,
            $this->cleDEpuisement,
        );

        $this->orchestrateur = new OrchestrateurDeMessage(
            $contextBuilder,
            $trousseCatalogue,
            $selecteur,
            $executeur,
            $logger,
            $journal,
            $budget,
            $repliPrecis,
            $appelEnTexte,
            $outilsDePlan,
            $comprehenseur,
            $dormir,
        );
    }

    public function name(): string
    {
        return $this->fil->nom();
    }

    public function nom(): string
    {
        return $this->fil->nom();
    }

    /** Une clé posée, et rien d'autre : la garde de périmètre est ailleurs. */
    public function estDisponible(): bool
    {
        return $this->cleEstPosee;
    }

    /**
     * Ce moteur s'est-il déjà déclaré à sec ? Sans appel réseau : c'est la mémoire
     * qu'on interroge, et c'est ce qui évite de repayer une attente pour un refus
     * connu d'avance. La chaîne va alors droit au moteur qui a du solde.
     */
    public function estEpuise(): bool
    {
        return $this->epuisement?->estEpuise($this->cleDEpuisement) ?? false;
    }

    public function modelName(): string
    {
        return $this->fil->modeleCourant();
    }

    public function reply(AiRequest $request): AiReply
    {
        return $this->orchestrateur->traiter($this->fil, $request);
    }
}
