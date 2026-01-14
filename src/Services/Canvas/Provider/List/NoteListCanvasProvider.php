<?php

namespace App\Services\Canvas\Provider\List;

use App\Entity\Note;
use App\Services\ServiceMonnaies;

class NoteListCanvasProvider implements ListCanvasProviderInterface
{
    public function __construct(private ServiceMonnaies $serviceMonnaies)
    {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Note::class;
    }

    public function getCanvas(): array
    {
        return [
            "colonne_principale" => [
                "titre_colonne" => "Notes",
                "texte_principal" => ["attribut_code" => "nom", "icone" => "mdi:note-text"],
                "textes_secondaires_separateurs" => " • ",
                "textes_secondaires" => [
                    ["attribut_prefixe" => "Réf: ", "attribut_code" => "reference"],
                    ["attribut_prefixe" => "Statut: ", "attribut_code" => "status_string"],
                ],
            ],
            "colonnes_numeriques" => [
                [
                    "titre_colonne" => "Montant Payable",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    "attribut_code" => "montant_payable",
                    "attribut_type" => "nombre",
                ],
                [
                    "titre_colonne" => "Montant Payé",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    "attribut_code" => "montant_paye",
                    "attribut_type" => "nombre",
                ],
                [
                    "titre_colonne" => "Solde",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    "attribut_code" => "montant_solde",
                    "attribut_type" => "nombre",
                ],
            ],
        ];
    }
}