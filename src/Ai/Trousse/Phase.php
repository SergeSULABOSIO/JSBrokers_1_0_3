<?php

namespace App\Ai\Trousse;

/**
 * Les TROIS phases d'un message, et il n'y en aura jamais une quatrième.
 *
 * Un message de l'utilisateur est UNE unité de travail :
 *  0. COMPRÉHENSION — le modèle ne répond pas et n'exécute rien ; il établit ce
 *     que l'utilisateur veut. Aucun outil ne lui est déclaré, donc aucun ne peut
 *     partir. Si la demande n'est pas claire, TOUT S'ARRÊTE ICI : Ket rend la
 *     demande reformulée et attend l'accord.
 *  1. PLANIFICATION — le modèle choisit les outils et leurs arguments. C'est le
 *     seul moment où des outils lui sont déclarés.
 *  2. Symfony exécute tout, localement, sans rien demander à personne.
 *  3. RÉDACTION — le modèle formule la réponse finale à partir des résultats.
 *
 * POURQUOI CETTE SÉPARATION CHANGE TOUT. Chaque phase ne reçoit que ce dont elle a
 * besoin. La rédaction n'a besoin NI des déclarations d'outils (72 Ko), NI des
 * protocoles d'écriture (27 Ko) : elle commente un travail déjà fait. La
 * compréhension n'a besoin d'aucun des deux : elle n'a même pas encore décidé
 * qu'il y aurait un travail. Mesuré le 2026-08-10 : un message enchaînant cinq
 * tours pleins consommait 188 000 tokens sur les 212 500 d'une minute, et rendait
 * la conversation entière inutilisable — « salut » compris.
 *
 * POURQUOI UNE TROISIÈME PHASE MALGRÉ LA RÈGLE DES DEUX APPELS. La règle visait le
 * CHAÎNAGE : un modèle qui rappelle un outil après en avoir vu le résultat, cinq
 * fois de suite, avec l'intégralité du contexte réexpédié à chaque fois. La
 * compréhension n'est pas de ce genre-là. Elle ne boucle pas, elle ne déclare
 * aucun outil, elle tourne sur un modèle LÉGER dont le fournisseur tient un
 * compteur de débit distinct, et son prompt pèse un dixième de celui de la
 * planification. Surtout, elle est la seule phase qui puisse faire ÉCONOMISER les
 * deux autres : une demande mal comprise coûtait jusqu'ici deux appels pleins pour
 * une réponse à côté, puis une relance de l'utilisateur — soit quatre. Elle en
 * coûte désormais un seul, le plus petit.
 *
 * Si l'utilisateur veut aller plus loin, il envoie un NOUVEAU message : une
 * nouvelle unité de travail. Le comportement reste prévisible, le coût borné, et
 * le code lisible.
 */
enum Phase
{
    case COMPREHENSION;
    case PLANIFICATION;
    case REDACTION;

    /**
     * La RÉDACTION est la seule à n'en déclarer aucun : elle commente un travail
     * déjà fait. La compréhension, elle, en reçoit une poignée — juste de quoi
     * savoir de QUI l'on parle (cf. AiToolDeComprehension), jamais de quoi répondre
     * à la place de la planification.
     */
    public function declareDesOutils(): bool
    {
        return $this !== self::REDACTION;
    }

    public function libelle(): string
    {
        return match ($this) {
            self::COMPREHENSION => 'comprehension',
            self::PLANIFICATION => 'planification',
            self::REDACTION     => 'redaction',
        };
    }
}
