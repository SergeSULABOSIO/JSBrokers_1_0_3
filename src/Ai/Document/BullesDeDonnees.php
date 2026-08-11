<?php

namespace App\Ai\Document;

use App\Ai\Export\MessageMarkdownParser;
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
    public function __construct(
        private readonly MessageMarkdownParser $parser,
    ) {
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
     * La DERNIÈRE bulle de l'assistant, et seulement si elle porte des données.
     *
     * Pourquoi la dernière et pas n'importe laquelle : « produis-moi un rapport à
     * partir de cette réponse » désigne la bulle qui précède immédiatement la
     * demande. Remonter plus haut dans le fil serait deviner. Ce choix garde le
     * filet étroit — il ne se déclenche que sur le cas qu'il vise.
     *
     * Au moment où l'outil s'exécute, la réponse du tour EN COURS n'est pas encore
     * enregistrée : la dernière bulle assistant du fil est donc bien la précédente.
     */
    public function dernierePorteuse(?AssistantConversation $conversation): ?AssistantMessage
    {
        $derniere = null;
        foreach ($conversation?->getMessages() ?? [] as $message) {
            if ($message->getRole() === AssistantMessage::ROLE_ASSISTANT
                && trim((string) $message->getContenu()) !== '') {
                $derniere = $message;
            }
        }

        if ($derniere === null || !$this->porteDesDonnees((string) $derniere->getContenu())) {
            return null;
        }

        return $derniere;
    }
}
