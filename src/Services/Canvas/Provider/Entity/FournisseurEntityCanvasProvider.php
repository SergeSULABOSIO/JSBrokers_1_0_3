<?php

namespace App\Services\Canvas\Provider\Entity;

use App\Entity\Document;
use App\Entity\Entreprise;
use App\Entity\Fournisseur;

class FournisseurEntityCanvasProvider implements EntityCanvasProviderInterface
{
    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Fournisseur::class;
    }

    public function getCanvas(): array
    {
        return [
            "parametres" => [
                "description" => "Fournisseur",
                "icone" => "fournisseur",
                'background_image' => '/images/fitures/default.jpg',
                'description_template' => [
                    "Fournisseur [[*nom]].",
                    " [[coordonnees]].",
                    " Statut : [[actifLabel]].",
                ]
            ],
            "liste" => array_merge([
                ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                ["code" => "nom", "intitule" => "Nom / raison sociale", "type" => "Texte"],
                ["code" => "personneContact", "intitule" => "Personne de contact", "type" => "Texte"],
                ["code" => "telephone", "intitule" => "Téléphone", "type" => "Texte"],
                ["code" => "email", "intitule" => "E-mail", "type" => "Texte"],
                ["code" => "adresse", "intitule" => "Adresse", "type" => "Texte"],
                ["code" => "rccm", "intitule" => "RCCM", "type" => "Texte"],
                ["code" => "numimpot", "intitule" => "Numéro d'imposition", "type" => "Texte"],
                ["code" => "description", "intitule" => "Description", "type" => "Texte"],
                ["code" => "actif", "intitule" => "Actif", "type" => "Booleen"],
                ["code" => "documents", "intitule" => "Dossier fournisseur", "type" => "Collection", "targetEntity" => Document::class, "displayField" => "nom"],
                ["code" => "entreprise", "intitule" => "Entreprise", "type" => "Relation", "targetEntity" => Entreprise::class, "displayField" => "nom"],
            ], $this->getSpecificIndicators())
        ];
    }

    private function getSpecificIndicators(): array
    {
        return [
            ["group" => "Détails", "code" => "coordonnees", "intitule" => "Coordonnées", "type" => "Calcul", "format" => "Texte", "description" => "Contact, téléphone et e-mail du fournisseur."],
            ["group" => "Détails", "code" => "actifLabel", "intitule" => "Statut", "type" => "Calcul", "format" => "Texte", "description" => "Fournisseur actif ou inactif à la saisie des dépenses."],
            ["group" => "Dossier", "code" => "nombreDocuments", "intitule" => "Pièces au dossier", "type" => "Calcul", "format" => "Entier", "description" => "Nombre de pièces justificatives attachées (contrats, agréments, preuves de partenariat…)."],
        ];
    }
}
