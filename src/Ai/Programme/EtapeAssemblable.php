<?php

namespace App\Ai\Programme;

use App\Ai\Tool\AiToolInterface;

/**
 * UN OUTIL QUI SAIT FABRIQUER SES PROPRES ARGUMENTS À PARTIR D'UNE ÉTAPE DE
 * PROGRAMME — le marqueur qui rend une SÉRIE D'ÉCRITURES QUELCONQUES possible.
 *
 * LE TROU QUE CELA COMBLE (incident du 2026-08-11). Une étape de programme se
 * pilote par des PAIRES PLATES (clé/valeur en texte), pour une bonne raison :
 * un paramètre complexe et optionnel est ce que les modèles omettent. La
 * conséquence non voulue était qu'un outil exigeant une STRUCTURE — au premier
 * chef `preparer_operations` et ses `operations` — était déclaré NON PILOTABLE.
 * Autrement dit : Ket savait enchaîner des paiements de prime, mais pas
 * « créons d'abord ce fournisseur, puis enregistrons la dépense » — la demande
 * la plus banale qui soit. Faute de pouvoir l'exprimer, elle a présenté un plan,
 * l'a fait valider, puis s'est arrêtée net ; l'utilisateur a dû relancer, et le
 * second plan n'est jamais venu avec ses boutons.
 *
 * LA SORTIE N'EST PAS D'EXPOSER LA STRUCTURE AU MODÈLE (on rétablirait le défaut
 * qu'on évite), mais de laisser l'OUTIL l'assembler. Le modèle décrit l'étape à
 * plat — quelle entité, quelle opération, quels champs — et l'outil, seul à
 * connaître la forme de ses propres arguments, en fabrique la structure. C'est la
 * même règle que partout ailleurs dans ce chantier : ce que le serveur peut
 * déduire, il le déduit ; ce qu'on ne demande pas au modèle ne peut pas être
 * manqué par lui.
 */
interface EtapeAssemblable extends AiToolInterface
{
    /**
     * Les arguments d'exécution de l'outil, dérivés d'une étape décrite à plat.
     *
     * @param array{
     *     entite?: string,
     *     operation?: string,
     *     cibleId?: int|null,
     *     ref?: string|null,
     *     champs?: array<int, array{cle?: string, valeur?: mixed}>
     * } $etape
     *
     * @return array<string, mixed> vide si l'étape est inexploitable — le programme
     *                              la traversera alors avec son motif, sans figer
     *                              la série
     */
    public function argumentsDepuisEtape(array $etape): array;

    /**
     * Une ligne d'aide destinée à la description de `preparer_programme` : comment
     * décrire une étape qui déclenche CET outil. Elle vit ici plutôt que dans le
     * texte du lanceur de programme, pour la même raison que l'aiguillage vit dans
     * l'outil : une consigne recopiée ailleurs finit par mentir.
     */
    public function aideEtape(): string;
}
