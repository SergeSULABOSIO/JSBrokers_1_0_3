<?php

namespace App\Service\Workspace;

/**
 * CE QUI VA PARTIR, CE QUI VA RESTER, ET POURQUOI.
 *
 * Objet de valeur produit par {@see SuppressionEnCascade::planifier()} : il ne contient
 * que des couples (classe, identifiant), jamais d'entités hydratées. C'est lui qu'on
 * montre à l'utilisateur AVANT de lui demander confirmation, et c'est le MÊME qu'on
 * exécute ensuite.
 *
 * ⚠ DEUX MOTEURS DE GRAPHE DIVERGERAIENT. Annoncer une portée avec un calcul et
 * l'appliquer avec un autre, c'est promettre à l'écran ce que l'exécution démentira. Le
 * plan est donc l'unique intermédiaire entre l'annonce et le geste.
 */
// ⚠ NON `final`, pour la même raison que le moteur qui le produit : les tests de
// périmètre de l'assistant doublent `SuppressionEnCascade`, et PHPUnit doit pouvoir
// fabriquer la valeur de retour de `planifier()`.
class PlanDeSuppression
{
    /**
     * @param class-string                                            $racineClasse
     * @param array<class-string, int[]>                              $aDetruire     classe => identifiants
     * @param array<int, array{classe: class-string, champ: string, ids: int[]}> $aDetacher
     * @param string[]                                                $conservations ce qui survit, expliqué
     * @param string[]                                                $refus         ce qui empêche, expliqué
     * @param array<class-string, string>                             $libelles      classe => libellé métier
     */
    public function __construct(
        public readonly string $racineClasse,
        public readonly ?int $racineId,
        public readonly array $aDetruire = [],
        public readonly array $aDetacher = [],
        public readonly array $conservations = [],
        public readonly array $refus = [],
        private readonly array $libelles = [],
    ) {
    }

    /** Plan vide et refusé, pour les cas où la cible ne peut pas être analysée. */
    public static function refuse(string $classe, ?int $id, string $motif, array $libelles = []): self
    {
        return new self($classe, $id, [], [], [], [$motif], $libelles);
    }

    public function estBloque(): bool
    {
        return $this->refus !== [];
    }

    /** Nombre de lignes détruites, racine comprise. */
    public function nombreDetruits(): int
    {
        return array_sum(array_map('count', $this->aDetruire));
    }

    /** Nombre de liens coupés (des lignes qui, elles, survivent). */
    public function nombreDetaches(): int
    {
        return array_sum(array_map(static fn (array $d): int => count($d['ids']), $this->aDetacher));
    }

    /**
     * Unités de travail à annoncer à la barre de progression.
     *
     * ⚠ LE TOTAL EST CONNU AVANT DE COMMENCER, et c'est ce que {@see Progression} exige :
     * une barre qui découvre son dénominateur en route recule, et une barre qui recule
     * ment.
     */
    public function total(): int
    {
        return $this->nombreDetruits() + $this->nombreDetaches();
    }

    /**
     * Ce qui part, par nature, hors racine — c'est la portée qu'on annonce.
     *
     * @return array<int, array{entite: string, libelle: string, count: int}>
     */
    public function portee(): array
    {
        $portee = [];
        foreach ($this->aDetruire as $classe => $ids) {
            $nombre = count($ids);
            if ($classe === $this->racineClasse) {
                --$nombre; // la racine elle-même n'est pas un « dommage collatéral »
            }
            if ($nombre > 0) {
                $portee[] = ['entite' => $this->court($classe), 'libelle' => $this->libelle($classe), 'count' => $nombre];
            }
        }

        return $portee;
    }

    /** Libellé métier d'une classe (nom court à défaut). */
    public function libelle(string $classe): string
    {
        return $this->libelles[$classe] ?? $this->court($classe);
    }

    /** Les identifiants condamnés d'une classe donnée. @return int[] */
    public function idsDe(string $classe): array
    {
        return $this->aDetruire[$classe] ?? [];
    }

    private function court(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');

        return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
    }
}
