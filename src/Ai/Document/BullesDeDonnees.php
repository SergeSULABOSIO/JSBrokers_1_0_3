<?php

namespace App\Ai\Document;

use App\Ai\Export\MessageMarkdownParser;
use App\Ai\Mutation\PlanEnAttente;
use App\Entity\AssistantConversation;
use App\Entity\AssistantMessage;

/**
 * LES BULLES DU FIL QUI PORTENT DES DONNÉES — et qu'un document doit reprendre
 * telles quelles plutôt que résumer.
 *
 * ── L'incident qui a motivé cette classe ────────────────────────────────────────
 * Ket affiche un tableau de dix-huit lignes (paiements de primes, commissions,
 * taxes, réserve). L'utilisateur demande « produis-moi un rapport à partir de
 * cette réponse ». Le rapport sortait avec l'objet, l'introduction, les
 * définitions, la conclusion… et, à la place du tableau, une phrase : « le montant
 * total cumulé s'élève à 1 911 633,28 $ ». Le document ne contenait PAS ses
 * données.
 *
 * La parade existait pourtant — `sourceMessageId` sur preparer_document — mais
 * elle était INUTILISABLE : l'historique envoyé au modèle ne portait aucun
 * identifiant de message. On lui demandait de désigner une bulle par un numéro
 * qu'il n'avait jamais vu. Il faisait donc la seule chose qui lui restait :
 * réécrire de mémoire, et raboter pour tenir dans ses 4 096 jetons de sortie.
 *
 * Cette classe répond aux deux moitiés du problème :
 *   1. {@see AiContextBuilder} annote les bulles porteuses de données avec leur
 *      identifiant, ce qui rend `sourceMessageId` réellement adressable ;
 *   2. {@see \App\Ai\Tool\PreparerDocumentTool} s'en sert de FILET : si le
 *      document préparé ne contient aucune donnée alors que la bulle précédente
 *      en portait, le serveur la rattache lui-même.
 *
 * GRAMMAIRE PARTAGÉE, jamais une expression régulière maison : la détection passe
 * par {@see MessageMarkdownParser}, le même analyseur qui rend les bulles et
 * alimente les six rendus de document. Ce qui compte comme « un tableau » est donc,
 * par construction, ce que l'utilisateur VOIT comme un tableau.
 */
final class BullesDeDonnees
{
    /**
     * Bulles d'assistant examinées en remontant le fil. Large à dessein : dans le fil
     * du 11/08/2026, huit bulles séparaient le tableau des paiements de la demande de
     * rapport (quatre annonces de plan, quatre plans d'écriture). Une portée courte
     * aurait de nouveau laissé la donnée hors d'atteinte.
     */
    private const PORTEE_REMONTEE = 20;

    /** Bulles listées au catalogue : de quoi couvrir une séance de travail sans noyer le prompt. */
    private const MAX_CATALOGUE = 8;

    /** Longueur du résumé d'une bulle au catalogue — de quoi la RECONNAÎTRE, pas la relire. */
    private const RESUME_MAX = 90;

    public function __construct(
        private readonly MessageMarkdownParser $parser,
    ) {
    }

    /**
     * LE CATALOGUE DES BULLES REPRENABLES — et pourquoi il ne pouvait pas être un
     * marqueur dans l'historique.
     *
     * Première tentative : annoter chaque bulle porteuse de son identifiant, dans
     * l'historique. Elle a échoué pour une raison structurelle — l'historique
     * transmis au moteur est PLAFONNÉ à vingt messages. Le fil du 11/08/2026 le
     * montre : le tableau des paiements est le message 1628, les demandes de rapport
     * commencent à 1645, et la fenêtre ne couvre que 1633→1652. La bulle à reprendre
     * était HORS CHAMP, son marqueur avec elle. Ket a alors désigné le seul numéro
     * qui lui restait en tête, celui d'une chronologie sans rapport — et le rapport
     * est sorti avec les bonnes phrases et le mauvais tableau.
     *
     * Le catalogue vit donc dans le CONTEXTE SYSTÈME, où il est reconstruit à chaque
     * tour depuis la base, sans plafond d'historique. Il ne transporte pas les
     * tableaux — seulement de quoi les désigner : un identifiant, une date, une
     * première ligne. Quelques centaines de caractères pour la garantie qu'un
     * rapport peut toujours atteindre ses données.
     *
     * @return list<array{id: int, date: string, resume: string, tableaux: int}>
     */
    public function catalogue(?AssistantConversation $conversation): array
    {
        $catalogue = [];
        foreach ($this->porteuses($conversation) as $message) {
            $catalogue[] = [
                'id'       => (int) $message->getId(),
                'date'     => $message->getCreatedAt()?->format('d/m/Y H:i') ?? '',
                'resume'   => $this->resume((string) $message->getContenu()),
                'tableaux' => $this->compterLesTableaux((string) $message->getContenu()),
            ];
            if (count($catalogue) >= self::MAX_CATALOGUE) {
                break;
            }
        }

        return $catalogue;
    }

    /**
     * La première ligne utile d'une bulle : celle qui dit ce que le tableau montre.
     * Les émojis de tête sont conservés — ce sont eux que l'utilisateur reconnaît.
     */
    private function resume(string $contenu): string
    {
        foreach (preg_split('/\R/', trim($contenu)) ?: [] as $ligne) {
            $ligne = trim($ligne);
            if ($ligne === '' || str_contains($ligne, '|')) {
                continue;
            }

            return mb_strlen($ligne) > self::RESUME_MAX
                ? mb_substr($ligne, 0, self::RESUME_MAX - 1) . '…'
                : $ligne;
        }

        return 'Tableau de données';
    }

    /**
     * Combien de blocs de données porte ce texte ?
     *
     * Un bloc ```chart compte : le rendu de document le transforme déjà en tableau
     * (cf. RapportAssembleur), donc il est tout aussi reprenable — et tout aussi
     * perdu s'il est résumé en une phrase.
     */
    public function compterLesTableaux(string $markdown): int
    {
        if (trim($markdown) === '') {
            return 0;
        }

        $compte = 0;
        foreach ($this->parser->analyser($markdown) as $bloc) {
            if (in_array($bloc['type'] ?? '', ['tableau', 'chart'], true)) {
                ++$compte;
            }
        }

        return $compte;
    }

    public function porteDesDonnees(string $markdown): bool
    {
        return $this->compterLesTableaux($markdown) > 0;
    }

    /**
     * La bulle de DONNÉES la plus récente du fil — celle dont un rapport doit
     * emporter le tableau.
     *
     * ── Pourquoi elle ne peut PAS être « la dernière bulle » ────────────────────
     * C'était la première version, et elle ne s'est jamais déclenchée en vrai. Dans
     * une conversation réelle, la bulle qui précède « produis-moi ce rapport » est
     * presque toujours l'ANNONCE DU PLAN précédent (« Le rapport a été préparé sous
     * format Word… ») : aucun tableau. Le fil du 11/08/2026 le montre sans appel —
     * quatre demandes de rapport d'affilée, et à chaque fois la bulle immédiatement
     * antérieure était une annonce de plan. Le tableau de données, lui, était huit
     * messages plus haut.
     *
     * On REMONTE donc le fil jusqu'à trouver une bulle porteuse, en sautant celles
     * qui n'ont rien à reprendre.
     *
     * ── Ce qu'on saute, et pourquoi c'est capital ───────────────────────────────
     * Les bulles qui portent un PLAN (d'écriture ou de document) sont écartées même
     * quand elles contiennent un tableau : ce tableau est l'APERÇU d'un plan, pas un
     * résultat. Le rattacher à un rapport y ferait figurer « ce que Ket s'apprête à
     * enregistrer » sous un titre annonçant des paiements — une donnée fausse, et
     * d'autant plus crédible qu'elle est bien mise en forme. Dans le fil du
     * 11/08/2026, les deux seules bulles à tableau à portée étaient exactement cela.
     *
     * Le balayage est BORNÉ : au-delà, on ne parle plus de « cette réponse » mais
     * d'un tableau oublié en haut du fil.
     */
    public function dernierePorteuse(?AssistantConversation $conversation): ?AssistantMessage
    {
        return $this->porteuses($conversation)[0] ?? null;
    }

    /**
     * Les bulles porteuses de données, de la PLUS RÉCENTE à la plus ancienne.
     *
     * Source unique du filet et du catalogue : ils doivent parler des mêmes bulles,
     * sans quoi le prompt en annoncerait une que le serveur n'irait jamais chercher.
     *
     * @return list<AssistantMessage>
     */
    private function porteuses(?AssistantConversation $conversation): array
    {
        $candidates = [];
        foreach ($conversation?->getMessages() ?? [] as $message) {
            if ($message->getRole() !== AssistantMessage::ROLE_ASSISTANT
                || $message->getId() === null
                || trim((string) $message->getContenu()) === '') {
                continue;
            }
            $candidates[] = $message;
        }

        $porteuses = [];
        foreach (array_slice(array_reverse($candidates), 0, self::PORTEE_REMONTEE) as $message) {
            if ($this->porteUnPlan($message)) {
                continue;
            }
            if ($this->porteDesDonnees((string) $message->getContenu())) {
                $porteuses[] = $message;
            }
        }

        return $porteuses;
    }

    /**
     * Cette bulle présente-t-elle un PLAN — d'écriture ou de document ? Son tableau,
     * s'il y en a un, décrit une intention et non un résultat.
     */
    private function porteUnPlan(AssistantMessage $message): bool
    {
        $meta = $message->getMeta() ?? [];

        return PlanEnAttente::porteUnPlan($meta) || DocumentEnAttente::porteUnPlan($meta);
    }
}
