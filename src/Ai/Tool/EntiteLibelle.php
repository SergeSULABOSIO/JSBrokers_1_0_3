<?php

namespace App\Ai\Tool;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

/**
 * Libellé lisible d'un enregistrement pour l'assistant IA : détecte le champ
 * de libellé persisté d'une entité (métadonnées Doctrine) et le lit via
 * PropertyAccess, avec repli __toString puis #id — même heuristique que
 * l'autocomplétion de la recherche avancée (DRY entre outils).
 */
final class EntiteLibelle
{
    /**
     * Champs candidats au libellé/filtre texte, par ordre de préférence. Le
     * premier présent dans les métadonnées Doctrine de l'entité est retenu.
     *
     * `nomComplet` (nom métier lisible, ex. Risque) est prioritaire sur `description`
     * (texte libre) : sans lui, un Risque était étiqueté par sa description — souvent
     * vide ou parasite — masquant son vrai nom à l'assistant (invisible en liste ET
     * introuvable au filtre texte, qui portait alors sur la description).
     *
     * `referencePolice` PRÉCÈDE `numero`, et c'est l'incident du 2026-08-14. Un courtier
     * désigne une police par sa référence — « la police SURDCVO00018389 » — jamais par
     * le numéro d'avenant qu'elle porte. Or `numero` était retenu le premier pour un
     * Avenant : la recherche portait sur « 0 », « 1 », « 3 », donc ne trouvait rien, et
     * la question de désambiguïsation renvoyée à l'utilisateur listait ces mêmes numéros
     * — « 0 / 1 / 3 / 0 », illisibles et tous identiques. Ket concluait que la police
     * « n'existe pas dans le portefeuille » alors qu'elle était bien en base.
     *
     * L'effet s'étend à deux entités qui n'avaient AUCUN champ de libellé et étaient donc
     * introuvables par leur nom : NotificationSinistre et Operation, qui ne portent que
     * `referencePolice`. Elles deviennent désignables du même coup.
     *
     * Le rang importe : APRÈS `reference` (Paiement garde la sienne) et AVANT `numero`.
     */
    private const DISPLAY_FIELD_CANDIDATES = ['nom', 'nomComplet', 'titre', 'libelle', 'intitule', 'reference', 'referencePolice', 'numero', 'description'];

    private readonly PropertyAccessorInterface $accessor;

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
        $this->accessor = PropertyAccess::createPropertyAccessor();
    }

    /** Premier champ persisté de l'entité utilisable comme libellé, ou null. */
    public function displayField(string $fqcn): ?string
    {
        return $this->champsDeResolution($fqcn)[0] ?? null;
    }

    /**
     * TOUS les champs par lesquels un enregistrement peut être DÉSIGNÉ, dans l'ordre de
     * préférence — le premier étant celui qui l'AFFICHE.
     *
     * POURQUOI AFFICHER ET DÉSIGNER NE SONT PAS LA MÊME CHOSE. Un enregistrement s'affiche
     * sous UN libellé, mais un utilisateur peut le nommer de plusieurs façons. Un avenant
     * s'affiche par sa référence de police, et c'est ainsi qu'on le désigne neuf fois sur
     * dix ; il reste pourtant légitime de dire « l'avenant 3 ». Ne chercher que sur le
     * champ d'affichage, c'est refuser la seconde formulation — et refuser, ici, ne
     * produit pas une erreur mais une phrase fausse : « cette police n'existe pas dans
     * votre portefeuille ».
     *
     * La liste est DÉRIVÉE des métadonnées, jamais déclarée par entité : une entité qui
     * gagne demain un champ `reference` devient désignable par lui sans qu'on y touche.
     *
     * @return list<string>
     */
    public function champsDeResolution(string $fqcn): array
    {
        $metadata = $this->em->getClassMetadata($fqcn);
        $champs = [];
        foreach (self::DISPLAY_FIELD_CANDIDATES as $candidate) {
            if ($metadata->hasField($candidate)) {
                $champs[] = $candidate;
            }
        }

        return $champs;
    }

    /** Libellé lisible d'une instance : champ détecté, sinon __toString, sinon #id. */
    public function libelle(object $entity, ?string $displayField): string
    {
        $libelle = null;
        if ($displayField !== null) {
            try {
                $libelle = $this->accessor->getValue($entity, $displayField);
            } catch (\Throwable) {
                // Champ illisible sur cette instance : repli __toString / id.
            }
        }
        if ($libelle === null || $libelle === '') {
            $libelle = method_exists($entity, '__toString') ? (string) $entity : ('#' . $entity->getId());
        }

        return (string) $libelle;
    }
}
