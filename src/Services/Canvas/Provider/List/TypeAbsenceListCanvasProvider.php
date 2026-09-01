<?php

namespace App\Services\Canvas\Provider\List;

use App\Entity\TypeAbsence;

class TypeAbsenceListCanvasProvider implements ListCanvasProviderInterface
{
    public function supports(string $entityClassName): bool
    {
        return $entityClassName === TypeAbsence::class;
    }

    public function getCanvas(): array
    {
        return [
            "colonne_principale" => [
                "titre_colonne" => "Types d'absence",
                // DES CODES PLATS, JAMAIS DE CHEMIN POINTÉ : un canevas de liste ne sait
                // lire qu'un attribut simple. Les libellés composés viennent de
                // TypeAbsenceIndicatorStrategy.
                "texte_principal" => ["attribut_code" => "libelle", "icone" => "gg:list"],
                "textes_secondaires" => [
                    ["attribut_prefixe" => "Code : ", "attribut_code" => "code"],
                    // « Décompté du solde » est LA propriété qui distingue un congé annuel
                    // d'un arrêt maladie : elle se lit sur la ligne, pas dans la fiche.
                    ["attribut_code" => "regleLibelle"],
                ],
            ],
            "colonnes_numeriques" => [],
        ];
    }
}
