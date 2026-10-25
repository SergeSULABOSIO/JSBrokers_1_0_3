<?php

namespace App\Services\Canvas;

use App\Services\Canvas\Provider\Numeric\GrandeurTotalisable;
use App\Services\ServiceMonnaies;
use App\Services\ServiceNombres;

/**
 * LA BARRE DES TOTAUX D'UNE PAGE, RENDUE PAR LE SERVEUR (chantier 4, commit 2 — décision de
 * Serge : « le chiffre est calculé et formaté par le serveur, plus par le navigateur »).
 *
 * Le navigateur additionnait et formatait (`Intl.NumberFormat('fr-FR')`), en français même en
 * anglais, et autrement que les cellules. Ici :
 *   - `attributs` : les options du sélecteur, dans l'ordre des grandeurs (libellé, nature) ;
 *   - `totaux`    : le total de la PAGE de chaque grandeur, DÉJÀ FORMATÉ comme une cellule —
 *                   code de la monnaie de travail devant, nombre dans la langue active ; un
 *                   compte, entier et sans monnaie ;
 *   - `valeurs`   : les valeurs entières par ligne (identifiant => code => valeur).
 * Les sommes portent sur des ENTIERS (centimes) : exactes au centime.
 * Une seule monnaie par cabinet, jamais convertie (courtage-assurance).
 */
final class BarreDesTotaux
{
    public function __construct(
        private readonly ServiceNombres $nombres,
        private readonly ServiceMonnaies $monnaies,
    ) {
    }

    /**
     * @param array<int, array<string, array{description: string, value: int, nature: string}>> $valeursParId
     *
     * @return array{attributs: array<string, array{libelle: string, nature: string}>, totaux: array<string, string>, valeurs: array<int, array<string, int>>}
     */
    public function composer(array $valeursParId): array
    {
        $attributs = [];
        $valeurs = [];
        $sommes = [];
        foreach ($valeursParId as $id => $grandeurs) {
            foreach ($grandeurs as $code => $grandeur) {
                $attributs[$code] ??= ['libelle' => $grandeur['description'], 'nature' => $grandeur['nature']];
                $valeur = (int) $grandeur['value'];
                $valeurs[$id][$code] = $valeur;
                $sommes[$code] = ($sommes[$code] ?? 0) + $valeur;
            }
        }

        // Le code de la monnaie, lu UNE fois : getCodeMonnaieAffichage() interroge la base à
        // chaque appel (une requête par grandeur et par page, sinon).
        $monnaie = $attributs === [] ? null : $this->monnaies->getCodeMonnaieAffichage();
        $totaux = [];
        foreach ($attributs as $code => $attribut) {
            $totaux[$code] = $this->formater($sommes[$code] ?? 0, $attribut['nature'], $monnaie);
        }

        return ['attributs' => $attributs, 'totaux' => $totaux, 'valeurs' => $valeurs];
    }

    /** Un total, tel qu'une cellule l'afficherait (`{{ unité }} {{ valeur|format_nombre(2) }}`). */
    public function formater(int $total, string $nature, ?string $monnaie): string
    {
        if ($nature === GrandeurTotalisable::NOMBRE) {
            return $this->nombres->format($total, 0);
        }
        $nombre = $this->nombres->format($total / 100, 2);

        return $monnaie ? $monnaie . ' ' . $nombre : $nombre;
    }
}
