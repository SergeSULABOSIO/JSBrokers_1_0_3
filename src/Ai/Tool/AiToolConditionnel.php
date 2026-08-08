<?php

namespace App\Ai\Tool;

use App\Ai\Scope\AiScope;

/**
 * Outil dont la DÉCLARATION au modèle n'a pas lieu d'être dans tous les
 * contextes. Interface facultative : un outil qui ne l'implémente pas est
 * toujours déclaré — le filtrage est purement additif, il ne peut donc pas
 * faire disparaître un outil par inadvertance.
 *
 * POURQUOI. L'API du fournisseur est sans mémoire : les 72 Ko de déclarations
 * des 33 outils (≈ 19 600 tokens) repartent à CHAQUE tour de function calling,
 * et ces tokens comptent dans le quota par minute. Déclarer un outil que
 * l'invité n'a pas le droit d'exécuter, ou qui n'a aucun sens dans ce fil,
 * revient à payer plein tarif — plusieurs fois par message — pour un refus.
 *
 * CE N'EST PAS UN MÉCANISME DE SÉCURITÉ. La garde reste où elle a toujours été :
 * dans execute(), qui re-vérifie canRead() en fail-closed (cf. AiToolInterface).
 * estDisponible() ne fait qu'éviter de parler d'un outil inutile ; un faux
 * « true » ne donne aucun accès, un faux « false » ne fait que priver le modèle
 * d'une capacité. Les deux méthodes doivent néanmoins rester cohérentes, sans
 * quoi le modèle se verrait refuser un outil qu'on lui a présenté.
 */
interface AiToolConditionnel
{
    /** Cet outil doit-il être déclaré au modèle pour ce périmètre et ce fil ? */
    public function estDisponible(AiScope $scope): bool;
}
