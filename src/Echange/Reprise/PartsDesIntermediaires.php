<?php

namespace App\Echange\Reprise;

use App\Echange\Classeur\LigneLue;
use App\Echange\Service\ResolveurDeRenvois;
use App\Service\Partage\ConditionDOffice;

/**
 * LA PART D'UN INTERMÉDIAIRE QUE LA REPRISE CRÉE, décidée par le FICHIER ENTIER.
 *
 * ── LE PROBLÈME QUE CETTE CLASSE RÉSOUT ────────────────────────────────────────────
 * Un partenaire ne peut pas naître sans « Part % » : la colonne est obligatoire. Le
 * classeur la porte bien — « Intermédiaire · Part » —, mais LIGNE PAR LIGNE, et un même
 * apporteur y reçoit souvent des taux différents d'une affaire à l'autre. Il faut donc
 * choisir la part de sa FICHE, puis traiter chaque écart comme une exception propre à
 * l'affaire.
 *
 * ⚠ LA PART LA PLUS FRÉQUENTE, ET NON LA PREMIÈRE. Prendre la première ligne ferait
 * dépendre la rémunération de l'ordre du fichier : un taux rare placé en tête deviendrait
 * la règle, et toutes les autres affaires recevraient une condition dérogatoire. La plus
 * fréquente écrit le moins d'exceptions possible — c'est la lecture la plus fidèle de ce
 * que le cabinet pratique vraiment avec cet apporteur.
 *
 * ⚠ SUR TOUT LE FICHIER, JAMAIS SUR UN PALIER. La reprise avance par tranches de lignes ;
 * calculée sur le palier, la part d'un même partenaire changerait selon l'endroit où le
 * découpage tombe.
 *
 * ⚠ À ÉGALITÉ, LA PREMIÈRE LIGNE DANS L'ORDRE DE TRAITEMENT — trié par police, donc stable
 * d'un palier à l'autre. Aucune valeur du tout : la part d'usage du cabinet
 * ({@see ConditionDOffice::PART_PARTENAIRE_DEFAUT}).
 */
final class PartsDesIntermediaires
{
    public const COLONNE_NOM = 'intermediaire';
    public const COLONNE_PART = 'intermediairePart';

    /** @param array<string, float> $parts nom normalisé => part retenue, en POINTS */
    private function __construct(private readonly array $parts)
    {
    }

    /** Aucun fichier sous les yeux : chaque intermédiaire créé prend la part d'usage. */
    public static function aucune(): self
    {
        return new self([]);
    }

    /** @param LigneLue[] $lignes toutes les lignes du fichier, dans l'ordre de traitement */
    public static function depuis(array $lignes): self
    {
        /** @var array<string, array<string, array{part: float, occurrences: int, rang: int}>> $comptes */
        $comptes = [];

        foreach (array_values($lignes) as $rang => $ligne) {
            $nom = ResolveurDeRenvois::normaliser($ligne->texte(self::COLONNE_NOM));
            $part = self::partDeLaLigne($ligne);
            if ($nom === '' || $part === null) {
                continue;
            }

            // Comparées au millième, comme la saisie : 30 et 30,0 sont une même part.
            $cle = number_format($part, 3, '.', '');
            $comptes[$nom][$cle] ??= ['part' => $part, 'occurrences' => 0, 'rang' => $rang];
            ++$comptes[$nom][$cle]['occurrences'];
        }

        $parts = [];
        foreach ($comptes as $nom => $candidates) {
            usort(
                $candidates,
                static fn (array $a, array $b): int => [$b['occurrences'], $a['rang']] <=> [$a['occurrences'], $b['rang']],
            );
            $parts[$nom] = $candidates[0]['part'];
        }

        return new self($parts);
    }

    /** La part à écrire sur la fiche d'un intermédiaire que la reprise crée. */
    public function partDeCreation(string $nom): float
    {
        return $this->parts[ResolveurDeRenvois::normaliser($nom)] ?? ConditionDOffice::PART_PARTENAIRE_DEFAUT;
    }

    /** La cellule de la part est-elle vide ? Vide n'est pas illisible : vide vaut zéro. */
    public static function celluleVide(LigneLue $ligne): bool
    {
        return self::brut($ligne) === '';
    }

    /**
     * La part écrite sur la ligne, en POINTS — null si la cellule est vide OU illisible.
     *
     * L'appelant distingue les deux par {@see celluleVide()} : une cellule vide est un
     * arrangement à 0 %, un texte qui ne se lit pas est une faute à montrer.
     */
    public static function partDeLaLigne(LigneLue $ligne): ?float
    {
        $brut = str_replace(',', '.', self::brut($ligne));

        return $brut !== '' && is_numeric($brut) ? (float) $brut : null;
    }

    /** Le texte de la cellule, sans espaces ni signe pourcent — « 30 % » se lit 30. */
    private static function brut(LigneLue $ligne): string
    {
        return str_replace([' ', "\u{00A0}", '%'], '', $ligne->texte(self::COLONNE_PART));
    }
}
