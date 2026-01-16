<?php

namespace App\Services\Canvas\Provider\Entity;

use App\Entity\Assureur;
use App\Entity\Avenant;
use App\Entity\ChargementPourPrime;
use App\Entity\Cotation;
use App\Entity\Document;
use App\Entity\Piste;
use App\Entity\RevenuPourCourtier;
use App\Entity\Tache;
use App\Entity\Tranche;
use App\Services\Canvas\CanvasHelper;
use App\Services\ServiceMonnaies;

class CotationEntityCanvasProvider implements EntityCanvasProviderInterface
{
    public function __construct(
        private ServiceMonnaies $serviceMonnaies,
        private CanvasHelper $canvasHelper
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Cotation::class;
    }

    public function getCanvas(): array
    {
        return [
            "parametres" => [
                "description" => "Cotation",
                "icone" => "mdi:file-document-outline",
                'background_image' => '/images/fitures/default.jpg',
                'description_template' => [
                    "Cotation [[*nom]] pour la piste [[piste]].",
                    " Assureur: [[assureur]].",
                    " Statut: [[statutSouscription]]."
                ]
            ],
            "liste" => array_merge([
                ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                ["code" => "nom", "intitule" => "Nom", "type" => "Texte"],
                ["code" => "duree", "intitule" => "Durée (jours)", "type" => "Entier"],
                ["code" => "createdAt", "intitule" => "Créée le", "type" => "Date"],
                ["code" => "assureur", "intitule" => "Assureur", "type" => "Relation", "targetEntity" => Assureur::class, "displayField" => "nom"],
                ["code" => "piste", "intitule" => "Piste", "type" => "Relation", "targetEntity" => Piste::class, "displayField" => "nom"],
                ["code" => "taches", "intitule" => "Tâches", "type" => "Collection", "targetEntity" => Tache::class, "displayField" => "description"],
                ["code" => "chargements", "intitule" => "Chargements", "type" => "Collection", "targetEntity" => ChargementPourPrime::class, "displayField" => "nom"],
                ["code" => "revenus", "intitule" => "Revenus", "type" => "Collection", "targetEntity" => RevenuPourCourtier::class, "displayField" => "nom"],
                ["code" => "tranches", "intitule" => "Tranches", "type" => "Collection", "targetEntity" => Tranche::class, "displayField" => "nom"],
                ["code" => "documents", "intitule" => "Documents", "type" => "Collection", "targetEntity" => Document::class, "displayField" => "nom"],
                ["code" => "avenants", "intitule" => "Avenants", "type" => "Collection", "targetEntity" => Avenant::class, "displayField" => "numero"],
            ], $this->getSpecificIndicators(), $this->canvasHelper->getGlobalIndicatorsCanvas("Cotation"))
        ];
    }

    private function getSpecificIndicators(): array
    {
        return [
            ["code" => "statutSouscription", "intitule" => "Statut", "type" => "Texte", "format" => "Texte", "description" => "Indique si la cotation a été transformée en police (Souscrite) ou non (En attente)."],
            ["code" => "delaiDepuisCreation", "intitule" => "Âge", "type" => "Texte", "format" => "Texte", "description" => "Nombre de jours écoulés depuis la création de la cotation."],
            ["code" => "nombreTranches", "intitule" => "Nb. Tranches", "type" => "Entier", "format" => "Nombre", "description" => "Nombre de tranches de paiement définies pour cette cotation."],
            ["code" => "montantMoyenTranche", "intitule" => "Moy. par Tranche", "type" => "Nombre", "format" => "Monetaire", "unite" => $this->serviceMonnaies->getCodeMonnaieAffichage(), "description" => "Montant moyen d'une tranche de paiement."],
        ];
    }
}