<?php

namespace App\Ai;

/**
 * Réponse normalisée du moteur IA. `refused` = la question sortait du périmètre
 * d'accès de l'invité ; `toolUsed` = outil de données exécuté (traçabilité) ;
 * `actions` = directives d'intention UI (AiToolResult::uiAction) que le chat
 * traduit sur le bus d'événements du workspace (ex. ouverture de dialogue).
 */
final class AiReply
{
    /**
     * @param array<int, array{outil: string, motif: string}> $plansRefuses outils
     *        PRODUCTEURS DE PLAN appelés ce tour-ci qui n'ont PAS produit de plan
     *        (« pret » différent de true), avec le motif tel que l'outil l'a dit.
     *
     *        POURQUOI CE CHAMP EXISTE. La prose et la surface de décision sont
     *        découplées : rien n'empêche le modèle de rendre un beau tableau de plan,
     *        budget compris, alors que l'outil venait de REFUSER — c'est ce qui s'est
     *        produit deux fois de suite le 2026-08-11, l'utilisateur cherchant un
     *        bouton qui ne viendrait jamais. Le détecter par des mots-clés dans la
     *        prose est un jeu perdu d'avance ; le SERVEUR, lui, sait exactement qu'un
     *        outil de plan a refusé et pourquoi. C'est ce signal STRUCTUREL que le
     *        contrôleur croise avec la prose, et c'est lui qui permet de dire à
     *        l'utilisateur ce qui manque VRAIMENT au lieu d'un avertissement creux.
     */
    public function __construct(
        public readonly string $content,
        public readonly bool $refused = false,
        public readonly ?string $toolUsed = null,
        public readonly array $actions = [],
        public readonly array $plansRefuses = [],
    ) {
    }
}
