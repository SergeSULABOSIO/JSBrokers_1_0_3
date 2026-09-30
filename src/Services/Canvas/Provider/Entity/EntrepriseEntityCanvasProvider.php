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
                // ⚠ LE CAPITAL SE SAISIT EN MONNAIE LOCALE, ET SE LIT DANS LA MÊME. C'est
                // l'exception de l'application : partout ailleurs les montants sont
                // convertis vers la monnaie d'affichage, mais celui-ci est un montant
                // STATUTAIRE, écrit tel quel dans les statuts du cabinet. L'afficher en
                // monnaie d'affichage sans l'avoir converti donnait « 4 000 000 USD » pour
                // 4 000 000 CDF — un facteur de deux mille huit cents, sans le moindre
                // signe. Le formulaire de saisie, le menu latéral et l'en-tête des PDF
                // tenaient déjà la bonne règle ; cette fiche était seule à s'en écarter.
                //
                // ⚠ LA COMPTABILITÉ, ELLE, LE CONVERTIT — et c'est normal : des états ne
                // s'additionnent que dans une monnaie unique. C'est la règle comptable qui
                // y décide, pas celle-ci.
                ["code" => "capitalSociale", "intitule" => "Capital Social", "type" => "Nombre", "format" => "Monetaire", "unite" => $this->serviceMonnaies->getCodeMonnaieLocale()],
                ["code" => "siteweb", "intitule" => "Site Web", "type" => "Texte"],
                ["code" => "utilisateur", "intitule" => "Créateur", "type" => "Relation", "targetEntity" => Utilisateur::class, "displayField" => "nom"],
                ["code" => "createdAt", "intitule" => "Créée le", "type" => "Date"],
                // Collections
                ["code" => "invites", "intitule" => "Collaborateurs", "type" => "Collection", "targetEntity" => Invite::class, "displayField" => "nom"],
                // ── DOUZE ONGLETS FANTOMES RETIRES LE 2026-09-30 ────────────────────
                // Clients, Partenaires, Assureurs, Groupes, Risques, Monnaies, Taxes,
                // Comptes bancaires, Types de revenu, Types de chargement, Classeurs et
                // Modeles de pieces etaient declares ici comme collections d'Entreprise.
                // Or l'entite n'en porte AUCUNE : elle n'a que `utilisateurs`, `invites`
                // et `documents`. Ces onglets ne pouvaient donc pas s'ouvrir — pas de
                // getter, donc 404 —, et surtout ils ne pouvaient pas se filtrer.
                // Ces rubriques existent par ailleurs, au menu, ou elles fonctionnent.
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