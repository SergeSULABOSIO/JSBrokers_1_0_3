<?php

namespace App\Form\Autocomplete;

use App\Services\JSBDynamicSearchService;
use Doctrine\ORM\QueryBuilder;

/**
 * LA RECHERCHE D'UNE AUTOCOMPLÉTION, ÉCRITE PAR NOUS — pour les champs qui cherchent dans plus
 * qu'un nom (Groupe, Taxe : leur description).
 *
 * `searchable_fields` laisse ux-autocomplete écrire son propre LIKE. Il échappe déjà les
 * jokers (2.36, `ESCAPE '!'`), mais il ne peut pas chercher le TEXTE d'une description riche
 * plutôt que son HTML : c'est la raison de `filter_query` (chantier 11). On y applique le même
 * motif que la recherche des listes (JSBDynamicSearchService::motifLike), pour qu'un terme se
 * cherche partout de la même façon. `filter_query` reçoit le QueryBuilder DÉJÀ restreint au
 * cabinet (`query_builder` du champ, résolu par EntityType) : on n'y ajoute qu'un `andWhere`,
 * la frontière du cabinet reste celle du champ.
 */
final class RechercheAutocomplete
{
    /** Nombre de propositions, comme le défaut du bundle (`max_results`). */
    private const PROPOSITIONS = 10;

    /**
     * @param string[] $champs colonnes de l'entité racine, cherchées en OU
     *
     * @return \Closure(QueryBuilder, string): void
     */
    public static function surLesChamps(array $champs): \Closure
    {
        return static function (QueryBuilder $qb, string $saisie) use ($champs): void {
            $qb->setMaxResults(self::PROPOSITIONS);
            if (JSBDynamicSearchService::motifLike($saisie) === null) {
                return; // rien de tapé : les premières propositions, comme le bundle
            }
            $alias = $qb->getRootAliases()[0];
            $meta = $qb->getEntityManager()->getClassMetadata($qb->getRootEntities()[0]);

            // Même lecture que les listes : le TEXTE d'un champ riche (TEXTE_DU_HTML), blancs
            // réduits ; un champ simple tel quel. Un paramètre par sorte de champ.
            $conditions = [];
            foreach ($champs as $champ) {
                $riche = JSBDynamicSearchService::estRiche($meta, $champ);
                $parametre = $riche ? 'saisieAutocompleteRiche' : 'saisieAutocomplete';
                $conditions[] = sprintf(
                    "%s LIKE :%s ESCAPE '%s'",
                    JSBDynamicSearchService::colonneCherchable($meta, $alias, $champ),
                    $parametre,
                    JSBDynamicSearchService::ECHAPPEMENT_LIKE,
                );
                $qb->setParameter($parametre, JSBDynamicSearchService::motifLike($saisie, 'contains', $riche));
            }
            $qb->andWhere($qb->expr()->orX(...$conditions));
        };
    }
}
