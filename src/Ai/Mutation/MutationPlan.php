<?php

namespace App\Ai\Mutation;

/**
 * Plan d'écriture de Ket : une SÉQUENCE ordonnée d'opérations (create/edit/
 * delete) à exécuter ensemble, de façon ATOMIQUE (tout ou rien). Objet de valeur
 * immuable, sérialisable pour être stocké côté serveur (meta du message qui
 * présente le plan) puis RE-VALIDÉ intégralement à l'exécution — on ne fait
 * jamais confiance au client.
 */
final class MutationPlan
{
    /** @param MutationOperation[] $operations */
    public function __construct(
        public readonly array $operations,
    ) {
    }

    public function estVide(): bool
    {
        return $this->operations === [];
    }

    /**
     * Une suppression est-elle présente À N'IMPORTE QUEL NIVEAU de l'arbre ?
     * (déclenche l'autorisation renforcée par mot de passe). Parcours récursif :
     * un delete d'un enfant de collection compte autant qu'un delete de tête.
     */
    public function contientSuppression(): bool
    {
        foreach ($this->operations as $op) {
            if (self::noeudContientSuppression($op)) {
                return true;
            }
        }

        return false;
    }

    private static function noeudContientSuppression(MutationOperation $op): bool
    {
        if ($op->isDelete()) {
            return true;
        }
        foreach ($op->collections as $enfants) {
            foreach ($enfants as $enfant) {
                if (self::noeudContientSuppression($enfant)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Ordre d'exécution : créations d'abord (leurs id peuvent servir de cible aux
     * opérations suivantes), puis éditions, puis suppressions en dernier. Ordre
     * stable au sein d'un même type (préserve l'ordre proposé).
     *
     * @return MutationOperation[]
     */
    public function operationsOrdonnees(): array
    {
        return self::ordonner($this->operations);
    }

    /**
     * Ordonne une liste d'opérations (tête ou enfants de collection) : créations
     * d'abord, puis éditions, puis suppressions ; ordre stable au sein d'un même
     * type. Helper partagé (DRY) avec le tri des sous-opérations de collection.
     *
     * @param MutationOperation[] $ops
     * @return MutationOperation[]
     */
    public static function ordonner(array $ops): array
    {
        $rang = [
            MutationOperation::OP_CREATE => 0,
            MutationOperation::OP_EDIT   => 1,
            MutationOperation::OP_DELETE => 2,
        ];
        // tri stable (usort ne l'est pas partout) : on décore par l'index d'origine.
        $decore = [];
        foreach ($ops as $i => $op) {
            $decore[] = [$rang[$op->op] ?? 9, $i, $op];
        }
        usort($decore, static fn ($a, $b) => ($a[0] <=> $b[0]) ?: ($a[1] <=> $b[1]));

        return array_map(static fn ($d) => $d[2], $decore);
    }

    /** @return array<int, array> */
    public function toArray(): array
    {
        return array_map(static fn (MutationOperation $op) => $op->toArray(), $this->operations);
    }

    public static function fromArray(array $data): self
    {
        $ops = [];
        foreach ($data as $item) {
            if (is_array($item)) {
                $ops[] = MutationOperation::fromArray($item);
            }
        }

        return new self($ops);
    }
}
