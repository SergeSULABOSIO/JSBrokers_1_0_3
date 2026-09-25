<?php

namespace App\Ai\Comprehension;

use App\Ai\AiRequest;
use App\Ai\Fournisseur\Fournisseur;

/**
 * L'APPEL au fournisseur pour la phase de compréhension — et rien d'autre.
 *
 * Tout ce qui fait la valeur de cette phase reste dans Comprehenseur : les cas où
 * le serveur sait déjà (aucun appel, aucun token), le garde-fou anti-chiffre
 * inventé, la lecture de la conclusion, le journal, et surtout le fail-open. Ce
 * contrat ne couvre que la conversation avec le modèle.
 *
 * POURQUOI LE SÉPARER. La compréhension parlait à Google en direct, quel que soit
 * le moteur de texte. Une « alternative à Gemini » qui exige quand même une clé
 * Gemini n'en est pas une — et le basculement cessait d'être une affaire de
 * .env.local. Pire : depuis que les deux moteurs partagent le même orchestrateur,
 * le fil transmis à cette phase arrivait au FORMAT DU MOTEUR ACTIF. Sous Claude,
 * il partait donc chez Google dans un dialecte que Google refuse — et comme tout
 * ici est fail-open, la phase échouait en silence, à chaque message.
 *
 * D'où la règle : l'implémentation construit SON fil à partir de la requête. Elle
 * ne reçoit jamais celui d'un autre.
 *
 * UN SEUL TOUR D'OUTILS, exactement comme la planification. Le comprenant peut
 * vérifier en base — « ce client existe-t-il ? », « y a-t-il plusieurs polices à
 * ce nom ? » — parce que sans cela il poserait une question là où une recherche
 * aurait tranché. Mais il ne CHAÎNE pas : c'est l'enchaînement, et lui seul, qui a
 * saturé le quota le 2026-08-10. Combien d'allers-retours cela demande dépend du
 * fournisseur, et c'est précisément ce que ce contrat cache à l'appelant.
 */
interface AppelDeComprehension extends Fournisseur
{

    /**
     * Le modèle interrogé. Il est VOLONTAIREMENT distinct de celui de la
     * planification : les fournisseurs tiennent un compteur de débit par modèle,
     * et c'est ce qui empêche cette phase de manger la fenêtre qu'elle est censée
     * épargner.
     */
    public function modele(): string;

    /**
     * Le modèle qui a RÉELLEMENT répondu au dernier appel — à distinguer de
     * {@see modele()}, qui rend celui qu'on a demandé.
     *
     * Les deux diffèrent dès qu'un secours a pris le relais, et le journal doit dire
     * le second, jamais le premier : nommer un modèle qui n'a pas parlé, c'est
     * exactement le défaut corrigé le 2026-09-24 sur le bandeau des coulisses.
     * Avant tout appel, rend le modèle demandé — il n'y a rien d'autre à dire.
     */
    public function modeleAyantRepondu(): string;

    /**
     * La clé du compteur de débit de CE modèle.
     *
     * Elle ne se déduit pas du nom du modèle : Gemini compte un quota unique de
     * tokens d'entrée, cache inclus, quand Anthropic en tient deux et en exclut le
     * cache lu. Cf. BudgetDebit.
     */
    public function cleDeDebit(): string;

    /**
     * Le cycle complet : interroger, exécuter localement les outils si le modèle
     * en demande, puis conclure.
     *
     * Rend le TEXTE de la conclusion — un JSON que Comprehenseur interprète — et le
     * total des tokens d'entrée consommés, déjà déclarés au compteur de débit.
     *
     * @return array{texte: string, tokens: int}
     */
    public function conclure(AiRequest $request): array;
}
