<?php

namespace App\Service\Workspace;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;

/**
 * Découvre, à partir de l'ARBRE DES FORMTYPES (le contrat exact de l'interface
 * graphique), quelles collections d'une entité sont éditables par l'assistant IA
 * (Ket) — et, récursivement, quelles collections de leurs éléments le sont.
 *
 * Source de vérité = le formulaire lui-même : un champ de type CollectionType
 * expose son `entry_type` (le FormType enfant, celui que l'UI utilise pour
 * éditer un élément) et ses autorisations `allow_add` / `allow_delete`. Le nom
 * du champ correspond à l'association Doctrine homonyme sur l'entité parente
 * (même si le champ de formulaire est `mapped:false`), d'où l'on tire la classe
 * enfant et la relation inverse (`mappedBy`) à poser sur l'enfant.
 *
 * Conséquence de sécurité : la surface éditable de Ket est STRICTEMENT celle de
 * l'UI — un champ ou une collection hors formulaire n'est jamais atteignable
 * (fail-closed par construction), et le nom court d'entité d'un enfant est
 * toujours dérivé ici (jamais dicté par le LLM).
 */
class FormTreeInspector
{
    /** Garde-fou anti-emballement : profondeur maximale de récursion dans l'arbre. */
    public const PROFONDEUR_MAX = 5;

    /** @var array<string, array<string, CollectionEditable>> cache par nom court d'entité */
    private array $cache = [];

    public function __construct(
        private readonly FormFactoryInterface $formFactory,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Collections éditables déclarées par le FormType d'une entité, indexées par
     * nom de collection. Tableau vide si l'entité n'a pas de FormType exploitable
     * ou aucune collection dans son formulaire.
     *
     * @return array<string, CollectionEditable>
     */
    public function collectionsEditables(string $shortName): array
    {
        if (array_key_exists($shortName, $this->cache)) {
            return $this->cache[$shortName];
        }

        $collections = $this->inspecter($shortName);
        $this->cache[$shortName] = $collections;

        return $collections;
    }

    /** Une collection nommée est-elle éditable sur cette entité (déclarée par son formulaire) ? */
    public function collectionEditable(string $shortName, string $collectionName): ?CollectionEditable
    {
        return $this->collectionsEditables($shortName)[$collectionName] ?? null;
    }

    /**
     * @return array<string, CollectionEditable>
     */
    private function inspecter(string $shortName): array
    {
        $entityFqcn = 'App\\Entity\\' . $shortName;
        $formType = 'App\\Form\\' . $shortName . 'Type';
        if (!class_exists($entityFqcn) || !class_exists($formType)) {
            return [];
        }

        // Construire le formulaire sur une instance neuve (comme l'UI en création)
        // pour lire sa structure. Best-effort : un FormType récalcitrant => aucune
        // collection exposée (fail-closed).
        try {
            $form = $this->formFactory->create($formType, new $entityFqcn());
            $meta = $this->em->getClassMetadata($entityFqcn);
        } catch (\Throwable) {
            return [];
        }

        $collections = [];
        foreach ($form->all() as $child) {
            if (!$this->estCollectionType($child)) {
                continue;
            }
            $nom = $child->getName();
            if (!$meta->hasAssociation($nom)) {
                continue; // champ de collection sans association Doctrine homonyme : ignoré.
            }
            $mapping = $meta->getAssociationMapping($nom);
            // On ne gère que les OneToMany (relation inverse posée sur l'enfant via mappedBy).
            $isOneToMany = ($mapping['type'] ?? null) === \Doctrine\ORM\Mapping\ClassMetadata::ONE_TO_MANY;
            $mappedBy = $mapping['mappedBy'] ?? null;
            if (!$isOneToMany || !is_string($mappedBy) || $mappedBy === '') {
                continue;
            }

            $childFqcn = (string) ($mapping['targetEntity'] ?? '');
            if ($childFqcn === '' || !class_exists($childFqcn)) {
                continue;
            }
            $childShort = $this->shortName($childFqcn);
            $entryType = $child->getConfig()->getOption('entry_type');
            if (!is_string($entryType) || !class_exists($entryType)) {
                continue; // pas de formulaire enfant exploitable.
            }

            $collections[$nom] = new CollectionEditable(
                nom: $nom,
                childShortName: $childShort,
                childFqcn: $childFqcn,
                childFormType: $entryType,
                mappedBy: $mappedBy,
                allowAdd: (bool) $child->getConfig()->getOption('allow_add'),
                allowDelete: (bool) $child->getConfig()->getOption('allow_delete'),
                getter: 'get' . ucfirst($nom),
                adder: 'add' . ucfirst($this->singulier($nom)),
                remover: 'remove' . ucfirst($this->singulier($nom)),
                setterInverse: 'set' . ucfirst($mappedBy),
            );
        }

        return $collections;
    }

    private function estCollectionType(FormInterface $child): bool
    {
        $type = $child->getConfig()->getType();
        while ($type !== null) {
            if ($type->getInnerType() instanceof CollectionType) {
                return true;
            }
            $type = $type->getParent();
        }

        return false;
    }

    private function shortName(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');

        return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
    }

    /** Singularise un nom de collection (convention du projet : retrait du « s » final). */
    private function singulier(string $nom): string
    {
        return preg_replace('/s$/', '', $nom) ?? $nom;
    }
}
