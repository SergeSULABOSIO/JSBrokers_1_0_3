<?php

namespace App\Services\Canvas\Provider\List;

use App\Entity\OffreIndemnisationSinistre;
use App\Services\ServiceMonnaies;

class OffreIndemnisationSinistreListCanvasProvider implements ListCanvasProviderInterface
{
    public function __construct(private ServiceMonnaies $serviceMonnaies)
    {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === OffreIndemnisationSinistre::class;
    }

    public function getCanvas(): array
    {
        return [
            "colonne_principale" => [
                "titre_colonne" => "Offres d'indemnisation",
                "texte_principal" => ["attribut_code" => "nom", "icone" => "icon-park-outline:funds"],
                "textes_secondaires" => [["attribut_code" => "beneficiaire"]],
            ],
            "colonnes_numeriques" => [
                ["titre_colonne" => "Montant Payable", "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(), "attribut_code" => "montantPayable", "attribut_type" => "nombre"],
                ["titre_colonne" => "Comp. versée", "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(), "attribut_code" => "compensationVersee", "attribut_type" => "nombre"],
                ["titre_colonne" => "Solde à verser", "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(), "attribut_code" => "soldeAVerser", "attribut_type" => "nombre"],
            ],
        ];
    }
}