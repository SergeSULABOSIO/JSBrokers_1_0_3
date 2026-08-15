<?php

namespace App\Ai\Traitement;

use App\Ai\Action\ValidateurDActions;
use App\Ai\AiContextBuilder;
use App\Ai\AiEngineFailure;
use App\Ai\AiReply;
use App\Ai\Comprehension\ClarificationEnAttente;
use App\Ai\Document\DocumentEnAttente;
use App\Ai\Engine\AiEngineInterface;
use App\Ai\Mutation\PlanEnAttente;
use App\Ai\Programme\ProgrammeEnCours;
use App\Ai\Programme\ProgrammeRunner;
use App\Ai\Telemetrie\JournalTokens;
use App\Entity\AssistantMessage;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * @file Le TRAITEMENT d'un message de l'assistant : moteur IA, garde-fous,
 * persistance de la réponse.
 * @description Extrait tel quel de AssistantIaController::traiterMessage(), dont
 * il constitue la seconde moitié — celle qui est LENTE (20 à 40 s).
 *
 * POURQUOI CETTE CLASSE EXISTE. L'envoi d'un message se scinde en deux moments
 * qui n'ont ni la même durée ni les mêmes dépendances :
 *
 *  1. L'ACCEPTATION (restée dans le contrôleur) : gardes d'accès, validation du
 *     corps, métrage des tokens, persistance de la question. Rapide, et surtout
 *     c'est elle SEULE qui décide les codes d'erreur 400/402/403/404 — un
 *     message accepté puis refusé trente secondes plus tard serait un autre
 *     produit.
 *  2. LE TRAITEMENT (ici) : le moteur, les garde-fous, la réponse. Long, et
 *     n'ayant besoin de RIEN de la requête HTTP.
 *
 * Cette frontière n'est pas un arbitrage de confort : c'est une propriété du
 * code. Aucune des quatre variables dont ce traitement a besoin ne vient de la
 * requête — toutes se redérivent du message utilisateur lui-même. C'est ce qui
 * rend le pipeline invocable hors HTTP (cf. AssistantSmokeCommand, qui appelle
 * déjà le moteur en ligne de commande) et donc déportable dans un worker.
 *
 * ⚠️ IDENTITÉ. Ce service ne pose PAS de jeton de sécurité. Appelé depuis une
 * requête HTTP, il hérite de celui de la session ; appelé hors HTTP, l'appelant
 * doit en poser un (IdentiteDuTraitement) — plusieurs services atteints par les
 * outils IA lisent Security::getUser(), et certains, comme ServiceMonnaies,
 * répondent « aucune monnaie » EN SILENCE plutôt que de lever.
 */
final class TraitementDuMessage
{
    public function __construct(
        private readonly AiContextBuilder $contextBuilder,
        private readonly AiEngineInterface $aiEngine,
        // Écarte les actions que le navigateur ne saurait pas exécuter, et les
        // journalise : sans lui, elles partaient pour être ignorées en silence.
        private readonly ValidateurDActions $validateurDActions,
        private readonly ProgrammeEnCours $programmeEnCours,
        private readonly ProgrammeRunner $programmeRunner,
        private readonly JournalTokens $journalTokens,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Interroge le moteur pour CE message utilisateur, puis persiste la réponse.
     *
     * Le message doit déjà être rattaché à sa conversation (c'est l'acceptation
     * qui l'y met) : tout le contexte du traitement s'en déduit, et le fil que
     * lira le moteur contient donc bien la question à laquelle il répond.
     *
     * Ne lève jamais pour une panne du moteur : l'échec produit une réponse
     * d'excuse persistée, jamais un 500 — la conversation reste utilisable.
     */
    public function repondre(AssistantMessage $messageUser): AssistantMessage
    {
        $conversation = $messageUser->getConversation();
        $entreprise = $conversation->getEntreprise();
        $invite = $conversation->getInvite();

        // Le moteur réel (API Claude/Gemini) peut échouer (réseau, quota, clé) :
        // la conversation reste utilisable — réponse d'excuse persistée (honnête
        // sur la cause quand elle est identifiable, cf. AiEngineFailure), pas de 500.
        $erreurMoteur = false;
        // Construite DANS le try (une construction qui échoue doit produire
        // l'excuse, pas un 500) mais déclarée avant, pour rester disponible au
        // moment de journaliser l'échec.
        $aiRequest = null;
        // Durée réellement passée avec le moteur, mesurée ici parce que c'est le
        // seul endroit qui voit l'aller ET le retour. Elle figure au récapitulatif :
        // « 6,2 s » explique après coup une attente que l'utilisateur a subie.
        $debutMoteur = microtime(true);
        try {
            $aiRequest = $this->contextBuilder->build($entreprise, $invite, $conversation);
            $reply = $this->aiEngine->reply($aiRequest);
        } catch (\Throwable $e) {
            $quotaEpuise = AiEngineFailure::estLimiteDeDebit($e);
            // Sur un 429, le corps de la réponse nomme le quota violé et le délai
            // d'attente : sans ces champs le journal ne dit que « HTTP 429 » et la
            // saturation reste indiagnosticable.
            $detailsQuota = $quotaEpuise ? AiEngineFailure::detailsPourJournal($e) : [];
            $this->logger->error('Assistant IA : le moteur a échoué.', [
                'exception' => $e,
                'engine'    => $this->aiEngine->name(),
            ] + ($quotaEpuise ? ['quota' => $detailsQuota] : []));

            // Les tours déjà effectués ont été payés : ils doivent figurer dans la
            // campagne de mesure, sans quoi les messages les plus coûteux — ceux
            // qui butent sur le quota — seraient précisément ceux qui manquent.
            if ($aiRequest !== null) {
                $this->journalTokens->messageInterrompu(
                    $aiRequest,
                    $this->aiEngine->name(),
                    $this->aiEngine->modelName(),
                    $quotaEpuise ? JournalTokens::ISSUE_QUOTA_FOURNISSEUR : JournalTokens::ISSUE_ECHEC_TECHNIQUE,
                    $detailsQuota,
                );
            }

            $erreurMoteur = true;
            $reply = new AiReply(AiEngineFailure::messagePour($e));
        }

        // Un plan de mutation préparé par Ket (uiAction ket-mutation.review) est
        // STOCKÉ côté serveur (meta du message) : l'endpoint d'exécution le
        // rechargera et le re-validera intégralement — jamais de confiance au client.
        // Un seul plan par réponse : si le moteur a appelé deux fois l'outil de
        // préparation dans le MÊME tour (le verrou de conversation ne voit que les
        // tours précédents), seule la première barre de décision est conservée —
        // la seconde serait orpheline (aucun plan stocké derrière elle).
        $actions = PlanEnAttente::limiterAUnSeulPlan($reply->actions ?? []);
        // Même règle pour le plan de DOCUMENT : un message ne stocke qu'une spec,
        // une seconde barre « Valider et produire » serait orpheline.
        $actions = DocumentEnAttente::limiterAUnSeulPlan($actions);

        // CONTRAT AVEC LE NAVIGATEUR : il ignore silencieusement ce qu'il ne
        // reconnaît pas. Une action d'un type inconnu, ou privée d'un champ dont son
        // handler a besoin, partirait donc pour ne rien produire — l'utilisateur
        // attendrait un bouton qui n'arrive jamais, sans la moindre trace. On l'écarte
        // ici, et surtout on la JOURNALISE.
        $actions = $this->validateurDActions->filtrer($actions, $conversation->getId());

        $mutationPlan = $this->extraireMutationPlan($actions);
        $documentPlan = DocumentEnAttente::planStockable($actions);

        // GARDE-FOU anti-plan FANTÔME. Le modèle peut décrire un plan, un budget,
        // voire affirmer qu'un « bouton de validation » est actif — le tout dans sa
        // seule PROSE, sans avoir appelé preparer_operations. Aucune action n'est
        // alors émise : aucun bouton ne s'affiche, et l'utilisateur attend une
        // décision qui ne viendra jamais (au pire le modèle invente ensuite un « bug
        // d'interface »). Quand la prose simule une décision alors qu'AUCUN plan n'a
        // été préparé ce tour-ci NI n'est en attente dans le fil, on émet un signal
        // AUTORITAIRE (serveur) qui dit la vérité — indépendamment de la prose.
        //
        // Le test porte sur TOUTE décision — d'écriture ou de document. Ne regarder
        // que le plan d'écriture retournerait le garde-fou contre le cas qu'il
        // protège : un VRAI plan de document, légitimement annoncé en prose
        // (« budget », « prêt à être validé »), déclencherait l'avertissement
        // « aucun plan n'est en attente » juste au-dessus de sa propre barre.
        //
        // DEUX PREUVES, ET NON UNE. La première est LEXICALE (proseSimuleUneDecision) :
        // conservatrice par construction, elle ne voit que les revendications
        // explicites. Le 2026-08-11 elle est passée à côté de deux messages qui
        // affichaient un tableau de plan complet et un budget recopié du tour
        // précédent, avec « Veuillez valider ce plan » — l'utilisateur a cherché un
        // bouton pendant deux tours. La seconde preuve est STRUCTURELLE : le serveur
        // SAIT qu'un outil de plan a tourné et qu'il a refusé, et il sait pourquoi.
        // Croisée avec une prose qui montre un plan, elle est sans appel — et surtout
        // elle permet de dire à l'utilisateur ce qui MANQUE, au lieu de l'informer
        // qu'il n'y a rien à valider.
        // Une CLARIFICATION est une décision en attente comme une autre — et l'oublier
        // retournerait le garde-fou contre le cas qu'il protège. Sa prose énumère par
        // construction ce que Ket croit devoir faire (« créer un client, puis une
        // piste… ») : sans ce désarmement, toute demande de confirmation s'afficherait
        // sous un avertissement « aucun plan n'est en attente », c'est-à-dire un
        // démenti de la question qu'elle est justement en train de poser.
        $clarification = ClarificationEnAttente::stockable($actions);

        $planRefuse = $reply->plansRefuses[0] ?? null;
        $aucuneDecision = $mutationPlan === null
            && $documentPlan === null
            && $clarification === null
            && !$reply->refused
            && !DocumentEnAttente::aUneDecisionEnAttente($conversation);
        $planFantome = PlanEnAttente::estUnPlanFantome(
            (string) $reply->content,
            $aucuneDecision,
            $planRefuse !== null,
        );
        if ($planFantome) {
            $actions[] = array_filter([
                'type'  => PlanEnAttente::ACTION_ABSENT,
                // Le motif vient de l'OUTIL, pas d'une phrase générique : « il manque
                // la date de la dépense » est actionnable, « aucun plan n'est en
                // attente » ne l'est pas.
                'motif' => $planRefuse['motif'] ?? null,
            ]);
            $this->logger->warning('Assistant IA : plan fantôme détecté (prose sans action de mutation).', [
                'conversation' => $conversation->getId(),
                'engine'       => $this->aiEngine->name(),
                'outilRefuse'  => $planRefuse['outil'] ?? null,
                'motif'        => $planRefuse['motif'] ?? null,
            ]);
        }

        // EXÉCUTION FANTÔME — le mensonge le plus coûteux. Le 2026-08-12, Ket a
        // annoncé « Le dossier complet a été enregistré avec succès dans la base de
        // données », récapitulatif détaillé à l'appui, alors que la base ne contenait
        // rien : aucun plan n'avait été présenté, donc aucune validation, donc aucune
        // écriture. Une écriture ne peut avoir lieu que par l'endpoint d'exécution,
        // après un clic — jamais pendant un tour de chat. Si donc la prose affirme un
        // enregistrement alors que le fil n'en porte aucun, l'utilisateur doit
        // l'apprendre ICI, et pas le mois prochain en cherchant sa police.
        $executionFantome = PlanEnAttente::estUneExecutionFantome(
            (string) $reply->content,
            $aucuneDecision,
            !PlanEnAttente::aUnPlanExecute($conversation),
        );
        if ($executionFantome) {
            $actions[] = ['type' => PlanEnAttente::ACTION_NON_EXECUTE];
            $this->logger->warning('Assistant IA : exécution fantôme détectée (enregistrement affirmé, jamais fait).', [
                'conversation' => $conversation->getId(),
                'engine'       => $this->aiEngine->name(),
            ]);
        }

        $messageAssistant = (new AssistantMessage())
            ->setRole(AssistantMessage::ROLE_ASSISTANT)
            ->setContenu($reply->content)
            ->setMeta(array_filter([
                'engine'       => $this->aiEngine->name(),
                'tool'         => $reply->toolUsed,
                'refus'        => $reply->refused ?: null,
                'erreur'       => $erreurMoteur ?: null,
                'actions'      => $actions ?: null,
                'mutationPlan' => $mutationPlan,
                // Spec du document FIGÉE au moment où le budget est annoncé : la
                // production ne fera plus que la rendre. C'est ce qui garantit que
                // le fichier livré est exactement celui qui a été chiffré.
                DocumentEnAttente::CLE_PLAN => $documentPlan,
                // La reformulation à confirmer, persistée comme un plan : elle doit
                // survivre au rechargement, sinon un F5 laisserait la question seule
                // à l'écran, sans les deux boutons qui permettent d'y répondre.
                ClarificationEnAttente::CLE_META => $clarification,
                // Trace du garde-fou : réaffiche l'avertissement autoritaire après
                // un rechargement de page (F5), comme les statuts de plan — AVEC son
                // motif, pour dire la même chose en direct et après rechargement.
                'mutationAbsent' => $planFantome ? ['motif' => $planRefuse['motif'] ?? null] : null,
                // Même trace, pour le démenti d'exécution : il doit survivre au F5,
                // sinon un rechargement laisserait le récapitulatif mensonger seul
                // à l'écran — exactement l'état qu'on corrige.
                'executionAbsente' => $executionFantome ?: null,
                // Bandeau d'avancement quand ce plan est l'étape d'un PROGRAMME
                // (« étape 1 sur 3 ») : persisté pour survivre au rechargement,
                // au même titre que le plan lui-même.
                'programme'      => $this->extraireBandeauProgramme($actions),
                // Ce que le message a coûté au moteur, tel qu'il vient d'être montré
                // au fil de l'eau. Persisté pour la même raison que les clés
                // ci-dessus : sans lui, un F5 effacerait le récapitulatif que
                // l'utilisateur venait de lire. Null quand le moteur n'a pas de
                // télémétrie (simulé, Anthropic) — array_filter l'écarte alors.
                'activite'       => $this->activiteDuMessage($debutMoteur),
            ]));
        $conversation->addMessage($messageAssistant);

        // Le titre ne se fabrique plus à partir du premier message. Il en
        // reprenait quatre-vingts caractères — une phrase entière dans un
        // onglet, qui étirait la barre et restait figée pour toujours sur le
        // hasard de la première question. Une conversation sans titre choisi
        // s'affiche « CONV#135 » (AssistantConversation::libelle()), et
        // l'utilisateur la renomme d'un double-clic sur son onglet.

        $this->em->flush();

        // La PREMIÈRE étape d'un programme voyage sur ce message-ci (l'outil ne
        // fabrique pas de bulle supplémentaire pour elle) : on rattache l'étape à
        // son message maintenant qu'il a une identité. C'est ce lien, et lui seul,
        // qui permettra à l'exécution de savoir qu'elle vient de trancher une
        // étape — et donc d'enchaîner sur la suivante.
        $programmeCourant = $this->programmeEnCours->courant($conversation);
        if ($programmeCourant !== null) {
            $this->programmeRunner->attacherMessage($programmeCourant, $messageAssistant);
        }

        return $messageAssistant;
    }

    /**
     * Récapitulatif de ce que le message a coûté au moteur, prêt à être affiché.
     *
     * MÊME SOURCE que le fil en direct (le journal a compté les deux), donc aucun
     * risque que la ligne lue pendant l'attente et le récapitulatif lu après
     * racontent deux histoires différentes.
     *
     * @return array{appels: int, jetonsIa: int, secondes: float, etapes: list<array{cle: string, jetons: int}>}|null
     */
    private function activiteDuMessage(float $debutMoteur): ?array
    {
        $recap = $this->journalTokens->recapitulatif();
        if ($recap === null) {
            return null;
        }

        return $recap + ['secondes' => round(microtime(true) - $debutMoteur, 1)];
    }

    /**
     * Extrait le plan de mutation (plan + budget + exige-mdp) d'une éventuelle
     * action ket-mutation.review, pour stockage serveur. null si absent.
     *
     * Délègue à PlanEnAttente : la structure stockée est partagée avec la
     * préparation déterministe des étapes de programme (ProgrammeRunner), et les
     * deux chemins doivent écrire exactement la même chose.
     *
     * @param array<int, array> $actions
     */
    private function extraireMutationPlan(array $actions): ?array
    {
        return PlanEnAttente::planStockable($actions);
    }

    /**
     * Bandeau d'avancement du programme porté par une action de revue, ou null
     * quand le plan est isolé. Stocké à part du plan : le plan est ce qui sera
     * ÉCRIT, le bandeau est ce qui situe l'étape dans la série — deux choses
     * indépendantes, l'une n'ayant pas à polluer l'autre.
     *
     * @param array<int, array> $actions
     */
    private function extraireBandeauProgramme(array $actions): ?array
    {
        foreach ($actions as $action) {
            if (($action['type'] ?? null) === PlanEnAttente::ACTION_REVUE && is_array($action['programme'] ?? null)) {
                return $action['programme'];
            }
        }

        return null;
    }
}
