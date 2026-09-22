<?php

namespace App\Ai\Engine;

use App\Ai\Fournisseur\Fournisseur;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * UN MOTEUR DE TEXTE, membre de la chaîne des fournisseurs.
 *
 * POURQUOI CE MARQUEUR EXISTE. La voix et les oreilles de Ket sont des CHAÎNES :
 * des fournisseurs taggés, ordonnés par une liste, chacun cédant la place au
 * suivant quand il ne peut pas servir. Le moteur de texte, lui, était le maillon
 * dépareillé — un `switch` en dur sur trois classes injectées nommément. Trois
 * mécanismes proches pour un même besoin, c'est la question « pourquoi Ket
 * parle-t-elle à l'un et écrit-elle chez l'autre ? » garantie un jour ou l'autre.
 *
 * POURQUOI UN MARQUEUR PLUTÔT QUE LE TAG SUR AiEngineInterface. Le résolveur
 * implémente lui aussi cette interface : le tagger par `_instanceof` le ferait
 * entrer dans sa propre liste, et il s'appellerait en boucle. Un marqueur que les
 * MOTEURS portent et que le résolveur ne porte pas ferme le cycle sans écrire de
 * liste nulle part — ajouter un moteur, c'est implémenter ceci, rien d'autre.
 *
 * AiEngineInterface n'est pas touchée : ses trois méthodes sont inchangées, et
 * l'alias de services.yaml pointe toujours sur le résolveur.
 */
#[AutoconfigureTag('app.fournisseur_moteur')]
interface MoteurDeTexte extends AiEngineInterface, Fournisseur
{
}
