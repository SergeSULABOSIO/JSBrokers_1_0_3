<?php

namespace App\Services\Canvas;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;

/**
 * LE LIEN QUI RATTACHE UNE COLLECTION À SON PARENT — nommé par Doctrine, jamais deviné.
 *
 * ── LE DÉFAUT QUE CETTE CLASSE FERME ────────────────────────────────────────────────
 * Un onglet contextuel du workspace a DEUX chemins de lecture. Le premier affichage passe
 * par le getter Doctrine du parent : il est juste par construction. Tout le reste — page 2,
 * recherche, « Réinitialiser », rafraîchissement après un enregistrement — repart vers la
 * rubrique ENTIÈRE de l'enfant, et n'est borné que par le nom du champ qui la relie au
 * parent.
 *
 * Or ce nom était DEVINÉ par le navigateur, en fouillant le canevas de FORMULAIRE du
 * parent. Les onglets, eux, naissent du canevas d'ENTITÉ. Deux listes indépendantes, que
 * rien ne confrontait : pour quatre des six onglets d'un client — pistes, sinistres, notes,
 * partenaires — la devinette rendait `null`, et la liste affichait tout le cabinet sous une
 * pastille portant le nom du client.
 *
 * Doctrine, lui, sait toujours quel champ porte le lien : c'est lui qui l'a construit.
 *
 * ── DEUX NATURES, ET ELLES NE SE FILTRENT PAS PAREIL ────────────────────────────────
 *  - `to_one`     : l'enfant porte une référence vers le parent → `enfant.champ = :id`
 *  - `collection` : l'enfant porte une COLLECTION de parents (ManyToMany — les partenaires
 *                   associés d'un client) → `:id MEMBER OF enfant.champ`. Une égalité
 *                   n'exprime pas une appartenance.
 *
 * ── POURQUOI STATIQUE, AVEC L'EntityManager EN PARAMÈTRE ────────────────────────────
 * Le seul appelant de production est un TRAIT de contrôleur, qui n'a pas de conteneur à
 * lui. Et le test de contrat doit pouvoir interroger la même règle sans monter un
 * contrôleur. Une fonction pure sur les métadonnées n'a de toute façon aucun état à tenir.
 */
final class LienDeCollection
{
    /**
     * @return array{champ: string, nature: string}|null null si le parent ne porte pas
     *         cette collection, ou si la relation ne nomme aucun champ côté enfant
     */
    public static function pour(EntityManagerInterface $em, string $parentClass, string $collectionName): ?array
    {
        $metadata = $em->getClassMetadata($parentClass);
        if (!$metadata->hasAssociation($collectionName)) {
            return null;
        }

        $mapping = $metadata->getAssociationMapping($collectionName);
        $type = $mapping['type'] ?? null;

        if ($type === ClassMetadata::ONE_TO_MANY) {
            return ($mapping['mappedBy'] ?? null)
                ? ['champ' => $mapping['mappedBy'], 'nature' => 'to_one']
                : null;
        }

        if ($type === ClassMetadata::MANY_TO_MANY) {
            // Le côté propriétaire nomme l'inverse par `inversedBy`, le côté inverse par
            // `mappedBy`. Dans les deux cas, c'est une COLLECTION du côté de l'enfant.
            $champ = $mapping['mappedBy'] ?? $mapping['inversedBy'] ?? null;

            return $champ ? ['champ' => $champ, 'nature' => 'collection'] : null;
        }

        return null;
    }
}
