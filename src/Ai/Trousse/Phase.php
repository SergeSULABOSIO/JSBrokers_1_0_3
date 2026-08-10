<?php

namespace App\Ai\Trousse;

/**
 * Les DEUX phases d'un message, et il n'y en aura jamais une troisième.
 *
 * Un message de l'utilisateur est UNE unité de travail :
 *  1. PLANIFICATION — le modèle ne répond pas à l'utilisateur ; il choisit les
 *     outils et leurs arguments. C'est le seul moment où des outils lui sont
 *     déclarés.
 *  2. Symfony exécute tout, localement, sans rien demander à personne.
 *  3. RÉDACTION — le modèle formule la réponse finale à partir des résultats.
 *
 * POURQUOI CETTE SÉPARATION CHANGE TOUT. La phase de rédaction n'a besoin NI des
 * déclarations d'outils (72 Ko), NI des protocoles d'écriture (27 Ko) : elle
 * commente un travail déjà fait. Les lui envoyer, c'était payer deux fois le
 * catalogue pour un tour qui n'appelle rien. Mesuré le 2026-08-10 : un message
 * enchaînant cinq tours pleins consommait 188 000 tokens sur les 212 500 d'une
 * minute, et rendait la conversation entière inutilisable — « salut » compris.
 *
 * Si l'utilisateur veut aller plus loin, il envoie un NOUVEAU message : une
 * nouvelle unité de travail, de nouveau limitée à deux appels. Le comportement
 * reste prévisible, le coût borné, et le code lisible.
 */
enum Phase
{
    case PLANIFICATION;
    case REDACTION;

    public function declareDesOutils(): bool
    {
        return $this === self::PLANIFICATION;
    }

    public function libelle(): string
    {
        return $this === self::PLANIFICATION ? 'planification' : 'redaction';
    }
}
