<?php

namespace App\Services\Form;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\FormInterface;

/**
 * L'EMPREINTE D'UNE FICHE, POUR QU'UN « ENREGISTRER » N'ÉCRASE JAMAIS EN SILENCE.
 *
 * ── LE DÉFAUT QUI L'A FAIT NAÎTRE ───────────────────────────────────────────────────
 * Un dialogue d'édition renvoie TOUS ses champs à l'enregistrement, y compris ceux que
 * l'utilisateur n'a pas touchés. Si la fiche a changé en base entre l'ouverture et
 * l'enregistrement — « Retirer du portefeuille » lancé depuis la fiche, l'assistant qui
 * écrit, un collègue —, l'ancienne valeur affichée était réécrite : le retrait était
 * défait, sans un mot.
 *
 * ── LA GARDE ────────────────────────────────────────────────────────────────────────
 * À l'ouverture, le formulaire porte l'empreinte des valeurs de ses champs ; à
 * l'enregistrement, on la recalcule depuis la base AVANT d'appliquer la saisie. Une
 * différence = la fiche a changé depuis son ouverture : on refuse (409) et l'on dit
 * quels champs, plutôt que d'écrire par-dessus.
 *
 * ── CE QUE L'EMPREINTE REGARDE, ET POURQUOI ─────────────────────────────────────────
 * Les seuls champs du formulaire STOCKÉS en base (colonne ou association Doctrine) :
 *  - pas les champs calculés, que l'enregistrement ne relit pas — ils feraient crier au
 *    loup à chaque sauvegarde ;
 *  - pas les collections, qui ont leurs propres dialogues : ajouter un contact depuis
 *    l'onglet de la fiche ne doit pas rendre la fiche « périmée » ;
 *  - pas l'horodatage `updatedAt` : sa précision est la seconde, et il bouge pour des
 *    changements qui ne touchent aucun champ du formulaire.
 *
 * Une empreinte par champ, et non une seule pour la fiche : le refus NOMME ce qui a
 * changé. Les deux calculs (ouverture, enregistrement) doivent suivre le MÊME chemin —
 * entité lue en base, formulaire construit, empreinte — sans calcul d'attribut entre les
 * deux, sinon une valeur posée en mémoire passerait pour une modification.
 */
final class EmpreinteDeFiche
{
    /** Nom du champ caché qui transporte l'empreinte d'ouverture. */
    public const CHAMP = '_empreinte_fiche';

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * L'empreinte de chaque champ stocké du formulaire.
     *
     * @return array<string, string> nom du champ => empreinte courte de sa valeur
     */
    public function empreintes(FormInterface $form): array
    {
        $entite = $form->getData();
        if (!is_object($entite)) {
            return [];
        }

        try {
            $meta = $this->em->getClassMetadata($entite::class);
        } catch (\Throwable) {
            return []; // pas une entité : rien en base à protéger
        }

        $empreintes = [];
        foreach ($form->all() as $nom => $enfant) {
            $config = $enfant->getConfig();
            if (!$config->getMapped() || $config->hasOption('entry_type')) {
                continue; // non mappé, ou collection (elle a ses propres dialogues)
            }
            $chemin = $enfant->getPropertyPath();
            if ($chemin === null || $chemin->getLength() !== 1) {
                continue;
            }
            $propriete = (string) $chemin;
            if (!$meta->hasField($propriete) && !$meta->hasAssociation($propriete)) {
                continue; // champ calculé : l'enregistrement ne le relit pas
            }
            $empreintes[(string) $nom] = substr(md5(json_encode($this->normaliser($enfant->getData()))), 0, 12);
        }
        ksort($empreintes);

        return $empreintes;
    }

    /** Le jeton transporté par le formulaire : les empreintes, sérialisées. */
    public function jeton(FormInterface $form): string
    {
        return base64_encode(json_encode($this->empreintes($form)));
    }

    /**
     * Les champs dont la valeur EN BASE a changé depuis l'émission du jeton.
     *
     * Un jeton illisible ne bloque rien : la garde protège, elle ne doit pas empêcher
     * d'enregistrer pour une raison que l'utilisateur ne peut pas corriger.
     *
     * @return string[] noms des champs modifiés
     */
    public function champsModifies(FormInterface $form, string $jeton): array
    {
        $avant = json_decode((string) base64_decode($jeton, true), true);
        if (!is_array($avant)) {
            return [];
        }

        $maintenant = $this->empreintes($form);
        $modifies = [];
        foreach ($avant as $nom => $empreinte) {
            if (isset($maintenant[$nom]) && $maintenant[$nom] !== $empreinte) {
                $modifies[] = (string) $nom;
            }
        }

        return $modifies;
    }

    /** Une valeur de champ ramenée à une forme stable et comparable. */
    private function normaliser(mixed $valeur): mixed
    {
        if ($valeur === null || is_scalar($valeur)) {
            return $valeur;
        }
        if ($valeur instanceof \BackedEnum) {
            return $valeur->value;
        }
        if ($valeur instanceof \UnitEnum) {
            return $valeur->name;
        }
        if ($valeur instanceof \DateTimeInterface) {
            return $valeur->format('Y-m-d H:i:s');
        }
        if (is_iterable($valeur)) {
            // Une association « à plusieurs » n'a pas d'ordre : on trie, sans quoi la
            // même sélection relue dans un autre ordre passerait pour une modification.
            $liste = [];
            foreach ($valeur as $element) {
                $liste[] = json_encode($this->normaliser($element));
            }
            sort($liste);

            return $liste;
        }
        if (method_exists($valeur, 'getId')) {
            return 'id:' . $valeur->getId();
        }
        if ($valeur instanceof \Stringable) {
            return (string) $valeur;
        }

        return $valeur::class;
    }
}
