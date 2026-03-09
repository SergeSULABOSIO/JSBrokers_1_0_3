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
                "texte_principal" => ["attribut_code" => "nom", "icone" => "note"],
                "textes_secondaires_separateurs" => " • ",
                "textes_secondaires" => [
                    ["attribut_prefixe" => "Réf: ", "attribut_code" => "reference"],
                    ["attribut_prefixe" => "Dest.: ", "attribut_code" => "addressedToString"],
                    ["attribut_prefixe" => "Statut: ", "attribut_code" => "statutPaiement"],
                ],
            ],
            "colonnes_numeriques" => [
                [
                    "titre_colonne" => "Montant Total",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    "attribut_code" => "montantTotal",
                    "attribut_type" => "nombre",
                ],
                [
                    "titre_colonne" => "Montant Payé",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    "attribut_code" => "montantPaye",
                    "attribut_type" => "nombre",
                ],
                [
                    "titre_colonne" => "Solde",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    "attribut_code" => "solde",
                    "attribut_type" => "nombre",
                ],
            ],
        ];
    }
}