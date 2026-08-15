<?php

namespace App\Ai\Fichier;

use App\Ai\AiText;

/**
 * LE DÉMENTI DE FICHIER — quand Ket affirme qu'aucune pièce n'a été transmise alors
 * que l'utilisateur en a bel et bien attaché une, et qu'il la voit sous sa propre bulle.
 *
 * L'INCIDENT. « Ajoute le dans l'avenant », pièce jointe à l'appui — agrafe visible dans
 * le fil —, et deux réponses de suite : « Votre fichier n'apparaît toujours pas dans le
 * fil de notre conversation », puis « Aucun fichier n'a été transmis ou reçu dans nos
 * derniers échanges. Je vous invite à téléverser le document ». C'est-à-dire : refaites
 * ce que vous venez de faire. La cause première était un prompt de rédaction aveugle
 * aux pièces jointes, et elle est corrigée à la source (AiContextBuilder). Ce garde-fou
 * est la ceinture qui accompagne la bretelle.
 *
 * POURQUOI UNE CEINTURE. Le contenu d'une bulle est écrit par un modèle : aucune
 * consigne, si ferme soit-elle, ne le contraint absolument. Or ce démenti-là a un coût
 * particulier — il renvoie l'utilisateur à une manipulation qu'il a déjà faite, et lui
 * laisse croire que la plateforme a perdu son fichier. Le serveur, lui, SAIT ce qui est
 * attaché. Quand la prose le contredit, c'est le serveur qui a raison, et il le dit
 * lui-même — exactement comme pour un plan fantôme ou une exécution fantôme.
 *
 * CONSERVATEUR PAR CONSTRUCTION. On ne se déclenche que sur une NÉGATION explicite, et
 * jamais sur une simple mention. « Le fichier est illisible », « je n'ai pas pu extraire
 * le texte », « votre PDF est scanné » sont des réponses HONNÊTES sur un fichier bien
 * reçu : les démentir serait ajouter une confusion à une réponse juste.
 */
final class FichierNieAtort
{
    /**
     * Les formes du démenti, normalisées (sans accents, en minuscules).
     *
     * Chacune affirme l'ABSENCE ou réclame un envoi. Les variantes viennent de ce que
     * le modèle produit réellement, pas d'une liste imaginée : les deux premières sont
     * relevées mot pour mot dans l'incident.
     */
    private const NEGATIONS = [
        'aucun fichier',
        'aucune piece jointe',
        'aucun document n a ete transmis',
        'n apparait toujours pas',
        'n apparait pas dans le fil',
        'ne figure pas dans le fil',
        'je ne vois aucun',
        'je ne recois aucun',
        'je n ai recu aucun',
        'je n ai pas recu de fichier',
        'pas ete transmis',
        'pas ete recu',
        'n a pas ete joint',
        'veuillez televerser',
        'je vous invite a televerser',
        'merci de televerser',
        'veuillez joindre',
        'je vous invite a joindre',
        'invite a utiliser l option de piece jointe',
        'option de piece jointe de votre interface',
    ];

    /**
     * La réponse nie-t-elle des pièces réellement présentes ? Rend la mise au point à
     * afficher, ou null.
     *
     * @param array<int, array{id:int, nom:string}> $fichiersAttaches tels que AiContextBuilder les liste
     *
     * @return array{fichiers: list<array{id:int, nom:string}>}|null
     */
    public static function detecter(string $prose, array $fichiersAttaches): ?array
    {
        if ($fichiersAttaches === [] || trim($prose) === '') {
            return null;
        }
        if (!self::proseNieUnFichier($prose)) {
            return null;
        }

        $fichiers = [];
        foreach ($fichiersAttaches as $f) {
            $id = (int) ($f['id'] ?? 0);
            if ($id > 0) {
                $fichiers[] = ['id' => $id, 'nom' => (string) ($f['nom'] ?? '')];
            }
        }

        return $fichiers === [] ? null : ['fichiers' => $fichiers];
    }

    /**
     * La prose affirme-t-elle l'absence de fichier, ou en réclame-t-elle l'envoi ?
     *
     * On passe par AiText::cle() et non par normalize() : elle seule ramène TOUTE la
     * ponctuation à une espace, donc l'apostrophe droite comme la typographique.
     * « n'apparaît », « n’apparaît » et « n apparait » deviennent la même phrase — une
     * détection qui dépendrait de la typographie du modèle ne tiendrait pas un mois.
     */
    private static function proseNieUnFichier(string $prose): bool
    {
        $normalise = AiText::cle($prose);

        foreach (self::NEGATIONS as $negation) {
            if (str_contains($normalise, $negation)) {
                return true;
            }
        }

        return false;
    }
}
