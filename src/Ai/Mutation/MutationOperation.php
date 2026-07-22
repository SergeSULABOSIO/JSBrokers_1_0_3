<?php

namespace App\Ai\Mutation;

use App\Entity\Invite;

/**
 * Une opération d'écriture élémentaire du plan de Ket : créer, modifier ou
 * supprimer UN enregistrement d'une entité métier. Objet de VALEUR immuable —
 * il ne porte aucune logique de sécurité (celle-ci vit dans le service de
 * mutation et le resolver) : il décrit seulement l'INTENTION structurée que le
 * LLM a assemblée et que l'utilisateur validera avant exécution.
 *
 * `fields` = champs scalaires/relations (par id) proposés ; jamais l'id, ni les
 * champs d'audit — le service applique la whitelist et la validation FormType.
 */
final class MutationOperation
{
    public const OP_CREATE = 'create';
    public const OP_EDIT   = 'edit';
    public const OP_DELETE = 'delete';

    public const OPS = [self::OP_CREATE, self::OP_EDIT, self::OP_DELETE];

    /**
     * @param array<string, scalar|null> $fields
     */
    public function __construct(
        public readonly string $op,
        public readonly string $entityShortName,
        public readonly ?int $targetId = null,
        public readonly array $fields = [],
    ) {
    }

    public function isCreate(): bool
    {
        return $this->op === self::OP_CREATE;
    }

    public function isDelete(): bool
    {
        return $this->op === self::OP_DELETE;
    }

    /** Niveau d'accès requis (fail-closed) selon l'opération. */
    public function requiredLevel(): int
    {
        return match ($this->op) {
            self::OP_CREATE => Invite::ACCESS_ECRITURE,
            self::OP_EDIT   => Invite::ACCESS_MODIFICATION,
            self::OP_DELETE => Invite::ACCESS_SUPPRESSION,
            default         => Invite::ACCESS_SUPPRESSION, // valeur la plus stricte par défaut
        };
    }

    public function fqcn(): string
    {
        return 'App\\Entity\\' . $this->entityShortName;
    }

    public function estValide(): bool
    {
        if (!in_array($this->op, self::OPS, true) || $this->entityShortName === '') {
            return false;
        }
        // create : pas d'id ; edit/delete : id strictement positif.
        return $this->isCreate() ? true : ($this->targetId !== null && $this->targetId > 0);
    }

    public static function fromArray(array $data): self
    {
        $fields = [];
        foreach ((array) ($data['fields'] ?? $data['valeurs'] ?? []) as $champ => $valeur) {
            if (is_string($champ) && (is_scalar($valeur) || $valeur === null)) {
                $fields[$champ] = $valeur;
            }
        }

        $id = isset($data['targetId']) ? (int) $data['targetId'] : (isset($data['id']) ? (int) $data['id'] : null);

        return new self(
            op: (string) ($data['op'] ?? ''),
            entityShortName: (string) ($data['entite'] ?? $data['entityShortName'] ?? ''),
            targetId: ($id !== null && $id > 0) ? $id : null,
            fields: $fields,
        );
    }

    /** Sérialisation pour stockage (meta du message) et re-validation d'exécution. */
    public function toArray(): array
    {
        return [
            'op'       => $this->op,
            'entite'   => $this->entityShortName,
            'targetId' => $this->targetId,
            'fields'   => $this->fields,
        ];
    }
}
