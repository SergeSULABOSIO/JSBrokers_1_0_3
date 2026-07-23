<?php

namespace App\Service\Workspace;

/**
 * Descripteur immuable d'une collection éditable d'une entité, tel que déclaré
 * par son FormType (cf. FormTreeInspector). Porte tout ce qu'il faut pour créer
 * / éditer / supprimer un élément de la collection à l'identique de l'UI :
 * classe et FormType enfant, relation inverse à poser, et méthodes d'accès du
 * parent.
 */
final class CollectionEditable
{
    public function __construct(
        public readonly string $nom,
        public readonly string $childShortName,
        public readonly string $childFqcn,
        public readonly string $childFormType,
        /** Champ de la relation inverse (ManyToOne) sur l'enfant, ex. « cotation ». */
        public readonly string $mappedBy,
        public readonly bool $allowAdd,
        public readonly bool $allowDelete,
        /** Accesseur de la collection sur le parent, ex. « getChargements ». */
        public readonly string $getter,
        /** Méthode d'ajout sur le parent, ex. « addChargement ». */
        public readonly string $adder,
        /** Méthode de retrait sur le parent, ex. « removeChargement ». */
        public readonly string $remover,
        /** Setter de la relation inverse sur l'enfant, ex. « setCotation ». */
        public readonly string $setterInverse,
    ) {
    }
}
