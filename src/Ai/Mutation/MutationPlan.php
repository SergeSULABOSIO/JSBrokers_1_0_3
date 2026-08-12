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
     * Les POSITIONS déclarées des opérations, dans l'ordre où elles seront
     * RÉELLEMENT exécutées.
     *
     * POURQUOI CETTE MÉTHODE EXISTE. Le dry-run doit rendre le même verdict que
     * l'exécution — sans quoi il refuse des plans qui marcheraient, ou l'inverse.
     * Or il a besoin des DEUX ordres à la fois : l'ordre d'EXÉCUTION pour savoir
     * quand chaque « @etiquette » devient disponible, et l'ordre DÉCLARÉ pour
     * numéroter le plan comme l'utilisateur le lira. Rendre les indices plutôt que
     * les objets lui donne les deux, en dérivant du MÊME tri que l'exécution :
     * il n'y a toujours qu'une seule règle d'ordre dans le projet.
     *
     * @return list<int> indices dans $this->operations, en ordre d'exécution
     */
    public function indicesOrdonnes(): array
    {
        $indices = array_keys($this->operations);
        $rangs = self::rangs();
        // Même tri stable que ordonner(), appliqué aux indices.
        usort($indices, function (int $a, int $b) use ($rangs): int {
            $ra = $rangs[$this->operations[$a]->op] ?? 9;
            $rb = $rangs[$this->operations[$b]->op] ?? 9;

            return ($ra <=> $rb) ?: ($a <=> $b);
        });

        return array_values($indices);
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
        $rang = self::rangs();
        // tri stable (usort ne l'est pas partout) : on décore par l'index d'origine.
        $decore = [];
        foreach ($ops as $i => $op) {
            $decore[] = [$rang[$op->op] ?? 9, $i, $op];
        }
        usort($decore, static fn ($a, $b) => ($a[0] <=> $b[0]) ?: ($a[1] <=> $b[1]));

        return array_map(static fn ($d) => $d[2], $decore);
    }

    /**
     * LA règle d'ordre, en un seul endroit : créations d'abord (leurs identifiants
     * peuvent servir aux suivantes), puis éditions, puis suppressions.
     *
     * @return array<string, int>
     */
    private static function rangs(): array
    {
        return [
            MutationOperation::OP_CREATE => 0,
            MutationOperation::OP_EDIT   => 1,
            MutationOperation::OP_DELETE => 2,
        ];
    }

    /**
     * ÉTENDUE du plan : restreint le plan aux seules étapes RETENUES par
     * l'utilisateur (cases cochées). Le filtrage est fait ICI, côté serveur, sur
     * le plan STOCKÉ — le client ne transmet que des clés d'étapes, jamais des
     * opérations. Règles (fail-closed) :
     *  - l'étape SOCLE (celle de la première opération du plan) est toujours
     *    conservée : sans elle le reste n'a plus d'objet ;
     *  - toute autre opération — de tête comme de collection — dont l'étape n'est
     *    pas retenue est élaguée, ainsi que toute sa descendance ;
     *  - un nœud sans étape hérite de celle de son parent (il suit son sort) ;
     *  - CASCADE de références : une opération qui renvoie (« @etiquette ») à une
     *    création élaguée est elle-même élaguée — jamais d'orpheline ;
     *  - $cles vide (aucune sélection transmise) = plan INTÉGRAL, comportement
     *    historique strictement inchangé.
     *
     * @param string[] $cles Clés d'étapes retenues (cf. etapes()).
     */
    public function filtrerEtapes(array $cles): self
    {
        if ($cles === []) {
            return $this;
        }
        $retenues = array_flip(array_map(self::cleEtape(...), $cles));
        $socle = $this->operations[0]->etape ?? null;
        if ($socle !== null) {
            $retenues[self::cleEtape($socle)] = true;
        }

        // La CASCADE se calcule dans l'ordre d'EXÉCUTION, pas dans l'ordre déclaré :
        // c'est le seul dans lequel une création précède toujours ce qui y renvoie.
        // Décidée dans l'ordre déclaré, une édition placée AVANT la création dont
        // elle dépend survivait à l'élagage de celle-ci et repartait avec un
        // « @etiquette » orphelin. Le résultat, lui, reste dans l'ordre DÉCLARÉ.
        $abandonnees = []; // étiquettes de créations élaguées => leurs dépendantes tombent aussi.
        $gardees = [];
        foreach ($this->indicesOrdonnes() as $i) {
            $op = $this->operations[$i];
            $etape = $op->etape;
            $garde = $etape === null || isset($retenues[self::cleEtape($etape)]);
            if ($garde) {
                foreach ($op->referencesUtilisees() as $utilisee) {
                    if (isset($abandonnees[$utilisee])) {
                        $garde = false;
                        break;
                    }
                }
            }
            if (!$garde) {
                if ($op->ref !== null) {
                    $abandonnees[MutationReferences::etiquette(MutationReferences::PREFIXE . $op->ref)] = true;
                }
                continue;
            }
            $gardees[$i] = true;
        }

        $ops = [];
        foreach ($this->operations as $i => $op) {
            if (isset($gardees[$i])) {
                $ops[] = self::filtrerNoeud($op, $retenues, $op->etape);
            }
        }

        return new self($ops);
    }

    /**
     * @param array<string, mixed> $retenues clé d'étape => présence
     */
    private static function filtrerNoeud(MutationOperation $op, array $retenues, ?string $etapeHeritee): MutationOperation
    {
        $collections = [];
        foreach ($op->collections as $nom => $enfants) {
            $gardes = [];
            foreach ($enfants as $enfant) {
                $etape = $enfant->etape ?? $etapeHeritee;
                if ($etape !== null && !isset($retenues[self::cleEtape($etape)])) {
                    continue; // étape décochée : l'enfant et sa descendance sont abandonnés.
                }
                $gardes[] = self::filtrerNoeud($enfant, $retenues, $etape);
            }
            if ($gardes !== []) {
                $collections[$nom] = $gardes;
            }
        }

        return $op->withCollections($collections);
    }

    /**
     * INVENTAIRE des étapes du plan, dans l'ordre où elles apparaissent : ce que
     * l'utilisateur voit et coche. L'étape SOCLE (celle de la première opération)
     * est « obligatoire » — les autres se décochent librement. `noeuds` = nombre
     * d'enregistrements concernés (informatif).
     *
     * @return array<int, array{cle: string, libelle: string, obligatoire: bool, noeuds: int}>
     */
    public function etapes(): array
    {
        $etapes = [];
        foreach ($this->operations as $op) {
            self::collecterEtapes($op, $op->etape, $etapes);
        }
        $socle = $this->operations[0]->etape ?? null;
        if ($socle !== null && isset($etapes[self::cleEtape($socle)])) {
            $etapes[self::cleEtape($socle)]['obligatoire'] = true;
        }

        return array_values($etapes);
    }

    /** @param array<string, array{cle: string, libelle: string, obligatoire: bool, noeuds: int}> $etapes */
    private static function collecterEtapes(MutationOperation $op, ?string $etapeHeritee, array &$etapes): void
    {
        $libelle = $op->etape ?? $etapeHeritee;
        if ($libelle !== null) {
            $cle = self::cleEtape($libelle);
            $etapes[$cle] ??= ['cle' => $cle, 'libelle' => $libelle, 'obligatoire' => false, 'noeuds' => 0];
            $etapes[$cle]['noeuds']++;
        }
        foreach ($op->collections as $enfants) {
            foreach ($enfants as $enfant) {
                self::collecterEtapes($enfant, $libelle, $etapes);
            }
        }
    }

    /** Clé stable d'une étape (le libellé reste libre côté modèle). */
    public static function cleEtape(string $libelle): string
    {
        $cle = mb_strtolower(trim($libelle));
        $cle = preg_replace('/[^\p{L}\p{N}]+/u', '-', $cle) ?? $cle;

        return trim($cle, '-');
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
