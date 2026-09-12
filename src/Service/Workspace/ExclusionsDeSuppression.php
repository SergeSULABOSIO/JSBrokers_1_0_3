<?php

namespace App\Service\Workspace;

/**
 * CE QUE L'UTILISATEUR A DÉCOCHÉ — les pièces qu'il garde alors que leur dossier part.
 *
 * ⚠ « GARDER » VEUT DIRE « DÉTACHER », JAMAIS « IGNORER ». Une pièce épargnée dont le
 * parent disparaît doit voir son lien coupé, sinon la base refuse la suppression et tout
 * échoue. C'est la différence entre épargner et oublier.
 *
 * Les noms de classe arrivent de l'écran sous leur forme COURTE (« Document ») : c'est ce
 * que porte l'arbre, et c'est tout ce dont on a besoin pour reconnaître une ligne.
 */
final class ExclusionsDeSuppression
{
    /**
     * Plafond de lignes épargnées.
     *
     * ⚠ CE N'EST PAS UNE LIMITE MÉTIER, C'EST UNE BORNE D'ENTRÉE. La charge vient du
     * navigateur : sans plafond, une requête forgée ferait construire un tableau de
     * plusieurs millions d'identifiants avant même qu'on ait vérifié quoi que ce soit.
     */
    public const MAX = 5000;

    /** @param array<string, array<int, true>> $parClasse nom court => identifiants */
    private function __construct(
        private readonly array $parClasse,
    ) {
    }

    public static function vide(): self
    {
        return new self([]);
    }

    /**
     * Lit la charge envoyée par l'écran : `{"Document": [903, 904], "Paiement": [77]}`.
     *
     * Tout ce qui n'est pas reconnaissable est ignoré sans bruit : une exclusion qu'on ne
     * comprend pas ne doit pas faire échouer une suppression que l'utilisateur a validée.
     * Le pire qu'elle puisse produire, c'est que la pièce parte avec le reste — ce qui
     * était le comportement AVANT qu'on sache l'épargner.
     */
    public static function depuis(mixed $brut): self
    {
        if (!is_array($brut)) {
            return self::vide();
        }

        $parClasse = [];
        $total = 0;
        foreach ($brut as $classe => $ids) {
            if (!is_string($classe) || !is_array($ids) || !preg_match('/^[A-Za-z]+$/', $classe)) {
                continue;
            }
            foreach ($ids as $id) {
                if (!is_int($id) && !ctype_digit((string) $id)) {
                    continue;
                }
                if (++$total > self::MAX) {
                    return new self($parClasse);
                }
                $parClasse[$classe][(int) $id] = true;
            }
        }

        return new self($parClasse);
    }

    public function contient(string $classeCourte, int $id): bool
    {
        return isset($this->parClasse[$classeCourte][$id]);
    }

    public function estVide(): bool
    {
        return $this->parClasse === [];
    }

    public function nombre(): int
    {
        return array_sum(array_map('count', $this->parClasse));
    }
}
