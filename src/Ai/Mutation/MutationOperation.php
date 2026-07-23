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
 *
 * `collections` = sous-opérations RÉCURSIVES sur les collections éditables du
 * nœud, telles qu'exposées par son FormType (ex. `chargements` d'une Cotation).
 * Structure : nom de collection => liste de MutationOperation enfant, chaque
 * enfant pouvant lui-même porter des `collections` (parité formulaire récursive
 * avec l'UI). Le nom court d'entité d'un enfant est DÉRIVÉ côté serveur du
 * FormType parent (entry_type / targetEntity), jamais dicté par le LLM.
 */
final class MutationOperation
{
    public const OP_CREATE = 'create';
    public const OP_EDIT   = 'edit';
    public const OP_DELETE = 'delete';

    public const OPS = [self::OP_CREATE, self::OP_EDIT, self::OP_DELETE];

    /**
     * @param array<string, scalar|null>        $fields
     * @param array<string, MutationOperation[]> $collections nom de collection => ops enfant
     */
    public function __construct(
        public readonly string $op,
        public readonly string $entityShortName,
        public readonly ?int $targetId = null,
        public readonly array $fields = [],
        public readonly array $collections = [],
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
        // `champs` = clé exposée au LLM par le schéma de preparer_operations ;
        // `fields` = clé de sérialisation interne (toArray) ; `valeurs` = alias
        // toléré. Lire les trois évite de perdre les valeurs dictées par le modèle.
        $fields = [];
        foreach ((array) ($data['champs'] ?? $data['fields'] ?? $data['valeurs'] ?? []) as $champ => $valeur) {
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
            collections: self::collectionsFromArray($data['collections'] ?? []),
        );
    }

    /**
     * Décode récursivement la structure `collections` : nom de collection => liste
     * d'ops enfant. Ignore silencieusement les entrées malformées (fail-closed :
     * ce qui n'est pas une op valide n'est pas exécuté).
     *
     * @return array<string, MutationOperation[]>
     */
    private static function collectionsFromArray(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $collections = [];
        foreach ($raw as $nom => $enfants) {
            if (!is_string($nom) || !is_array($enfants)) {
                continue;
            }
            $ops = [];
            foreach ($enfants as $enfant) {
                if (is_array($enfant)) {
                    $ops[] = self::fromArray($enfant);
                }
            }
            if ($ops !== []) {
                $collections[$nom] = $ops;
            }
        }

        return $collections;
    }

    /** Sérialisation pour stockage (meta du message) et re-validation d'exécution. */
    public function toArray(): array
    {
        $data = [
            'op'       => $this->op,
            'entite'   => $this->entityShortName,
            'targetId' => $this->targetId,
            'fields'   => $this->fields,
        ];
        if ($this->collections !== []) {
            $data['collections'] = [];
            foreach ($this->collections as $nom => $enfants) {
                $data['collections'][$nom] = array_map(
                    static fn (MutationOperation $enfant) => $enfant->toArray(),
                    $enfants,
                );
            }
        }

        return $data;
    }

    /**
     * Retourne une copie de l'opération avec un nom court d'entité dérivé côté
     * serveur (les enfants de collection sont typés d'après le FormType parent,
     * jamais d'après le LLM). Immutabilité préservée.
     */
    public function withEntityShortName(string $shortName): self
    {
        return new self($this->op, $shortName, $this->targetId, $this->fields, $this->collections);
    }
}
