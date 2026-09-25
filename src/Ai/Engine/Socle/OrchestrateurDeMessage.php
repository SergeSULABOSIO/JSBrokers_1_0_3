<?php

namespace App\Ai\Engine\Socle;

use App\Ai\AiContextBuilder;
use App\Ai\AiEngineFailure;
use App\Ai\AiReply;
use App\Ai\AiRequest;
use App\Ai\Comprehension\ClarificationEnAttente;
use App\Ai\Comprehension\Comprehenseur;
use App\Ai\Comprehension\DemandeComprise;
use App\Ai\Controle\ChiffreFantome;
use App\Ai\Debit\BudgetDebit;
use App\Ai\Engine\AppelDOutilEnTexte;
use App\Ai\Mutation\MotifDeRefus;
use App\Ai\Mutation\OutilsDePlan;
use App\Ai\Mutation\PlanEnAttente;
use App\Ai\Redaction\RelanceDuTourMuet;
use App\Ai\Redaction\RepliPrecis;
use App\Ai\Telemetrie\JournalTokens;
use App\Ai\Tool\AiToolResult;
use App\Ai\Tool\ExecuteurDOutils;
use App\Ai\Trousse\Phase;
use App\Ai\Trousse\SelecteurDeTrousse;
use App\Ai\Trousse\TrousseCatalogue;
use Psr\Log\LoggerInterface;

/**
 * LE TRAVAIL D'UN MESSAGE, indépendamment du fournisseur.
 *
 * Comprendre la demande, choisir la trousse, mener les phases, exécuter les
 * outils, mesurer chaque tour, conclure par un bilan : rien de tout cela ne
 * dépend de Google ou d'Anthropic. Seule la forme du fil en dépend, et elle vit
 * dans DialecteDuFil.
 *
 * CE CODE N'EST PAS NEUF. Il a été DÉPLACÉ depuis GeminiAiEngine, ligne à ligne,
 * sans « amélioration » en chemin — c'est la condition pour que les 1 347 lignes
 * de GeminiAiEngineTest continuent de passer SANS UNE SEULE MODIFICATION, et donc
 * pour que l'extraction soit démontrée fidèle plutôt qu'espérée telle. Chaque
 * garde-fou qu'on y lit a été payé par un incident daté ; les commentaires qui le
 * disent ont suivi le code.
 *
 * DEUX APPELS PAR MESSAGE, TROIS QUAND LE PREMIER REGARD A BUTÉ. Le modèle
 * n'orchestre rien, PHP orchestre :
 *  1. le modèle reçoit la bulle et émet ses appels d'outils — plusieurs à la fois
 *     s'il le veut, ce qui est du PARALLÈLE, pas de l'orchestration ;
 *  2. le serveur exécute tout, puis un dernier appel sert à FORMULER la réponse.
 *
 * Le troisième appel SE MÉRITE (cf. meriteUnSecondRegard) : « quelle police porte
 * la plus grosse prime ? », « quel assureur ? » se répondent en deux temps —
 * chercher, lire, chercher à nouveau. Sans ce second regard, Ket répondait « je
 * n'ai pas trouvé » et l'utilisateur relançait à la main, au prix d'un message
 * entier. On paie donc un tour de plus LÀ OÙ L'ON ÉCHOUAIT, jamais ailleurs.
 *
 * POURQUOI PAS UN PLAFOND LIBRE. L'API est sans mémoire : chaque tour réexpédie
 * tout le contexte, déclarations d'outils comprises (~72 Ko). Mesuré le
 * 2026-08-10 : un message a enchaîné cinq tours à 37 700 tokens et consommé
 * 188 000 des 212 500 tokens d'une minute ; les messages suivants, « salut »
 * compris, se sont heurtés à une fenêtre vide. Le nombre de tours n'est pas un
 * curseur de performance, c'est un risque de panne pour la conversation entière.
 */
final class OrchestrateurDeMessage
{
    /**
     * Attente maximale AVANT DE RELANCER UN TOUR, quand la fenêtre d'une minute
     * est saturée mais sur le point de se libérer.
     *
     * PLAFOND BAS. La raison d'origine était le développement : « symfony serve »
     * ne dispose que d'UN worker php-cgi, et une requête qui dort y figeait toute
     * l'application, rechargement de page compris.
     *
     * ⚠️ CETTE RAISON A DISPARU, LE PLAFOND RESTE. Le traitement vit désormais
     * dans un worker, où dormir ne gèle plus rien. La tentation de relever ces
     * bornes est donc réelle — et c'est un AUTRE sujet : ce serait changer le
     * comportement de repli face au quota du fournisseur, décision à prendre sur
     * des mesures, pas au détour d'une refonte de transport. La seconde raison,
     * elle, tient toujours : au-delà d'une trentaine de secondes, l'utilisateur
     * préfère une réponse honnête à une attente muette.
     */
    private const MAX_ATTENTE_SECONDES = 15;

    /** Attente cumulée tolérée sur un message entier (garde-fou de temps de réponse). */
    private const MAX_ATTENTE_CUMULEE_SECONDES = 25;

    /**
     * Ratio octets → tokens MESURÉ sur la campagne (3,52 le 2026-08-08), et non
     * supposé. Sert à estimer ce que coûtera le tour suivant avant de le lancer.
     */
    private const OCTETS_PAR_TOKEN = 3.5;

    public function __construct(
        private readonly AiContextBuilder $contextBuilder,
        // Source unique des outils déclarés — la MÊME que celle dont le prompt tire
        // sa section d'aiguillage.
        private readonly TrousseCatalogue $trousseCatalogue,
        private readonly SelecteurDeTrousse $selecteur,
        // Le seul chemin vers le code métier, partagé avec la phase de compréhension.
        private readonly ExecuteurDOutils $executeur,
        private readonly LoggerInterface $logger,
        private readonly JournalTokens $journal,
        private readonly BudgetDebit $budget,
        // Rédige en PHP, à coût nul, ce que le modèle n'a pas rédigé — à partir des
        // résultats d'outils déjà obtenus.
        private readonly RepliPrecis $repliPrecis,
        // Rattrape l'appel d'outil que le modèle a ÉCRIT au lieu de l'émettre.
        private readonly AppelDOutilEnTexte $appelEnTexte,
        // Source unique des outils qui produisent un plan : sert à signaler au
        // contrôleur qu'un tel outil a tourné SANS produire de plan.
        private readonly OutilsDePlan $outilsDePlan,
        // PREMIÈRE PHASE : établir ce que l'utilisateur veut avant de décider quoi
        // que ce soit. Fail-open par construction — s'il ne conclut pas, la demande
        // part telle quelle et la planification retrouve son comportement d'avant.
        private readonly Comprehenseur $comprehenseur,
        // Injectable pour les tests : ils vérifient la DÉCISION d'attendre, pas
        // la capacité de PHP à dormir. Une suite qui dort n'est plus une suite.
        private readonly ?\Closure $dormir = null,
        // L'HORLOGE, injectable pour la même raison que $dormir : un test doit pouvoir
        // vérifier la DÉCISION de renoncer sans attendre quatre-vingt-dix secondes.
        // Absente, c'est le temps réel — le comportement en production est inchangé.
        private readonly ?\Closure $horloge = null,
    ) {
    }

    /** Secondes écoulées depuis le début du message, au temps réel ou à celui du test. */
    private function maintenant(): float
    {
        return $this->horloge === null ? microtime(true) : (float) ($this->horloge)();
    }

    /**
     * LE TEMPS DE L'UTILISATEUR EST UN BUDGET, LUI AUSSI.
     *
     * DUREE_MAX_SECONDES borne UN appel (45 s). Elle ne borne pas le MESSAGE : trois
     * appels bornés chacun peuvent tenir 135 s, auxquelles s'ajoute la compréhension.
     * Mesuré sur les 241 messages du journal : médiane 6 s, p95 26 s — et un maximum
     * à 159 s. Près de trois minutes de silence, sans que rien ne s'y oppose.
     *
     * QUATRE-VINGT-DIX SECONDES, soit deux appels pleins. Rejoué sur la campagne, ce
     * seuil n'aurait touché que 2 messages sur 241 (0,8 %) : il ne coupe pas le
     * travail normal, il coupe la queue pathologique. Au-delà, l'utilisateur préfère
     * une réponse honnête à une attente muette — c'est déjà le raisonnement qui a fixé
     * la borne par appel.
     *
     * ⚠ ON NE COUPE JAMAIS UN APPEL EN VOL : le budget est vérifié AVANT d'en engager
     * un de plus. Ce qui est payé est utilisé, et ce qui est déjà rassemblé est rendu
     * par RepliPrecis, à coût nul.
     */
    private const DUREE_MAX_MESSAGE_SECONDES = 90;

    public function traiter(DialecteDuFil $dialecte, AiRequest $request): AiReply
    {
        // Ouvre la mesure : rattache les lignes « tour » qui suivent à ce message,
        // et permet au contrôleur de savoir combien de tours ont été payés si un
        // 429 interrompt la boucle.
        $this->journal->nouveauMessage();

        // L'horloge du message démarre AVANT la compréhension : elle fait partie de
        // l'attente, même quand elle tourne sur un autre modèle.
        $debutDuMessage = $this->maintenant();

        $fil = $dialecte->filInitial($request);

        // PREMIÈRE PHASE — COMPRENDRE. Avant les pièces natives, et ce n'est pas un
        // détail d'ordre : joindre un PDF scanné à l'appel censé rester petit lui
        // ferait perdre sa raison d'être. Le nom des pièces figure dans son prompt,
        // c'est assez pour savoir qu'elles existent.
        //
        // Demande ambiguë => on s'arrête ICI. Ni planification ni rédaction : le
        // message aura coûté un seul appel, le plus léger des trois, au lieu de deux
        // appels pleins pour une réponse à côté suivie d'une relance.
        // MODE LIVE : pas de phase de compréhension. Elle coûte 8 s en médiane et
        // n'aboutit qu'une fois sur deux ; à l'oral, ce silence est intenable et son
        // apport — reformuler une demande ambiguë — se règle d'un mot de l'utilisateur.
        $this->journal->debutDePhase(Phase::COMPREHENSION);
        $comprise = $request->modeLive
            ? DemandeComprise::claire($request->lastUserMessage(), DemandeComprise::ORIGINE_COURT_CIRCUIT)
            // ⚠ ON NE LUI PASSE PAS NOTRE FIL. Il est au format de NOTRE fournisseur,
            // et la compréhension peut tourner chez un autre : le lui donner enverrait
            // chez Google un dialecte que Google refuse. Et comme cette phase est
            // fail-open de bout en bout, elle échouerait sans un mot, à chaque message.
            // Elle construit donc le sien, à partir de la même requête.
            : $this->comprehenseur->comprendre($request);
        if (!$comprise->claire) {
            return $this->conclure(
                $dialecte,
                $request,
                JournalTokens::ISSUE_CLARIFICATION,
                0,
                0,
                0,
                [],
                new AiReply(
                    $comprise->texteDeClarification(),
                    actions: [ClarificationEnAttente::action($comprise)],
                ),
            );
        }
        // L'intention voyage désormais avec la requête : la planification la lira en
        // tête de ses règles, à côté — jamais à la place — du message d'origine.
        $request = $request->withComprehension($comprise);

        // Pièces jointes lisibles nativement (PDF scannés, images) : jointes au
        // DERNIER tour utilisateur, pour que le modèle les lise par vision avec la
        // question courante (elles restent en contexte des tours suivants).
        $fil = $dialecte->joindrePieces($fil, $request->piecesNatives);

        $refused = false;
        $toolUsed = null;
        $actions = [];
        $cumulInput = 0;
        $cumulSortie = 0;
        $sequenceOutils = [];
        $attenteCumulee = 0;
        // Ce que les outils ont RÉELLEMENT rapporté : la matière du repli si le modèle
        // ne rédige pas. Sans elle, on ne pouvait que servir une phrase générique.
        $resultatsOutils = [];
        // Outils de plan qui ont REFUSÉ : le contrôleur croise ce signal avec la prose
        // pour démasquer un plan décrit mais jamais préparé — et dire ce qui manque.
        $plansRefuses = [];

        // TROUSSE du message : décidée par le SERVEUR, sans rien demander au modèle.
        // Un appel de routage était un TROISIÈME appel — la règle n'en tolère que
        // deux, et c'est la planification elle-même qui choisit les outils.
        $trousse = $this->selecteur->trousseDe($request);
        // Retenue pour le bilan de fin de message : la recalculer là-bas coûterait une
        // seconde lecture du fil, et pourrait rendre AUTRE CHOSE si l'état a bougé
        // entre-temps — un journal qui décrit un aiguillage qui n'a pas eu lieu est
        // pire qu'un journal muet.
        $this->trousseDuMessage = $trousse;
        $this->declencheurDuMessage = $this->selecteur->dernierDeclencheur();
        $this->motArmeurDuMessage = $this->selecteur->dernierMotArmeur();
        $this->journal->routage(
            $request,
            $dialecte->nom(),
            $trousse->libelle(),
            'serveur',
            0,
            0,
            $this->declencheurDuMessage,
        );

        // DEUX PHASES, ET UNE TROISIÈME QUI SE MÉRITE. Planification (les outils sont
        // déclarés), puis rédaction (ils ne le sont plus : on commente un travail déjà
        // fait). Entre les deux, un SECOND REGARD — un appel d'outils de plus — mais
        // seulement là où le premier a buté : cf. meriteUnSecondRegard().
        foreach ([Phase::PLANIFICATION, Phase::PLANIFICATION, Phase::REDACTION] as $round => $phase) {
            // Le second regard ne se paie que s'il sert. Sans cette porte, CHAQUE message
            // réexpédierait les 72 Ko de déclarations d'outils une fois de plus, pour un
            // tour que le modèle n'a pas demandé.
            if ($round === 1 && !self::meriteUnSecondRegard($resultatsOutils)) {
                continue;
            }
            // LE BUDGET DE DURÉE DU MESSAGE, vérifié avant d'engager un appel de plus.
            // Un appel déjà parti va au bout : on ne jette pas ce qui est payé.
            $ecoulees = $this->maintenant() - $debutDuMessage;
            if ($ecoulees >= self::DUREE_MAX_MESSAGE_SECONDES) {
                $this->logger->warning(sprintf('Assistant IA (%s) : budget de durée du message dépassé, conclusion anticipée.', $dialecte->nom()), [
                    'phase'    => $phase->name,
                    'secondes' => round($ecoulees, 1),
                    'plafond'  => self::DUREE_MAX_MESSAGE_SECONDES,
                ]);

                $restitution = $this->repliPrecis->depuis($resultatsOutils);

                return $this->conclure(
                    $dialecte,
                    $request,
                    JournalTokens::ISSUE_DUREE_DEPASSEE,
                    $round,
                    $cumulInput,
                    $cumulSortie,
                    $sequenceOutils,
                    new AiReply(
                        $restitution === RepliPrecis::GENERIQUE ? self::TROP_LONG : $restitution,
                        refused: $refused,
                        toolUsed: $toolUsed,
                        actions: $actions,
                        plansRefuses: $plansRefuses,
                        chiffresDesOutils: ChiffreFantome::nombresDe($resultatsOutils),
                    ),
                );
            }

            // La phase est annoncée AVANT de partir : c'est pendant l'appel que
            // l'utilisateur attend, pas après. Tout le reste de ce journal se
            // mesure au retour, et arriverait donc une phase trop tard.
            $this->journal->debutDePhase($phase);

            // LE CHRONOMÈTRE DE L'APPEL, ouvert ici et arrêté au journal du tour. Il
            // couvre donc l'appel ET ses éventuelles reprises (tour muet, surcharge) :
            // c'est le temps que l'utilisateur SUBIT, pas celui d'un aller-retour
            // isolé qu'il n'a jamais attendu seul.
            $debutDeLAppel = $this->maintenant();
            try {
                ['reponse' => $response, 'octets' => $octets, 'usage' => $usage] = $dialecte->appeler($request, $fil, $trousse, $phase);
            } catch (\Throwable $e) {
                // UN QUOTA ÉPUISÉ N'EST PAS UNE PANNE DE L'APPLICATION. Relevé en
                // production le 2026-09-20 : 21 exceptions « HTTP/2 429 returned for
                // gemini-3.1-flash-lite » remontées jusqu'au contrôleur en dix-sept
                // minutes. L'utilisateur perdait son message, recevait une erreur 500, et
                // la supervision classait comme défaut à corriger une limite de débit du
                // fournisseur — sur laquelle il n'y a rien à corriger.
                //
                // Tous les modèles étant saturés, on CONCLUT proprement : ce que les
                // outils ont déjà rapporté est restitué en PHP (coût nul), et à défaut Ket
                // explique la saturation et le délai. Le fil reste intact.
                if (!AiEngineFailure::estLimiteDeDebit($e)) {
                    throw $e;
                }
                $delai = AiEngineFailure::secondesAvantNouvelEssai($e);
                $this->logger->warning(sprintf('Assistant IA (%s) : quota du fournisseur épuisé, message conclu sans erreur.', $dialecte->nom()), [
                    'phase'   => $phase->name,
                    'modele'  => $dialecte->modeleCourant(),
                    'delai'   => $delai,
                    'details' => AiEngineFailure::detailsPourJournal($e),
                ]);
                $restitution = $this->repliPrecis->depuis($resultatsOutils);

                // ⚠ TOUS LES 429 NE SE VALENT PAS. Le plafond de DÉPENSE mensuel arrive
                // sous le même code HTTP qu'une saturation de débit, et c'est son exact
                // contraire : aucune attente ne le rouvre. Lui servir « reposez-moi la
                // question dans quelques minutes » enverrait l'utilisateur relancer en
                // boucle jusqu'au premier du mois. AiEngineFailure sait le dire —
                // la vraie cause, et la date de réouverture que le fournisseur annonce.
                $excuse = AiEngineFailure::estPlafondDeDepense($e)
                    ? AiEngineFailure::messagePour($e)
                    : $this->messageQuotaEpuise($delai);

                return $this->conclure(
                    $dialecte,
                    $request,
                    JournalTokens::ISSUE_BUDGET_ATTEINT,
                    $round + 1,
                    $cumulInput,
                    $cumulSortie,
                    $sequenceOutils,
                    new AiReply(
                        $restitution === RepliPrecis::GENERIQUE ? $excuse : $restitution,
                        refused: $refused,
                        toolUsed: $toolUsed,
                        actions: $actions,
                        plansRefuses: $plansRefuses,
                        chiffresDesOutils: ChiffreFantome::nombresDe($resultatsOutils),
                    ),
                );
            }

            // UNE SEULE REPRISE PAR TOUR, quelle qu'en soit la cause (appel malformé ou
            // tour muet) : au-delà, on paierait un troisième appel pour ce message.
            $repriseFaite = false;

            // APPEL D'OUTIL MALFORMÉ — le blocage du 2026-08-12, et il ne venait ni du
            // prompt ni du raisonnement du modèle. Sur « Je confirme », Gemini a bien
            // TENTÉ d'émettre preparer_operations, mais son sérialiseur d'appels a
            // produit une structure invalide qu'il a lui-même rejetée : finishReason
            // MALFORMED_FUNCTION_CALL, 698 jetons de sortie, et RIEN dans le canal des
            // fonctions. L'utilisateur recevait alors « redites-le-moi en nommant le
            // point précis », c'est-à-dire notre échec présenté comme son imprécision.
            //
            // C'est un défaut de SÉRIALISATION, donc dépendant de l'échantillonnage :
            // le même appel réémis passe le plus souvent. On réessaie UNE fois, et une
            // seule — le quota se compte par minute, et un échec répété relève d'autre
            // chose (un schéma trop profond pour ce modèle) que d'un aléa.
            if ($phase === Phase::PLANIFICATION && $dialecte->estAppelMalforme($response)) {
                $repriseFaite = true;
                $this->logger->warning(sprintf('Assistant IA (%s) : appel d’outil MALFORMÉ, une reprise.', $dialecte->nom()), [
                    'sortie' => $usage->sortie,
                ]);
                ['reponse' => $response, 'octets' => $octets, 'usage' => $usage] = $dialecte->appeler($request, $fil, $trousse, $phase);
                if ($dialecte->estAppelMalforme($response)) {
                    // Deux fois de suite : ce n'est plus un aléa. On le DIT — et
                    // surtout on ne renvoie pas la faute à l'utilisateur.
                    $this->logger->error(sprintf('Assistant IA (%s) : appel d’outil malformé DEUX fois, abandon du tour.', $dialecte->nom()), [
                        'sortie' => $usage->sortie,
                    ]);
                }
            }

            // UN TOUR DE PLANIFICATION QUI NE REND RIEN DU TOUT — ni texte, ni appel
            // d'outil. C'est le mur du 2026-09-08 (conversation 68) : sur « Invente pour
            // moi des numéros. », le modèle a dépensé 807 jetons de sortie en raisonnement
            // interne et n'a émis AUCUN mot. Le moteur n'avait alors rien à rendre, rien à
            // restituer non plus — pas un outil n'avait tourné —, et l'utilisateur a reçu
            // la phrase de dernier recours : « redites-la-moi en nommant le point précis ».
            // Il venait de le nommer trois fois.
            //
            // POURQUOI UNE REPRISE, ET POURQUOI ELLE NE COÛTE RIEN DE PLUS. Ce message
            // s'arrête ICI : la rédaction ne partira jamais, puisqu'elle n'aurait rien à
            // commenter. Le second des deux appels auxquels un message a droit est donc
            // libre — le dépenser à redemander une réponse vaut infiniment mieux que de le
            // laisser tomber pour servir un mur. La règle des DEUX APPELS est tenue, et
            // `$repriseFaite` garantit qu'on ne reprend qu'une fois par tour, y compris
            // quand l'appel malformé ci-dessus a déjà consommé la reprise.
            //
            // LA RELANCE DIT CE QUI S'EST PASSÉ. Rejouer à l'identique parierait sur le
            // seul échantillonnage ; on y joint donc une ligne qui nomme le silence et
            // rappelle les deux seules sorties acceptables — répondre, ou appeler l'outil.
            if ($phase === Phase::PLANIFICATION && !$repriseFaite && $dialecte->estTourVide($response)) {
                $repriseFaite = true;
                // Le tour abandonné a bel et bien été facturé chez le fournisseur : il
                // entre dans le cumul du message ET dans le compteur de débit par minute,
                // sans quoi la reprise partirait sur un quota qu'on croit encore libre.
                $cumulInput += $usage->entree;
                $cumulSortie += $usage->sortie;
                $this->budget->enregistrer($dialecte->cleDeDebit(), $usage->debit);

                $this->logger->warning(sprintf('Assistant IA (%s) : tour de planification VIDE, une reprise.', $dialecte->nom()), [
                    'entree' => $usage->entree,
                    'sortie' => $usage->sortie,
                ]);

                // La relance ne rejoint PAS $fil : c'est un échafaudage, pas un tour de
                // conversation. La rédaction — et le fil que l'utilisateur relira — ne
                // doivent pas en garder trace.
                ['reponse' => $response, 'octets' => $octets, 'usage' => $usage] = $dialecte->appeler(
                    $request,
                    array_merge($fil, [$dialecte->tourDeRelance(RelanceDuTourMuet::TEXTE)]),
                    $trousse,
                    $phase,
                );
            }

            $cumulInput += $usage->entree;
            $cumulSortie += $usage->sortie;

            // Le débit consommé se déclare AUSSITÔT, et sur ce que le fournisseur
            // décompte vraiment (cf. Usage::$debit). Déclarer ici plutôt qu'en fin
            // de message est indispensable : le quota est partagé, une autre requête
            // en cours doit voir ce que celle-ci vient de consommer, sans attendre
            // qu'elle se termine.
            $this->budget->enregistrer($dialecte->cleDeDebit(), $usage->debit);

            $appels = $dialecte->appelsDOutils($response);

            // RATTRAPAGE — L'APPEL D'OUTIL ÉCRIT AU LIEU D'ÊTRE ÉMIS. Le modèle rend
            // parfois « consulter_guide(parcours-de-saisie) » comme du TEXTE, sans
            // rien émettre dans le canal des fonctions. Servir cette ligne à
            // l'utilisateur — ce qui est arrivé le 2026-08-11 — c'est lui montrer nos
            // rouages et lui refuser une réponse que nous pouvions produire : l'outil
            // existe, l'argument est bon, seul le canal était faux. On l'exécute donc,
            // sans aucun appel supplémentaire au fournisseur.
            $rattrapage = false;
            if ($appels === [] && $phase === Phase::PLANIFICATION) {
                foreach ($this->appelEnTexte->extraire(
                    $dialecte->texte($response, ''),
                    $this->trousseCatalogue->outilsDe($trousse, $request->scope),
                ) as $appel) {
                    $appels[] = ['nom' => $appel['name'], 'args' => $appel['args'], 'id' => null];
                    $rattrapage = true;
                }
                if ($rattrapage) {
                    $this->logger->warning(sprintf('Assistant IA (%s) : appel d’outil rendu en texte, rattrapé par le serveur.', $dialecte->nom()), [
                        'outils' => array_column($appels, 'nom'),
                    ]);
                }
            }

            $this->journal->tour(
                $request,
                $dialecte->nom(),
                $dialecte->modeleCourant(),
                $round + 1,
                $usage->pourLeJournal(),
                $octets,
                array_column($appels, 'nom'),
                (int) round(($this->maintenant() - $debutDeLAppel) * 1000),
            );

            // Requête bloquée par les garde-fous du fournisseur (prompt ou réponse).
            if ($dialecte->estBloquee($response)) {
                return $this->conclure(
                    $dialecte,
                    $request,
                    JournalTokens::ISSUE_BLOCAGE_SECURITE,
                    $round + 1,
                    $cumulInput,
                    $cumulSortie,
                    $sequenceOutils,
                    new AiReply(
                        'Je ne peux pas traiter cette demande. Reformulez votre question sur les données '
                        . 'de votre espace de travail et je vous aiderai volontiers.',
                        refused: true,
                    ),
                );
            }

            // Le modèle a formulé sa réponse : c'est la fin normale, à l'une ou
            // l'autre phase (une question de pure conversation n'appelle aucun outil
            // et se termine donc dès la planification, en UN seul appel).
            if ($appels === []) {
                // TEXTE VIDE SANS APPEL D'OUTIL : le modèle n'a RIEN rendu. Le cas
                // s'est produit le 11/08/2026 sur « affiche le même tableau, mais
                // ajoute une colonne » — une pure remise en forme, où l'utilisateur
                // s'est vu répondre « précisez votre question » alors que sa demande
                // était parfaitement claire et que le tableau était juste au-dessus.
                //
                // Deux corrections, distinctes :
                //  1) on TRACE la cause. Sans elle on ne peut que supposer ; le journal
                //     ne disait rien de ce tour, c'est ce qui a rendu l'incident opaque.
                //  2) on cesse de RENVOYER LA FAUTE. Le repli par défaut demande à
                //     l'utilisateur de préciser ; RepliPrecis, lui, restitue ce que les
                //     outils du tour ont rapporté et, à défaut, reconnaît franchement
                //     que NOUS n'avons pas conclu. C'est déjà ce que fait la phase de
                //     rédaction ; il n'y avait aucune raison que celle-ci en diffère.
                $texte = $dialecte->texte($response, $this->repliPrecis->depuis($resultatsOutils));
                if (trim($dialecte->texte($response, '')) === '') {
                    $this->logger->warning(sprintf('Assistant IA (%s) : réponse SANS texte ni appel d’outil.', $dialecte->nom()), [
                        'phase'        => $phase->name,
                        'outilsDuTour' => $sequenceOutils,
                        'sortie'       => $usage->sortie,
                    ]);
                }

                // FILET DE SÛRETÉ D'AFFICHAGE. Si ce texte est encore un appel d'outil
                // écrit en prose — parce qu'il nomme un outil qui n'existe pas, ou qui
                // n'est pas déclaré ce tour-ci —, le rattrapage n'a rien pu en faire.
                // Le servir tel quel resterait la pire des issues : l'utilisateur a
                // reçu « consulter_guide(parcours-de-saisie) » et répondu « je ne
                // comprends rien !! ». On restitue plutôt ce que les outils du tour ont
                // rapporté, et à défaut on dit honnêtement qu'on n'a pas conclu.
                if ($this->appelEnTexte->ressembleAUnAppel($texte)) {
                    $this->logger->warning(sprintf('Assistant IA (%s) : appel d’outil en texte non rattrapable, masqué.', $dialecte->nom()), [
                        'phase' => $phase->name,
                        'texte' => mb_substr($texte, 0, 120),
                    ]);
                    $texte = $this->repliPrecis->depuis($resultatsOutils);
                }

                return $this->conclure(
                    $dialecte,
                    $request,
                    JournalTokens::ISSUE_REPONSE,
                    $round + 1,
                    $cumulInput,
                    $cumulSortie,
                    $sequenceOutils,
                    new AiReply(
                        $texte,
                        refused: $refused,
                        toolUsed: $toolUsed,
                        actions: $actions,
                        plansRefuses: $plansRefuses,
                        chiffresDesOutils: ChiffreFantome::nombresDe($resultatsOutils),
                    ),
                );
            }

            // Phase de RÉDACTION : aucun outil ne lui est déclaré, donc un appel
            // d'outil ici ne peut être qu'une hallucination. On ne l'exécute pas et
            // on ne relance pas — ce serait un appel de plus. On rend ce qui a déjà
            // été obtenu, actions comprises : un plan préparé et son bouton de
            // validation valent bien mieux qu'une page blanche.
            if ($phase === Phase::REDACTION) {
                // Le modèle a redemandé un outil au lieu d'écrire. Plutôt que la phrase
                // générique d'autrefois — qui renvoyait l'utilisateur à sa question alors
                // que la réponse était déjà sur la table —, on restitue nous-mêmes ce que
                // les outils ont rapporté : la question précise qui reste à poser, le
                // blocage rencontré, ou les candidats à départager. Coût : zéro token.
                $texte = $dialecte->texte($response, $this->repliPrecis->depuis($resultatsOutils));

                return $this->conclure(
                    $dialecte,
                    $request,
                    JournalTokens::ISSUE_REPONSE,
                    $round + 1,
                    $cumulInput,
                    $cumulSortie,
                    $sequenceOutils,
                    new AiReply(
                        $texte,
                        refused: $refused,
                        toolUsed: $toolUsed,
                        actions: $actions,
                        plansRefuses: $plansRefuses,
                        chiffresDesOutils: ChiffreFantome::nombresDe($resultatsOutils),
                    ),
                );
            }

            // Appels d'outils : exécuter TOUT ce qui a été demandé (fail-closed dans
            // chaque outil), réponses regroupées dans UN message user.
            //
            // On exécute AVANT de regarder le débit disponible, à l'inverse de
            // l'ancien garde-fou : nos outils ne coûtent aucun token, et s'il
            // faut malgré tout s'arrêter là, autant que l'utilisateur reparte
            // avec ce qu'ils ont produit — un plan préparé et son bouton de
            // validation (uiAction) valent bien mieux qu'une page blanche.
            $resultatsDuTour = [];
            foreach ($appels as $appel) {
                $nom = $appel['nom'];
                $result = $this->executeur->executer($nom, $appel['args'], $request->scope, $trousse);
                $toolUsed = $nom;
                $sequenceOutils[] = $nom;
                // L'ACTION D'INTERFACE VOYAGE AVEC LE RÉSULTAT : quand un outil ne
                // rapporte aucune donnée, c'est elle — et elle seule — qui dit ce qui a
                // été fait. Sans cela, le repli ne pouvait que nier le geste.
                $resultatsOutils[] = ['outil' => $nom, 'data' => $result->data, 'action' => $result->uiAction];
                if ($result->status === AiToolResult::STATUS_HORS_PERIMETRE) {
                    $refused = true;
                }
                if ($result->uiAction !== null) {
                    $actions[] = $result->uiAction;
                }
                // Un outil de plan qui n'a PAS produit de plan : le contrôleur doit le
                // savoir, sans quoi rien ne distingue « le modèle a décrit un plan
                // inexistant » de « le modèle a répondu à une question ».
                if ($this->outilsDePlan->estOutilDePlan($nom) && MotifDeRefus::estUnRefus($result)) {
                    $plansRefuses[] = ['outil' => $nom, 'motif' => MotifDeRefus::depuis($result)];
                }
                $resultatsDuTour[] = ['appel' => $appel, 'statut' => $result->status, 'data' => $result->data];
            }
            $fil = array_merge($fil, $dialecte->toursDeResultats($response, $resultatsDuTour, $rattrapage));

            // CE QUI VIENT D'ARRIVER DÉCIDE DU TEMPS DE LA PHRASE SUIVANTE. Si la
            // planification a préparé une décision — plan d'écriture, document à
            // produire —, RIEN n'est écrit : la rédaction doit parler au futur. Sans ce
            // signal, elle n'a que le fil pour se situer et son prompt lui affirme que
            // le travail est fait ; c'est ainsi que Ket a annoncé « le document a été
            // correctement rattaché au client » sous un bouton « Valider et exécuter »
            // que personne n'avait touché (2026-08-16).
            $request = $request->withDecisionEnAttente(PlanEnAttente::porteUneDecision($actions));

            // La rédaction n'emporte NI les déclarations d'outils NI les protocoles
            // d'écriture : elle coûte donc bien moins que la planification. On
            // l'estime sur ce qu'elle transporte vraiment — l'historique et les
            // résultats —, sans quoi on renoncerait à un tour bon marché en le
            // croyant aussi cher que le précédent.
            $estime = (int) ceil(
                (\strlen((string) json_encode($fil, JSON_UNESCAPED_UNICODE))
                    + \strlen($this->contextBuilder->toSystemPrompt($request, $trousse, Phase::REDACTION)))
                / self::OCTETS_PAR_TOKEN,
            );
            // LA FENÊTRE DE LA RÉDACTION, pas celle du tour qui vient de finir : depuis
            // qu'elle peut avoir son propre modèle, ce n'est plus le même compteur —
            // et renoncer à un tour parce qu'une AUTRE fenêtre est pleine serait un
            // refus sans cause.
            $attente = $this->budget->secondesAvantLiberation($dialecte->cleDeDebit(Phase::REDACTION), $estime);

            // Assez de débit tout de suite : on enchaîne, c'est le cas courant.
            if ($attente === 0) {
                continue;
            }

            $tropLong = $attente === null
                || $attente > self::MAX_ATTENTE_SECONDES
                || $attenteCumulee + $attente > self::MAX_ATTENTE_CUMULEE_SECONDES;

            if ($tropLong) {
                $this->logger->warning(sprintf('Assistant IA (%s) : débit par minute saturé, boucle arrêtée.', $dialecte->nom()), [
                    'tours'          => $round + 1,
                    'cumulEntree'    => $cumulInput,
                    'estimeProchain' => $estime,
                    'restant'        => $this->budget->restant($dialecte->cleDeDebit(Phase::REDACTION)),
                    'attente'        => $attente,
                    'dernierOutil'   => $appels[0]['nom'] ?? null,
                ]);

                return $this->conclure(
                    $dialecte,
                    $request,
                    JournalTokens::ISSUE_BUDGET_ATTEINT,
                    $round + 1,
                    $cumulInput,
                    $cumulSortie,
                    $sequenceOutils,
                    new AiReply(
                        $this->messageDebitSature($attente),
                        refused: $refused,
                        toolUsed: $toolUsed,
                        actions: $actions,
                        plansRefuses: $plansRefuses,
                    ),
                );
            }

            // La fenêtre se libère dans quelques secondes : patienter et finir le
            // travail vaut infiniment mieux que jeter les tours déjà payés.
            $this->journal->attente($request, $dialecte->nom(), $dialecte->modeleCourant(), $round + 1, $attente, $estime);
            ($this->dormir ?? static fn (int $s) => sleep($s))($attente);
            $attenteCumulee += $attente;
        }

        // Sortie de sécurité : les phases rendent toujours la main d'elles-mêmes, ce
        // point n'est donc pas atteint aujourd'hui. Il reste écrit — et correct : une
        // variable orpheline y attendait, qui aurait fait tomber le moteur le jour où
        // une phase de plus serait ajoutée. Et là encore, on restitue le travail des
        // outils plutôt qu'une phrase creuse.
        return $this->conclure(
            $dialecte,
            $request,
            JournalTokens::ISSUE_TOURS_EPUISES,
            count($sequenceOutils) + 1,
            $cumulInput,
            $cumulSortie,
            $sequenceOutils,
            new AiReply(
                $this->repliPrecis->depuis($resultatsOutils),
                refused: $refused,
                toolUsed: $toolUsed,
                actions: $actions,
                plansRefuses: $plansRefuses,
                chiffresDesOutils: ChiffreFantome::nombresDe($resultatsOutils),
            ),
        );
    }

    /**
     * LE PREMIER REGARD A-T-IL BUTÉ ? Alors un second vaut la peine d'être payé.
     *
     * « Quelle police a généré la prime la plus élevée ? », « quel assureur ? », « le
     * client de cette police ? » : toutes ces questions se répondent en deux temps —
     * chercher, lire, puis chercher à nouveau. Le moteur n'en accordait qu'un, et Ket
     * répondait « je n'ai pas trouvé » ou « précisez » là où un second appel aurait
     * suffi. L'utilisateur, lui, relançait à la main — au prix d'un message entier.
     *
     * MAIS PAS À CHAQUE FOIS. L'API est sans mémoire : un tour de plus réexpédie tout le
     * contexte, déclarations d'outils comprises (~72 Ko). Mesuré le 2026-08-10, cinq
     * tours ont consommé 188 000 jetons et vidé la fenêtre d'une minute pour toute la
     * conversation. Le second regard est donc RÉSERVÉ aux résultats qui appellent une
     * suite : une recherche vide, une question à trancher, des candidats ambigus, un
     * refus. Quand les données sont là, on écrit — comme avant, en deux appels.
     *
     * @param list<array{outil: string, data: array}> $resultats
     */
    private static function meriteUnSecondRegard(array $resultats): bool
    {
        foreach ($resultats as $resultat) {
            $data = $resultat['data'] ?? [];
            if (($data['aDemander'] ?? null) || ($data['ambigu'] ?? null) || ($data['refus'] ?? null)) {
                return true;
            }
            // Une liste vide ou un compte nul : la donnée existe peut-être ailleurs, sous
            // un autre nom, dans un autre périmètre. C'est exactement là que Ket rendait
            // les armes.
            if (array_key_exists('totalItems', $data) && (int) $data['totalItems'] === 0) {
                return true;
            }
            if (array_key_exists('count', $data) && (int) $data['count'] === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Point de sortie unique de traiter() : journalise le bilan du message puis
     * rend la réponse. Passer par ici garantit qu'AUCUN chemin de sortie ne
     * manque à la campagne de mesure — or ce sont justement les sorties
     * anormales (budget, blocage, tours épuisés) qui l'intéressent le plus.
     *
     * @param list<string> $sequenceOutils
     */
    /**
     * UN OUTIL D'ÉCRITURE A-T-IL RÉELLEMENT ÉTÉ APPELÉ pendant ce message ?
     *
     * C'est la moitié qui manque au diagnostic : armer l'écriture n'est un gaspillage
     * que si elle n'a pas servi. L'appartenance se lit dans le catalogue — jamais dans
     * une liste recopiée ici, comme partout ailleurs dans ce code.
     *
     * @param list<string> $sequenceOutils
     */
    /** La trousse retenue pour le message en cours, et ce qui l'a imposée. */
    private ?\App\Ai\Trousse\Trousse $trousseDuMessage = null;

    private string $declencheurDuMessage = 'aucun';

    /** Laquelle des alternatives de VERBES_ACTION a mordu — vide si ce n'est pas elle. */
    private string $motArmeurDuMessage = '';

    private function uneEcritureAEuLieu(array $sequenceOutils): bool
    {
        foreach ($sequenceOutils as $nom) {
            if ($this->trousseCatalogue->estOutilDEcriture((string) $nom)) {
                return true;
            }
        }

        return false;
    }

    private function conclure(
        DialecteDuFil $dialecte,
        AiRequest $request,
        string $issue,
        int $tours,
        int $cumulEntree,
        int $cumulSortie,
        array $sequenceOutils,
        AiReply $reply,
    ): AiReply {
        $this->journal->message(
            $request,
            $dialecte->nom(),
            $dialecte->modeleCourant(),
            $issue,
            $tours,
            $cumulEntree,
            $cumulSortie,
            $sequenceOutils,
            // ── DE QUOI JUGER L'AIGUILLAGE SUR PIÈCES ───────────────────────────
            //
            // La trousse d'ÉCRITURE coûte cinquante-deux déclarations d'outils au lieu
            // de trente-trois, plus vingt-sept kilo-octets de protocoles d'écriture :
            // plus de la moitié du payload d'un tour. Le rapport de campagne dit ce
            // que cela COÛTE, mais ni QUEL déclencheur l'a réclamée, ni si une écriture
            // a seulement eu lieu.
            //
            // Ces trois champs répondent aux deux questions qui manquent. Croisés sur
            // quelques jours, ils diront quel déclencheur arme l'écriture pour rien —
            // et resserrer cessera d'être un pari.
            [
                'trousse'            => $this->trousseDuMessage?->libelle() ?? '?',
                'declencheur'        => $this->declencheurDuMessage,
                // Le MOT, et pas seulement le signal : sans lui, resserrer la liste de
                // verbes revient à en retirer au hasard.
                'mot_armeur'         => $this->motArmeurDuMessage,
                'ecriture_effective' => $this->uneEcritureAEuLieu($sequenceOutils),
            ],
        );

        return $reply;
    }

    /**
     * Ce que Ket dit quand elle doit s'arrêter faute de débit.
     *
     * L'ancien message (« votre demande m'a obligé à enchaîner trop de
     * recherches… découpez-la ») rejetait sur l'utilisateur une limite qui
     * n'était pas la sienne — et, pire, se déclenchait le plus souvent alors que
     * le fournisseur aurait laissé passer (cap par message, cf. BudgetDebit).
     * Le débit étant partagé par tout le cabinet, la seule chose honnête est de
     * le dire et d'annoncer un délai réel.
     */
    private function messageDebitSature(?int $attente): string
    {
        // Attente impossible : même une fenêtre entièrement vide ne suffirait
        // pas. Ce n'est plus une question de patience mais de poids du fil —
        // c'est la seule situation où découper sert vraiment à quelque chose.
        if ($attente === null) {
            return 'Le fil de cette conversation est devenu trop lourd : je dois le renvoyer en '
                . "entier à mon moteur à chaque recherche, et il dépasse désormais ce qu'il accepte "
                . 'en une minute. Ouvrez une nouvelle conversation (ou détachez quelques pièces '
                . 'jointes) et reposez-moi la question : je repartirai sur un contexte léger.';
        }

        return 'Mon moteur a atteint sa limite de débit pour la minute en cours — une limite '
            . 'partagée par tout le cabinet, pas un défaut de votre demande. '
            . sprintf('Relancez-la dans %d secondes ', max(1, $attente))
            . 'et je la termine : ce que j\'ai déjà rassemblé reste dans le fil.';
    }

    /**
     * CE QUE KET DIT QUAND LE FOURNISSEUR A DIT NON, et qu'aucun modèle ne reste.
     *
     * ⚠ NE PAS RÉUTILISER messageDebitSature(null) ICI — c'est l'erreur qu'un test a
     * attrapée le 2026-09-20. Sans délai annoncé, ce message-là accuse LE POIDS DU FIL
     * et invite à ouvrir une nouvelle conversation : un diagnostic faux, et un conseil
     * inutile. Notre compteur local, lui, sait qu'une fenêtre vide ne suffirait pas ;
     * un 429 sans RetryInfo ne dit rien de tel — seulement que le quota du moment est
     * consommé.
     */
    /**
     * CE QU'ON DIT QUAND ON A RENONCÉ À ATTENDRE PLUS LONGTEMPS.
     *
     * Ni excuse ni jargon : l'utilisateur n'a pas à savoir ce qu'est un budget de
     * durée. Et surtout, pas un mot qui rejette la faute sur sa demande — c'est notre
     * moteur qui a traîné, pas sa question qui était mauvaise.
     */
    private const TROP_LONG = 'Mon moteur met trop de temps à répondre en ce moment. Je préfère '
        . 'vous le dire plutôt que de vous laisser attendre : reposez-moi la question, '
        . 'elle devrait aboutir normalement.';

    private function messageQuotaEpuise(?int $delai): string
    {
        if ($delai !== null) {
            return $this->messageDebitSature($delai);
        }

        return 'Mon moteur a épuisé son quota pour le moment — une limite partagée par tout le '
            . 'cabinet, pas un défaut de votre demande. Reposez-moi la question dans quelques '
            . 'minutes : ce que j’ai déjà rassemblé reste dans le fil.';
    }
}
