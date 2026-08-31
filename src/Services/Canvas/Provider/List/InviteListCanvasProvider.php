<?php

namespace App\Services\Canvas\Provider\List;

use App\Entity\Invite;
use App\Services\ServiceMonnaies;

class InviteListCanvasProvider implements ListCanvasProviderInterface
{
    public function __construct(private ServiceMonnaies $serviceMonnaies)
    {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Invite::class;
    }

    public function getCanvas(): array
    {
        return [
            "colonne_principale" => [
                "titre_colonne" => "Invitations",
                "texte_principal" => ["attribut_code" => "nom", "icone" => "invite"],
                "textes_secondaires_separateurs" => " • ",
                "textes_secondaires" => [
                    ["attribut_prefixe" => "Statut: ", "attribut_code" => "status_string"],
                    // Rattachement portefeuille : toujours renseigné (« Aucun portefeuille »
                    // si l'invité n'en gère pas) — même icône dossier que la liste Clients.
                    ["attribut_code" => "portefeuilleNom", "icone" => "lucide:folder"],
                ],
            ],
            // TOUTES CES COLONNES DÉCRIVENT LE MÊME PÉRIMÈTRE : les affaires que l'invité
            // a APPORTÉES — celles où une condition de partage le rémunère — et non celles
            // qu'il gère comme gestionnaire de compte. La ligne se lit donc d'un bloc :
            // la prime produite, ce qu'elle a rapporté, et ce qui lui en revient.
            //
            // Y mêler la production gérée attribuerait à un gestionnaire le résultat
            // commercial de ses collègues, juste à côté d'une rétrocommission à zéro.
            //
            // Les `attribut_code` sont ceux publiés par InviteIndicatorStrategy (camelCase,
            // convention des stratégies vivantes). Ils pointaient auparavant les propriétés
            // snake_case de CalculatedIndicatorsTrait, que rien n'alimente : les quatre
            // premières colonnes affichaient « — ».
            "colonnes_numeriques" => [
                [
                    "titre_colonne" => "Prime apportée",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    "attribut_code" => "primeTotale",
                    "attribut_type" => "nombre",
                ],
                [
                    "titre_colonne" => "Comm. apportée",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    "attribut_code" => "montantTTC",
                    "attribut_type" => "nombre",
                ],
                [
                    "titre_colonne" => "Comm. pure",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    "attribut_code" => "montantPur",
                    "attribut_type" => "nombre",
                ],
                [
                    "titre_colonne" => "Réserve cabinet",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    "attribut_code" => "reserve",
                    "attribut_type" => "nombre",
                ],
                [
                    "titre_colonne" => "Rétrocom. due",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    "attribut_code" => "retroAgentDue",
                    "attribut_type" => "nombre",
                ],
                [
                    "titre_colonne" => "Rétrocom. payée",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    "attribut_code" => "retroAgentPayee",
                    "attribut_type" => "nombre",
                ],
                [
                    "titre_colonne" => "Rétrocom. solde",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    "attribut_code" => "retroAgentSolde",
                    "attribut_type" => "nombre",
                ],
                [
                    // CE QU'IL FAUT VERSER MAINTENANT. Le solde dit la dette entière,
                    // dont une part n'est pas encore réclamable : le cabinet n'a pas
                    // encaissé la commission qui la justifie. C'est cette colonne — et
                    // non le solde — qu'on lit pour décider d'un reversement, et c'est
                    // elle qui commande déjà l'apparition des actions rapport/versement
                    // (`hasRetroAgentExigible`).
                    "titre_colonne" => "Rétrocom. exigible",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    "attribut_code" => "retroAgentExigible",
                    "attribut_type" => "nombre",
                ],
            ],
        ];
    }
}