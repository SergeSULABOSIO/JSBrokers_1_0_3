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
 *
 * `ref` = étiquette posée sur une CRÉATION pour que d'autres opérations du MÊME
 * plan y renvoient (valeur de champ « @etiquette ») alors que son id n'existe pas
 * encore — c'est ce qui rend un plan MULTI-ENTITÉS validable en une seule fois
 * (cf. MutationReferences).
 *
 * `etape` = libellé de l'ÉTAPE de parcours à laquelle le nœud appartient. C'est
 * l'unité que l'utilisateur coche/décoche pour fixer l'ÉTENDUE du plan avant de
 * le valider (le filtrage reste serveur : MutationPlan::filtrerEtapes()).
 */
final class MutationOperation
{
    public const OP_CREATE = 'create';
    public const OP_EDIT   = 'edit';
    public const OP_DELETE = 'delete';

    public const OPS = [self::OP_CREATE, self::OP_EDIT, self::OP_DELETE];

    /**
     * @param array<string, scalar|array|null>   $fields
     * @param array<string, MutationOperation[]> $collections nom de collection => ops enfant
     */
    public function __construct(
        public readonly string $op,
        public readonly string $entityShortName,
        public readonly ?int $targetId = null,
        public readonly array $fields = [],
        public readonly array $collections = [],
        public readonly ?string $ref = null,
        public readonly ?string $etape = null,
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
            if (!is_string($champ)) {
                continue;
            }
            if (is_scalar($valeur) || $valeur === null) {
                $fields[$champ] = $valeur;
                continue;
            }
            // Relation MULTIPLE (ManyToMany/`multiple` du formulaire, ex. les
            // partenaires d'une piste) : liste d'identifiants. Seuls des scalaires
            // sont conservés (fail-closed sur toute structure imbriquée).
            if (is_array($valeur) && array_is_list($valeur)) {
                $liste = array_values(array_filter($valeur, static fn ($v) => is_scalar($v)));
                if ($liste !== []) {
                    $fields[$champ] = $liste;
                }
            }
        }

        $id = isset($data['targetId']) ? (int) $data['targetId'] : (isset($data['id']) ? (int) $data['id'] : null);
        $ref = trim((string) ($data['ref'] ?? ''));
        $etape = trim((string) ($data['etape'] ?? ''));

        return new self(
            op: (string) ($data['op'] ?? ''),
            entityShortName: (string) ($data['entite'] ?? $data['entityShortName'] ?? ''),
            targetId: ($id !== null && $id > 0) ? $id : null,
            fields: $fields,
            collections: self::collectionsFromArray($data['collections'] ?? []),
            ref: $ref !== '' ? $ref : null,
            etape: $etape !== '' ? $etape : null,
        );
    }

    /**
     * Décode récursivement la structure `collections` en map « nom de collection
     * => ops enfant ». Accepte DEUX formes (fail-closed : ce qui n'est pas une op
     * valide est ignoré) :
     *  - ARRAY (dialecte modèle, Gemini-safe) : [{collection|nom, elements|operations:[…]}] ;
     *  - MAP (aller-retour interne toArray) : {nom: [ops]}.
     *
     * @return array<string, MutationOperation[]>
     */
    private static function collectionsFromArray(mixed $raw): array
    {
        if (!is_array($raw) || $raw === []) {
            return [];
        }
        $collections = [];

        // Forme ARRAY : chaque entrée nomme sa collection et liste ses éléments.
        if (array_is_list($raw)) {
            foreach ($raw as $entree) {
                if (!is_array($entree)) {
                    continue;
                }
                $nom = (string) ($entree['collection'] ?? $entree['nom'] ?? '');
                $elements = $entree['elements'] ?? $entree['operations'] ?? $entree['ops'] ?? [];
                if ($nom === '' || !is_array($elements)) {
                    continue;
                }
                $ops = self::opsEnfant($elements);
                if ($ops !== []) {
                    $collections[$nom] = array_merge($collections[$nom] ?? [], $ops);
                }
            }

            return $collections;
        }

        // Forme MAP : { nom: [ops] }.
        foreach ($raw as $nom => $elements) {
            if (!is_string($nom) || !is_array($elements)) {
                continue;
            }
            $ops = self::opsEnfant($elements);
            if ($ops !== []) {
                $collections[$nom] = $ops;
            }
        }

        return $collections;
    }

    /** @return MutationOperation[] */
    private static function opsEnfant(array $elements): array
    {
        $ops = [];
        foreach ($elements as $element) {
            if (is_array($element)) {
                $ops[] = self::fromArray($element);
            }
        }

        return $ops;
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
        if ($this->ref !== null) {
            $data['ref'] = $this->ref;
        }
        if ($this->etape !== null) {
            $data['etape'] = $this->etape;
        }
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
        return new self($this->op, $shortName, $this->targetId, $this->fields, $this->collections, $this->ref, $this->etape);
    }

    /** Copie de l'opération avec d'autres sous-collections (filtrage d'étapes). */
    public function withCollections(array $collections): self
    {
        return new self($this->op, $this->entityShortName, $this->targetId, $this->fields, $collections, $this->ref, $this->etape);
    }

    /**
     * Étiquettes de référence utilisées par les champs de CE nœud (valeurs « @x »).
     *
     * @return string[]
     */
    public function referencesUtilisees(): array
    {
        $refs = [];
        foreach ($this->fields as $valeur) {
            foreach (is_array($valeur) ? $valeur : [$valeur] as $item) {
                if (MutationReferences::estReference($item)) {
                    $refs[] = MutationReferences::etiquette((string) $item);
                }
            }
        }

        return array_values(array_unique($refs));
    }
}
