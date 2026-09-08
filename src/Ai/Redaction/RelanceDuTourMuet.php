<?php

namespace App\Ai\Redaction;

/**
 * CE QU'ON DIT AU MODÈLE QUAND IL N'A RIEN DIT.
 *
 * LE SYMPTÔME. Un tour de planification revient sans texte ET sans appel d'outil : le
 * modèle a dépensé son budget de sortie en raisonnement interne et n'a émis aucun mot.
 * Mesuré le 2026-09-08 (conversation 68) sur « Invente pour moi des numéros. » — 807
 * jetons de sortie, zéro caractère rendu. Aucun outil n'ayant tourné, {@see RepliPrecis}
 * n'avait rien à restituer et servait sa phrase de dernier recours ; l'utilisateur s'est
 * vu demander de nommer un point précis qu'il venait de nommer trois fois.
 *
 * POURQUOI UNE REPRISE PLUTÔT QU'UN MEILLEUR REPLI. Un message a droit à DEUX appels :
 * la planification, puis la rédaction. Or un tour muet arrête le message sur place — il
 * n'y a rien à rédiger, donc la rédaction ne partira jamais, et le second appel reste
 * inemployé. Le dépenser à redemander une réponse vaut infiniment mieux que de le laisser
 * tomber pour servir un mur : la règle des deux appels est tenue à l'identique.
 *
 * POURQUOI CE TEXTE, ET PAS UN SIMPLE REJEU. Rejouer le tour tel quel ne miserait que sur
 * l'échantillonnage. Cette ligne, elle, NOMME le silence et referme les issues : répondre,
 * ou appeler l'outil — jamais se taire. Elle vise en particulier le cas qui l'a fait
 * naître, celui où le modèle se paralyse entre une demande qu'il ne peut pas satisfaire
 * telle quelle et l'interdiction d'inventer : la sortie est de le DIRE, pas de disparaître.
 *
 * ⚠ ELLE NE REJOINT JAMAIS L'HISTORIQUE. C'est un échafaudage, pas un tour de
 * conversation : le fil que l'utilisateur relira — et la phase de rédaction — ne doivent
 * en garder aucune trace.
 *
 * SOURCE UNIQUE, et c'est la raison d'être de cette classe : les deux moteurs doivent se
 * comporter à l'identique, sinon comparer leurs mesures n'a plus de sens et un défaut ne
 * se reproduit que sur l'un des deux. La DÉTECTION du tour muet, elle, reste dans chaque
 * moteur — un bloc de contenu Anthropic et une « part » Gemini ne se lisent pas pareil.
 */
final class RelanceDuTourMuet
{
    public const TEXTE = 'SYSTÈME : ton tour précédent n’a produit NI texte NI appel d’outil, '
        . 'et l’utilisateur n’a donc rien reçu. Reprends maintenant, et conclus dans CE tour : soit tu appelles '
        . 'l’outil qui convient, soit tu réponds en toutes lettres. Si la demande te paraît impossible telle '
        . 'quelle, dis-le en une phrase et propose ce qui reste faisable — ne te tais pas.';
}
