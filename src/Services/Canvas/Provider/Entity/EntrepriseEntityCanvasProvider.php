<?php

namespace App\Services\Canvas\Provider\Entity;

use App\Entity\Assureur;
use App\Entity\Chargement;
use App\Entity\Classeur;
use App\Entity\Client;
use App\Entity\CompteBancaire;
use App\Entity\Entreprise;
use App\Entity\Groupe;
use App\Entity\Invite;
use App\Entity\ModelePieceSinistre;
use App\Entity\Monnaie;
use App\Entity\Partenaire;
use App\Entity\Risque;
use App\Entity\Taxe;
use App\Entity\TypeRevenu;
use App\Entity\Utilisateur;
use App\Services\Canvas\CanvasHelper;
use App\Services\ServiceMonnaies;

class EntrepriseEntityCanvasProvider implements EntityCanvasProviderInterface
{
    public function __construct(
        private ServiceMonnaies $serviceMonnaies,
        private CanvasHelper $canvasHelper
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Entreprise::class;
    }

    public function getCanvas(): array
    {
        return [
            "parametres" => [
                "description" => "Entreprise",
                "icone" => "entreprise",
                'background_image' => '/images/fitures/default.jpg',
                'description_template' => [
                    "Entreprise [[*nom]].",
                    " Adresse: [[adresse]].",
                    " Contact: [[telephone]] / [[siteweb]]."
                ]
            ],
            "liste" => array_merge([
                ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                ["code" => "nom", "intitule" => "Nom", "type" => "Texte"],
                ["code" => "licence", "intitule" => "Licence", "type" => "Texte"],
                ["code" => "adresse", "intitule" => "Adresse", "type" => "Texte"],
                ["code" => "telephone", "intitule" => "Téléphone", "type" => "Texte"],
                ["code" => "rccm", "intitule" => "RCCM", "type" => "Texte"],
                ["code" => "idnat", "intitule" => "ID.NAT", "type" => "Texte"],
                ["code" => "numimpot", "intitule" => "N° Impôt", "type" => "Texte"],
                ["code" => "capitalSociale", "intitule" => "Capital Social", "type" => "Nombre", "format" => "Monetaire", "unite" => $this->serviceMonnaies->getCodeMonnaieAffichage()],
                ["code" => "siteweb", "intitule" => "Site Web", "type" => "Texte"],
                ["code" => "utilisateur", "intitule" => "Créateur", "type" => "Relation", "targetEntity" => Utilisateur::class, "displayField" => "nom"],
                ["code" => "createdAt", "intitule" => "Créée le", "type" => "Date"],
                // Collections
                ["code" => "invites", "intitule" => "Collaborateurs", "type" => "Collection", "targetEntity" => Invite::class, "displayField" => "nom"],
                ["code" => "clients", "intitule" => "Clients", "type" => "Collection", "targetEntity" => Client::class, "displayField" => "nom"],
                ["code" => "partenaires", "intitule" => "Partenaires", "type" => "Collection", "targetEntity" => Partenaire::class, "displayField" => "nom"],
                ["code" => "assureurs", "intitule" => "Assureurs", "type" => "Collection", "targetEntity" => Assureur::class, "displayField" => "nom"],
                ["code" => "groupes", "intitule" => "Groupes", "type" => "Collection", "targetEntity" => Groupe::class, "displayField" => "nom"],
                ["code" => "risques", "intitule" => "Risques", "type" => "Collection", "targetEntity" => Risque::class, "displayField" => "nomComplet"],
                ["code" => "monnaies", "intitule" => "Monnaies", "type" => "Collection", "targetEntity" => Monnaie::class, "displayField" => "nom"],
                ["code" => "taxes", "intitule" => "Taxes", "type" => "Collection", "targetEntity" => Taxe::class, "displayField" => "nom"],
                ["code" => "compteBancaires", "intitule" => "Comptes Bancaires", "type" => "Collection", "targetEntity" => CompteBancaire::class, "displayField" => "nom"],
                ["code" => "typerevenus", "intitule" => "Types de Revenu", "type" => "Collection", "targetEntity" => TypeRevenu::class, "displayField" => "nom"],
                ["code" => "chargements", "intitule" => "Types de Chargement", "type" => "Collection", "targetEntity" => Chargement::class, "displayField" => "nom"],
                ["code" => "classeurs", "intitule" => "Classeurs", "type" => "Collection", "targetEntity" => Classeur::class, "displayField" => "nom"],
                ["code" => "modelePieceSinistres", "intitule" => "Modèles de Pièces", "type" => "Collection", "targetEntity" => ModelePieceSinistre::class, "displayField" => "nom"],
            ], $this->getSpecificIndicators(), $this->canvasHelper->getGlobalIndicatorsCanvas("Entreprise"))
        ];
    }

    private function getSpecificIndicators(): array
    {
        return [
            ["code" => "ageEntreprise", "intitule" => "Âge", "type" => "Texte", "format" => "Texte", "description" => "Nombre de jours depuis la création de l'entreprise."],
            ["code" => "nombreCollaborateurs", "intitule" => "Nb. Collabs", "type" => "Entier", "format" => "Nombre", "description" => "Nombre total de collaborateurs (invités)."],
            ["code" => "nombreClients", "intitule" => "Nb. Clients", "type" => "Entier", "format" => "Nombre", "description" => "Nombre total de clients."],
            ["code" => "nombrePartenaires", "intitule" => "Nb. Partenaires", "type" => "Entier", "format" => "Nombre", "description" => "Nombre total de partenaires."],
            ["code" => "nombreAssureurs", "intitule" => "Nb. Assureurs", "type" => "Entier", "format" => "Nombre", "description" => "Nombre total d'assureurs."],
        ];
    }
}