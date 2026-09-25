<?php

namespace App\Ai\Engine;

use App\Ai\AiContextBuilder;
use App\Ai\AiReply;
use App\Ai\AiRequest;
use App\Ai\Comprehension\Comprehenseur;
use App\Ai\Debit\BudgetDebit;
use App\Ai\Fournisseur\FournisseurAModele;
use App\Ai\Fournisseur\FournisseurAReplis;
use App\Ai\Fournisseur\FournisseurDatable;
use App\Ai\Fournisseur\MemoireDEpuisement;
use App\Ai\Engine\Socle\DialecteGeminiDuFil;
use App\Ai\Engine\Socle\OrchestrateurDeMessage;
use App\Ai\Fournisseur\ModeleChoisi;
use App\Ai\Fournisseur\PolitiqueDesFournisseurs;
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
 * Moteur réel : API Google Gemini (generateContent) via symfony/http-client,
 * avec function calling — le modèle décide d'appeler nos outils métier, et PHP
 * mène le travail.
 *
 * CE FICHIER NE CONTIENT PLUS LE TRAVAIL, seulement ce qui est propre à Google.
 * Comprendre la demande, choisir la trousse, mener les phases, exécuter les
 * outils, mesurer, conclure : tout cela vit désormais dans
 * Socle\OrchestrateurDeMessage, partagé avec l'adaptateur Claude. Le format du
 * fil et le transport vivent dans Socle\DialecteGeminiDuFil.
 *
 * POURQUOI CE DÉCOUPAGE. Les règles de conduite de Ket ont été payées par des
 * incidents datés — la relance du tour muet, le rattrapage de l'appel écrit en
 * prose, le garde-fou anti-plan fantôme, la restitution en PHP plutôt qu'une
 * excuse. Tant qu'elles vivaient dans ce fichier, l'autre moteur ne les avait
 * pas : l'adaptateur Claude est resté six mois sans les pièces jointes natives,
 * qu'une simple clé posée suffisait pourtant à activer. Une règle écrite deux
 * fois n'est pas une règle, c'est la prochaine divergence.
 *
 * ⚠ LA SIGNATURE DU CONSTRUCTEUR N'A PAS BOUGÉ, et ce n'est pas un hasard : les
 * 1 347 lignes de GeminiAiEngineTest doivent passer SANS UNE MODIFICATION. C'est
 * ce qui démontre que l'extraction est fidèle, au lieu de l'espérer.
 *
 * SÉCURITÉ : inchangée — le périmètre ne dépend PAS du modèle, chaque outil
 * re-vérifie canRead() dans execute() (fail-closed).
 */
final class GeminiAiEngine implements MoteurDeTexte, FournisseurAModele, FournisseurAReplis, FournisseurDatable
{
    /** Assez ample pour restituer une page de liste (rechercher_entites) sans troncature. */
    private const MAX_OUTPUT_TOKENS = 4096;

    /**
     * Attente maximale avant de renoncer à un réessai, quand le fournisseur
     * annonce lui-même son délai. Même borne que celle de l'orchestrateur, et
     * pour la même raison : au-delà d'une quinzaine de secondes, l'utilisateur
     * préfère une réponse honnête à une attente muette.
     */
    private const MAX_ATTENTE_SECONDES = 15;

    private readonly OrchestrateurDeMessage $orchestrateur;

    private readonly DialecteGeminiDuFil $fil;

    private readonly bool $cleEstPosee;

    private readonly ?MemoireDEpuisement $epuisement;

    /** Le modèle du `.env` et sa chaîne de secours — la console peut en décider autrement. */
    private readonly string $modeleParDefaut;

    private readonly string $replisParDefaut;

    /** Modèle dédié à la RÉDACTION selon le .env — vide quand il n'y en a pas. */
    private readonly string $redactionParDefaut;

    public function __construct(
        HttpClientInterface $httpClient,
        AiContextBuilder $contextBuilder,
        // Source unique des outils déclarés — la MÊME que celle dont le prompt tire
        // sa section d'aiguillage.
        TrousseCatalogue $trousseCatalogue,
        // Les particularités du proto Gemini (déclarations assainies, objets vides
        // préservés), partagées avec la phase de compréhension.
        DialecteGemini $dialecte,
        SelecteurDeTrousse $selecteur,
        // Le seul chemin vers le code métier, partagé avec l'autre moteur et avec la
        // phase de compréhension.
        ExecuteurDOutils $executeur,
        #[Autowire(env: 'GEMINI_API_KEY')] string $apiKey,
        #[Autowire(env: 'GEMINI_MODEL')] string $model,
        // MODÈLES DE SECOURS, séparés par des virgules. Vide = aucun repli.
        //
        // Ils ne servent QUE sur un 503 : le modèle principal est débordé chez Google,
        // et aucune attente raisonnable n'y change quoi que ce soit. Le compteur de
        // débit de Google étant tenu PAR MODÈLE, un modèle de secours arrive avec sa
        // propre fenêtre — c'est ce qui rend le repli utile et non cosmétique.
        #[Autowire(env: 'GEMINI_MODELES_REPLI')] string $modelesDeRepli,
        LoggerInterface $logger,
        JournalTokens $journal,
        BudgetDebit $budget,
        // Rédige en PHP, à coût nul, ce que le modèle n'a pas rédigé.
        RepliPrecis $repliPrecis,
        // Rattrape l'appel d'outil que le modèle a ÉCRIT au lieu de l'émettre.
        AppelDOutilEnTexte $appelEnTexte,
        // Source unique des outils qui produisent un plan.
        OutilsDePlan $outilsDePlan,
        // PREMIÈRE PHASE : établir ce que l'utilisateur veut avant de décider quoi
        // que ce soit.
        Comprehenseur $comprehenseur,
        // Injectable pour les tests : ils vérifient la DÉCISION d'attendre, pas
        // la capacité de PHP à dormir. Une suite qui dort n'est plus une suite.
        ?\Closure $dormir = null,
        // LA MÉMOIRE D'ÉPUISEMENT, en dernier et facultative : sans elle, ce moteur
        // n'est jamais « à sec » et se comporte exactement comme avant. C'est ce qui
        // permet aux harnais de test de l'ignorer sans rien perdre du reste.
        ?MemoireDEpuisement $epuisement = null,
        // LA POLITIQUE DE LA CONSOLE, après la mémoire et tout aussi facultative.
        private readonly ?PolitiqueDesFournisseurs $politique = null,
        // L'HORLOGE DU BUDGET DE DURÉE, en dernier et facultative : rien à passer en
        // production, et les appels existants de ce constructeur restent valides — ce
        // qui est la condition posée en tête de ce fichier.
        ?\Closure $horloge = null,
        /**
         * MODÈLE DÉDIÉ À LA RÉDACTION. Vide = celui du moteur, et rien ne change.
         *
         * ⚠ EN DERNIER, ET AVEC UN DÉFAUT — la condition posée en tête de ce fichier.
         * Placé au milieu, il décalait les arguments positionnels de tous les appels
         * existants : cinquante erreurs de harnais l'ont dit aussitôt (2026-09-25).
         *
         * La rédaction ne pèse que 9,8 % des jetons d'entrée (mesuré sur la campagne)
         * mais écrit 100 % de ce que l'utilisateur lit : c'est le seul endroit où
         * payer plus cher se voit. Et Google tenant sa fenêtre PAR MODÈLE, la phase
         * déplacée cesse de disputer son débit à la planification, qui en pèse 83,9 %.
         */
        #[Autowire(env: 'GEMINI_MODELE_REDACTION')] string $modeleDeRedaction = '',
    ) {
        $this->cleEstPosee = trim($apiKey) !== '';
        $this->epuisement = $epuisement;
        // Posés AVANT le dialecte : celui-ci interroge ces résolveurs dès sa
        // construction, pour s'accorder au réglage en vigueur.
        $this->modeleParDefaut = $model;
        $this->replisParDefaut = $modelesDeRepli;
        $this->redactionParDefaut = $modeleDeRedaction;
        $this->fil = new DialecteGeminiDuFil(
            $httpClient,
            static fn (AiRequest $r, Trousse $t, Phase $p): string => $contextBuilder->toSystemPrompt($r, $t, $p),
            $dialecte,
            $apiKey,
            fn (): string => $this->modeleConfigure(),
            fn (): string => ModeleChoisi::liste($this->politique, 'moteur', 'gemini', $this->replisParDefaut, 'modelesRepli'),
            $logger,
            self::MAX_OUTPUT_TOKENS,
            self::MAX_ATTENTE_SECONDES,
            $dormir,
            $epuisement,
            fn (): string => $this->cleDEpuisement(),
            // Le MÊME journal que l'orchestrateur : une bascule de secours doit figurer
            // dans la campagne, à côté des tours qu'elle a fait changer de modèle.
            $journal,
            // PAR SON NOM, et non par sa position : ce constructeur aligne huit
            // paramètres dont cinq facultatifs, et deux fermetures voisines s'y étaient
            // déjà interverties une fois. Un nom ne s'intervertit pas.
            //
            // Relu à chaque appel, comme le modèle principal : un changement décidé en
            // console part avec le message suivant, sans redémarrage.
            modeleDeRedaction: fn (): string => ModeleChoisi::pour($this->politique, 'redaction', 'gemini', $this->redactionParDefaut),
        );

        // L'orchestrateur est CONSTRUIT ICI et non injecté : le faire entrer par le
        // constructeur en changerait la signature, et la suite de tests qui sert de
        // filet à cette extraction ne passerait plus telle quelle.
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
            $horloge,
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
        return $this->epuisement?->estEpuise($this->cleDEpuisement()) ?? false;
    }

    /**
     * Le modèle réellement interrogé — pas toujours celui de la configuration : un
     * 503 fait basculer sur un secours, et le dialecte en garde la trace.
     */
    public function modelName(): string
    {
        return $this->fil->modeleCourant();
    }

    public function reply(AiRequest $request): AiReply
    {
        return $this->orchestrateur->traiter($this->fil, $request);
    }

    /**
     * Le modèle que la console affiche en filigrane du champ « Modèle ».
     *
     * Un nom de modèle n'est pas un secret : la console est réservée aux agents
     * Joseara, et ce nom figure dans la documentation publique du fournisseur.
     */
    public function modeleEnVigueur(): string
    {
        return $this->modelName();
    }

    /**
     * LA CLÉ QUE LE BOUTON « RÉARMER » DE LA CONSOLE EFFACE.
     *
     * Sans elle, l'écran savait dire d'un moteur qu'il était à sec, mais pas
     * jusqu'à quand ni comment y remédier : la marque tenait alors jusqu'à son
     * échéance, sans autre recours qu'un accès serveur.
     */
    /**
     * LA MARQUE SUIT LE MODÈLE CONFIGURÉ, pas celui de secours en cours d'usage :
     * c'est le modèle principal dont on constate le quota. Changer ce modèle depuis
     * la console rend donc la parole à Ket même si l'ancien était marqué à sec —
     * les quotas de Google sont tenus par modèle.
     */
    public function cleDEpuisement(): string
    {
        return MemoireDEpuisement::cle('moteur', 'gemini', $this->modeleConfigure());
    }

    /**
     * LA CHAÎNE DE SECOURS, TELLE QU'ELLE SERA PARCOURUE.
     *
     * Ce moteur ne s'arrête pas au modèle principal : un 503 ou un quota atteint le
     * fait basculer sur le suivant, et il continue de répondre. Tant que la console
     * n'affichait que le premier, elle nommait la mauvaise chose — on cherchait la
     * cause d'une réponse lente ou médiocre du côté d'un modèle qui n'avait pas
     * parlé. Relu à chaque appel, comme le modèle principal.
     *
     * @return list<string>
     */
    public function modelesDeRepli(): array
    {
        $liste = ModeleChoisi::liste($this->politique, 'moteur', 'gemini', $this->replisParDefaut, 'modelesRepli');

        return array_values(array_filter(
            array_map('trim', explode(',', $liste)),
            static fn (string $nom): bool => $nom !== '',
        ));
    }

    /** La marque d'épuisement d'un modèle PRÉCIS de ce moteur. */
    public function cleDEpuisementDe(string $modele): string
    {
        return MemoireDEpuisement::cle('moteur', 'gemini', $modele);
    }

    /**
     * Le modèle DEMANDÉ, relu à chaque fois — à distinguer de `modelName()`, qui
     * rend celui réellement interrogé, éventuellement un modèle de secours.
     */
    private function modeleConfigure(): string
    {
        return ModeleChoisi::pour($this->politique, 'moteur', 'gemini', $this->modeleParDefaut);
    }
}
