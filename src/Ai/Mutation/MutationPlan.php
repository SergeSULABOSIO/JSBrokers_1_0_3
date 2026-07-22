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

    /** Une suppression est-elle présente ? (déclenche l'autorisation renforcée). */
    public function contientSuppression(): bool
    {
        foreach ($this->operations as $op) {
            if ($op->isDelete()) {
                return true;
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
        $rang = [
            MutationOperation::OP_CREATE => 0,
            MutationOperation::OP_EDIT   => 1,
            MutationOperation::OP_DELETE => 2,
        ];
        $ops = $this->operations;
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
