<?php

namespace App\Echange\Canevas;

/**
 * Une RESSOURCE échangeable : une entité du périmètre, sa feuille dans le classeur,
 * ses colonnes et ses dépendances.
 *
 * Le CODE est le nom court de l'entité (« Client », « ConditionPartage ») — le même
 * mot que manipulent déjà MutationAllowlist, WorkspaceAccessResolver::MAP et
 * l'assistant. Aucune table de correspondance à tenir : un vocabulaire de plus serait
 * un vocabulaire de plus à désynchroniser.
 */
final class RessourceDEchange
{
    /**
     * @param string                       $code           nom court de l'entité, identifiant de la ressource
     * @param string                       $libelle        libellé de rubrique, lu dans la carte d'accès
     * @param string                       $module         module de rattachement (Production, Finances…), lu dans la même carte
     * @param class-string                 $fqcn
     * @param string                       $feuille        nom d'onglet Excel (≤ 31 caractères, sans caractère interdit)
     * @param int                          $rang           rang topologique : une ressource référencée précède celle qui la référence
     * @param array<int, ColonneDEchange>  $colonnes       indexées par code
     * @param array<int, string>           $dependances    codes des ressources à écrire AVANT celle-ci
     * @param array<int, string>           $renvoisDifferes codes de colonnes dont la cible ne peut pas exister à l'écriture de cette ligne :
     *                                                     elles sont posées par une seconde passe, une fois la cible créée (cf. OrdreTopologique)
     */
    public function __construct(
        public readonly string $code,
        public readonly string $libelle,
        public readonly string $module,
        public readonly string $fqcn,
        public readonly string $feuille,
        public readonly int $rang,
        public readonly array $colonnes,
        public readonly array $dependances,
        public readonly array $renvoisDifferes = [],
    ) {
    }

    /** @return array<string, ColonneDEchange> indexées par code technique */
    public function colonnesParCode(): array
    {
        $parCode = [];
        foreach ($this->colonnes as $colonne) {
            $parCode[$colonne->code] = $colonne;
        }

        return $parCode;
    }

    public function colonne(string $code): ?ColonneDEchange
    {
        return $this->colonnesParCode()[$code] ?? null;
    }

    /** @return array<int, ColonneDEchange> celles que l'import relira */
    public function colonnesModifiables(): array
    {
        return array_values(array_filter($this->colonnes, static fn (ColonneDEchange $c) => $c->estModifiable()));
    }

    /** Le renvoi porté par cette colonne doit-il attendre une seconde passe ? */
    public function renvoiEstDiffere(string $codeColonne): bool
    {
        return in_array($codeColonne, $this->renvoisDifferes, true);
    }

    /** Copie de la ressource avec un nouveau rang et ses renvois différés (usage interne au tri). */
    public function avecRang(int $rang, array $renvoisDifferes): self
    {
        return new self(
            $this->code,
            $this->libelle,
            $this->module,
            $this->fqcn,
            $this->feuille,
            $rang,
            $this->colonnes,
            $this->dependances,
            $renvoisDifferes,
        );
    }
}
