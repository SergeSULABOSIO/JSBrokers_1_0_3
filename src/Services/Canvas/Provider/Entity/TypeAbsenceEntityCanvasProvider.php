<?php

namespace App\Services\Canvas\Provider\Entity;

use App\Entity\Entreprise;
use App\Entity\TypeAbsence;

class TypeAbsenceEntityCanvasProvider implements EntityCanvasProviderInterface
{
    public function supports(string $entityClassName): bool
    {
        return $entityClassName === TypeAbsence::class;
    }

    public function getCanvas(): array
    {
        return [
            "parametres" => [
                "description" => "Type d'absence",
                "icone" => "type-absence",
                'background_image' => '/images/fitures/default.jpg',
                'description_template' => [
                    "Type d'absence [[*libelle]] ([[code]]).",
                    " [[regleLibelle]].",
                ],
            ],
            // CE CANEVAS ALIMENTE AUSSI LA RECHERCHE AVANCÉE : retirer un attribut d'ici
            // supprime le filtre correspondant de la barre de recherche.
            "liste" => array_merge([
                ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                ["code" => "code", "intitule" => "Code", "type" => "Texte"],
                ["code" => "libelle", "intitule" => "Libellé", "type" => "Texte"],
                ["code" => "decompte", "intitule" => "Décompté du solde", "type" => "Booleen"],
                ["code" => "justificatifRequis", "intitule" => "Justificatif obligatoire", "type" => "Booleen"],
                ["code" => "plafondParDemande", "intitule" => "Plafond par demande", "type" => "Nombre", "unite" => "j"],
                ["code" => "autoriseDemiJournee", "intitule" => "Demi-journées autorisées", "type" => "Booleen"],
                ["code" => "couleur", "intitule" => "Couleur", "type" => "Texte"],
                ["code" => "actif", "intitule" => "Actif", "type" => "Booleen"],
                ["code" => "entreprise", "intitule" => "Entreprise", "type" => "Relation", "targetEntity" => Entreprise::class, "displayField" => "nom"],
            ], $this->getSpecificIndicators()),
        ];
    }

    private function getSpecificIndicators(): array
    {
        return [
            ["group" => "Règle", "code" => "regleLibelle", "intitule" => "Règle appliquée", "type" => "Calcul", "format" => "Texte", "description" => "Ce que ce type fait au compteur et ce qu'il exige à la saisie."],
            ["group" => "Règle", "code" => "actifLabel", "intitule" => "Statut", "type" => "Calcul", "format" => "Texte", "description" => "Type proposé ou non à la saisie d'une nouvelle demande."],
        ];
    }
}
