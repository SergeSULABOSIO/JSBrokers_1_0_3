<?php

namespace App\Services\Canvas\Provider\List;

use App\Entity\Partenaire;
use App\Services\ServiceMonnaies;

class PartenaireListCanvasProvider implements ListCanvasProviderInterface
{

    public function __construct(private ServiceMonnaies $serviceMonnaies) {}

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Partenaire::class;
    }

    public function getCanvas(): array
    {
        return [
            "colonne_principale" => [
                "titre_colonne" => "Partenaires",
                "texte_principal" => ["attribut_code" => "nom", "icone" => "mdi:account-tie"],
                "textes_secondaires_separateurs" => " • ",
                "textes_secondaires" => [
                    ["attribut_code" => "telephone"],
                    ["attribut_code" => "email"],
                ],
            ],
            "colonnes_numeriques" => [
                [
                    "titre_colonne" => "Part (%)",
                    "attribut_unité" => "%",
                    "attribut_code" => "partPourcentage",
                    "attribut_type" => "nombre",
                ],
                [
                    "titre_colonne" => "Assiette",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    "attribut_code" => "montantPur",
                    "attribut_type" => "nombre",
                ],
                [
                    "titre_colonne" => "Rétro-comm.",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    "attribut_code" => "retroCommission",
                    "attribut_type" => "nombre",
                ],
                [
                    "titre_colonne" => "Rétro. Payée",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    "attribut_code" => "retroCommissionReversee",
                    "attribut_type" => "nombre",
                ],
                [
                    // CE QUI RESTE DÛ, EN BOUT DE LIGNE : due moins payée. La dette du
                    // cabinet envers l'apporteur se lit d'un coup d'œil, à côté de ce qui
                    // l'a produite. Aucun calcul ici : la soustraction appartient à
                    // IndicatorCalculationHelper, qui la publie déjà.
                    "titre_colonne" => "Rétro. Solde",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    "attribut_code" => "retroCommissionSolde",
                    "attribut_type" => "nombre",
                ],
                [
                    // ET CE QU'IL FAUT SORTIR MAINTENANT. Le solde dit la dette entière,
                    // dont une part n'est pas encore réclamable : le cabinet n'a pas
                    // encaissé la commission qui la justifie. Cette colonne isole ce que
                    // l'argent rentré rend exigible — c'est elle qu'on lit pour décider
                    // d'un virement, jamais le solde.
                    "titre_colonne" => "Rétro. Exigible",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    "attribut_code" => "retroCommissionExigible",
                    "attribut_type" => "nombre",
                ],
            ],
        ];
    }
}
