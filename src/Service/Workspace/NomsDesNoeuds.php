<?php

namespace App\Service\Workspace;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;

/**
 * LE NOM DE CHAQUE LIGNE D'UN PLAN DE SUPPRESSION — une requête par CLASSE, jamais par
 * ligne.
 *
 * ⚠ C'EST LA SEULE RAISON D'ÊTRE DE CE SERVICE. `SuppressionEnCascade::nomLisible()` charge
 * une entité pour en tirer un nom : tenable pour la poignée de factures conservées qu'un
 * rapport cite, ruineux dès qu'il faut nommer les trois mille nœuds d'un arbre. Ici on lit
 * une COLONNE, en lots, sans hydrater : trois mille noms coûtent autant que six requêtes.
 *
 * ⚠ ET AUCUNE LIGNE NE RESTE MUETTE. Une entité sans champ nommant — `Article` n'en a
 * littéralement aucun — reçoit son libellé de classe suivi de son numéro. Une ligne vide
 * dans un arbre de suppression, c'est une case qu'on demande à quelqu'un de cocher sans lui
 * dire ce qu'elle emporte.
 */
final class NomsDesNoeuds
{
    /**
     * Les champs qui NOMMENT, par ordre de préférence.
     *
     * L'ordre n'est pas indifférent : une police a `referencePolice` ET `description`, et
     * c'est la référence qu'un courtier reconnaît. On prend donc le premier champ existant,
     * pas le plus long ni le plus rempli.
     */
    private const PREFERENCE = ['nom', 'libelle', 'reference', 'referencePolice', 'titre', 'numero', 'code', 'description'];

    /** Au-delà, la clause `IN` devient hostile au planificateur de MariaDB. */
    private const TAILLE_DE_LOT = 1000;

    /** @var array<class-string, string|null> mémoïsation du champ nommant par classe */
    private array $champs = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @param array<class-string, int[]>  $parClasse
     * @param array<class-string, string> $libelles  classe => libellé métier, pour le repli
     *
     * @return array<class-string, array<int, string>>
     */
    public function pour(array $parClasse, array $libelles = []): array
    {
        $noms = [];

        foreach ($parClasse as $classe => $ids) {
            if ($ids === []) {
                continue;
            }
            $champ = $this->champQuiNomme($classe);
            $lus = $champ === null ? [] : $this->lire($classe, $champ, $ids);

            // ⚠ ON NE MET PAS CE LIBELLÉ AU SINGULIER, et c'est un choix. Un libellé de
            // rubrique est au pluriel, mais le pluriel français n'est pas toujours à la fin :
            // « Lignes de facture » le porte sur le PREMIER mot, « Jours fériés » sur les
            // deux. Retirer le « s » final donnerait « Jours férié » et ne corrigerait même
            // pas « Lignes de facture ». Un libellé légèrement raide mais toujours juste vaut
            // mieux qu'un singulier inventé qui se trompe une fois sur deux.
            $repli = $libelles[$classe] ?? $this->court($classe);

            foreach ($ids as $id) {
                $valeur = trim((string) ($lus[$id] ?? ''));
                $noms[$classe][$id] = $valeur !== '' ? $valeur : sprintf('%s n° %d', $repli, $id);
            }
        }

        return $noms;
    }

    /**
     * Le premier champ de {@see PREFERENCE} que cette classe possède, ou null.
     *
     * @param class-string $classe
     */
    private function champQuiNomme(string $classe): ?string
    {
        if (array_key_exists($classe, $this->champs)) {
            return $this->champs[$classe];
        }

        try {
            $meta = $this->em->getClassMetadata($classe);
        } catch (\Throwable) {
            return $this->champs[$classe] = null;
        }

        foreach (self::PREFERENCE as $candidat) {
            // ⚠ ON EXIGE UN CHAMP, PAS UNE ASSOCIATION. `hasField()` écarte les relations :
            // `o.client` dans un SELECT rendrait un objet, et le concaténer lèverait.
            if ($meta->hasField($candidat) && $this->estTextuel($meta, $candidat)) {
                return $this->champs[$classe] = $candidat;
            }
        }

        return $this->champs[$classe] = null;
    }

    /** Un champ ne nomme que s'il porte du texte : une date ou un montant ne nomme rien. */
    private function estTextuel(ClassMetadata $meta, string $champ): bool
    {
        return in_array((string) $meta->getTypeOfField($champ), ['string', 'text'], true);
    }

    /**
     * @param class-string $classe
     * @param int[]        $ids
     *
     * @return array<int, string|null>
     */
    private function lire(string $classe, string $champ, array $ids): array
    {
        $lus = [];

        foreach (array_chunk(array_values($ids), self::TAILLE_DE_LOT) as $lot) {
            try {
                $lignes = $this->em->createQueryBuilder()
                    ->select('o.id')
                    ->addSelect(sprintf('o.%s AS nom', $champ))
                    ->from($classe, 'o')
                    ->where('o.id IN (:ids)')
                    ->setParameter('ids', $lot)
                    ->getQuery()
                    // ⚠ SCALAIRE, JAMAIS D'OBJETS. Hydrater pour lire un nom remettrait
                    // exactement le coût qu'on vient de retirer — et remplirait
                    // l'UnitOfWork d'entités que la suppression devra ensuite recharger.
                    ->getScalarResult();
            } catch (\Throwable) {
                // Un nom illisible n'est pas une raison de perdre l'arbre : le repli suffit.
                continue;
            }

            foreach ($lignes as $ligne) {
                $lus[(int) $ligne['id']] = $ligne['nom'] === null ? null : (string) $ligne['nom'];
            }
        }

        return $lus;
    }

    private function court(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');

        return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
    }
}
