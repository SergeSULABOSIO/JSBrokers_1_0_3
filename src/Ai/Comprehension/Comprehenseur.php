<?php

namespace App\Ai\Comprehension;

use App\Ai\AiRequest;
use App\Ai\Debit\BudgetDebit;
use App\Ai\Mutation\PlanEnAttente;
use App\Ai\Programme\ProgrammeEnCours;
use App\Ai\Telemetrie\JournalTokens;
use Psr\Log\LoggerInterface;

/**
 * LE PREMIER DES TROIS APPELS : établir ce que l'utilisateur veut dire, avant de
 * décider quoi que ce soit.
 *
 * Ket se trompait souvent de demande. La consigne « comprendre avant d'agir »
 * existait pourtant — mais noyée dans le prompt de planification, au milieu de
 * quarante déclarations d'outils et de 27 Ko de protocoles d'écriture. Comprendre y
 * était une tâche parmi douze, et c'est celle qui perdait. Elle a donc son propre
 * appel : petit, sans aucun outil, sur un modèle léger.
 *
 * L'ÉCONOMIE, PARCE QUE C'EST LA VRAIE QUESTION. Ce troisième appel n'en est un que
 * sur les messages qu'il ne change pas (+10 à +17 %). Sur un message ambigu, il
 * REMPLACE les deux gros : au lieu de payer une planification complète pour une
 * réponse à côté — puis la relance de l'utilisateur, soit quatre appels —, on paie
 * le plus petit et on s'arrête. Il tourne en outre sur un modèle distinct, dont
 * Google tient un compteur de débit séparé : il ne mange pas la fenêtre du modèle
 * principal.
 *
 * FAIL-OPEN, SANS EXCEPTION. Panne, quota, JSON illisible, débit indisponible : on
 * conclut que la demande est claire et on laisse passer. Le comprenant est là pour
 * améliorer une réponse, jamais pour empêcher qu'il y en ait une — bloquer
 * l'utilisateur parce que notre garde-fou est tombé serait pire que le mal qu'il
 * corrige.
 */
final class Comprehenseur
{
    /**
     * Assez pour une intention de trois phrases et quelques questions courtes. Le
     * modèle n'a rien d'autre à écrire : un plafond haut n'achèterait ici que du
     * raisonnement interne facturé.
     */
    private const MAX_OUTPUT_TOKENS = 700;

    /**
     * Réponses par lesquelles un utilisateur ACQUIESCE. Elles ne veulent rien dire
     * seules, et tout dire après le tour précédent : les soumettre au comprenant,
     * c'est lui garantir une fausse ambiguïté sur le message le plus clair du fil.
     */
    private const ACQUIESCEMENTS = '/^(oui|ok|okay|d\'accord|daccord|je confirme|confirme|'
        . 'vas.?y|allez.?y|go|c\'est bon|parfait|exact|tout à fait|continue|poursuis)[\s.!]*$/iu';

    public function __construct(
        // LE FOURNISSEUR, derrière un contrat : cette classe ne sait plus à qui elle
        // parle. Tout ce qu'elle garde — les cas où le serveur sait déjà, le garde-fou
        // anti-chiffre inventé, la lecture de la conclusion, le journal, le fail-open —
        // n'a jamais rien eu de gémino-spécifique.
        private readonly AppelDeComprehension $appel,
        private readonly ProgrammeEnCours $programmeEnCours,
        private readonly BudgetDebit $budget,
        private readonly JournalTokens $journal,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function modelName(): string
    {
        return $this->appel->modele();
    }

    public function comprendre(AiRequest $request): DemandeComprise
    {
        $debut = microtime(true);
        $brut = $request->lastUserMessage();

        if ($this->serveurSaitDeja($request)) {
            return $this->journaliser($request, DemandeComprise::claire($brut, DemandeComprise::ORIGINE_COURT_CIRCUIT), 0, $debut);
        }

        // Aucune clé pour ce fournisseur : la phase n'existe pas, et c'est tout. La
        // demande part telle quelle, exactement comme avant qu'on l'invente.
        if (!$this->appel->estDisponible()) {
            return $this->journaliser($request, DemandeComprise::claire($brut, DemandeComprise::ORIGINE_REPLI), 0, $debut);
        }

        // On ne PATIENTE jamais avant cet appel. « symfony serve » n'a qu'un worker
        // php-cgi : une requête qui dort fige toute l'application. Et faire attendre
        // l'utilisateur pour une phase qui ne fait qu'améliorer sa réponse serait un
        // marché perdant.
        if ($this->budget->secondesAvantLiberation($this->appel->cleDeDebit(), self::MAX_OUTPUT_TOKENS) !== 0) {
            return $this->journaliser($request, DemandeComprise::claire($brut, DemandeComprise::ORIGINE_REPLI), 0, $debut);
        }

        try {
            ['texte' => $texte, 'tokens' => $tokens] = $this->appel->conclure($request);
        } catch (\Throwable $e) {
            $this->logger->warning('Assistant IA : la phase de compréhension a échoué, la demande passe telle quelle.', [
                'exception'   => $e,
                'fournisseur' => $this->appel->nom(),
                'modele'      => $this->appel->modele(),
            ]);

            return $this->journaliser($request, DemandeComprise::claire($brut, DemandeComprise::ORIGINE_REPLI), 0, $debut);
        }

        return $this->journaliser($request, $this->interpreter($texte, $request), $tokens, $debut);
    }

    /**
     * Les cas où le SERVEUR connaît déjà l'intention — aucun appel, aucun token.
     *
     * Ce sont les mêmes signaux structurels que l'aiguillage de trousse : ils ne
     * devinent rien, ils CONSTATENT l'état du fil.
     */
    private function serveurSaitDeja(AiRequest $request): bool
    {
        $conversation = $request->scope->conversation;

        // Une barre de décision attend une réponse : « je confirme » porte tout son
        // sens, et reformuler une validation n'aurait aucun objet.
        if (PlanEnAttente::aUnPlanEnAttente($conversation)) {
            return true;
        }
        if ($this->programmeEnCours->courant($conversation) !== null) {
            return true;
        }
        // GARDE ANTI-BOUCLE. Le tour précédent était déjà une clarification : reposer
        // la question enfermerait l'utilisateur dans un dialogue de sourds bien pire
        // que la mécompréhension d'origine. On ne clarifie JAMAIS deux fois de suite.
        if (ClarificationEnAttente::enAttente($conversation)) {
            return true;
        }
        // Un acquiescement, quand il y a bien quelque chose à acquiescer.
        if ($conversation?->dernierMessageAssistant() !== null
            && preg_match(self::ACQUIESCEMENTS, trim($request->lastUserMessage())) === 1) {
            return true;
        }

        return false;
    }

    /**
     * Lit la conclusion du fournisseur : un JSON, quel qu'en soit l'emballage.
     */
    private function interpreter(string $texte, AiRequest $request): DemandeComprise
    {
        $brut = $request->lastUserMessage();

        // Chez Google, le tour qui porte les outils ne peut pas imposer de schéma de
        // sortie (le proto refuse les deux ensemble) : quand il conclut directement,
        // son JSON arrive parfois enveloppé dans une clôture markdown. On la retire —
        // refuser une réponse juste pour trois caractères de décoration serait
        // absurde. Chez Anthropic la sortie est structurée par un outil et n'a jamais
        // cet emballage ; le nettoyage ne lui coûte rien.
        $texte = trim($texte);
        if (str_starts_with($texte, '```')) {
            $texte = trim((string) preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $texte));
        }

        $sortie = json_decode($texte, true);
        $intention = is_array($sortie) ? trim((string) ($sortie['intention'] ?? '')) : '';

        // Sortie inexploitable ou vide : on n'a rien appris, on ne bloque rien.
        if (!is_array($sortie) || $intention === '') {
            return DemandeComprise::claire($brut, DemandeComprise::ORIGINE_REPLI);
        }

        // GARDE ANTI-DÉRIVE. Un modèle qui reformule invente des chiffres — c'est la
        // faute du « budget fabriqué » du 2026-08-12, sous une autre forme. Un montant
        // ou une date apparus de nulle part dans l'intention la disqualifient
        // ENTIÈREMENT : mieux vaut la demande brute, que personne n'a réécrite.
        if ($this->inventeUnChiffre($intention, $request)) {
            $this->logger->warning('Assistant IA : reformulation écartée, elle porte un chiffre absent du fil.', [
                'intention' => mb_substr($intention, 0, 200),
            ]);

            return DemandeComprise::claire($brut, DemandeComprise::ORIGINE_REPLI);
        }

        return ($sortie['claire'] ?? true) === false
            ? DemandeComprise::aClarifier($intention, (array) ($sortie['questions'] ?? []))
            : DemandeComprise::claire($intention);
    }

    /**
     * L'intention porte-t-elle un nombre qu'aucun message récent ne contient ?
     *
     * Les séparateurs de milliers sont neutralisés des deux côtés : « 12 000 » dicté
     * et « 12000 » reformulé sont le même montant, et croire le contraire ferait
     * rejeter les reformulations justes.
     */
    private function inventeUnChiffre(string $intention, AiRequest $request): bool
    {
        $sansEspaces = static fn (string $t): string => (string) preg_replace('/[\s\x{00A0}.,\']+/u', '', $t);

        $recent = '';
        foreach (array_slice($request->messages, -3) as $message) {
            $recent .= ' ' . (string) ($message['content'] ?? '');
        }
        $recent = $sansEspaces($recent);

        preg_match_all('/\d{2,}/', $sansEspaces($intention), $nombres);
        foreach ($nombres[0] as $nombre) {
            if (!str_contains($recent, $nombre)) {
                return true;
            }
        }

        return false;
    }

    private function journaliser(AiRequest $request, DemandeComprise $comprise, int $tokens, float $debut): DemandeComprise
    {
        $this->journal->comprehension(
            $request,
            $this->appel->modele(),
            $comprise->claire ? 'claire' : 'a_clarifier',
            $comprise->origine,
            $tokens,
            (int) round((microtime(true) - $debut) * 1000),
        );

        return $comprise;
    }
}
