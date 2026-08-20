<?php

namespace App\Services\Canvas\Provider\List;

use App\Entity\Contact;

class ContactListCanvasProvider implements ListCanvasProviderInterface
{
    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Contact::class;
    }

    /**
     * AUCUNE COLONNE NUMÉRIQUE — ni dans la rubrique, ni dans la collection d'une fiche.
     *
     * La liste affichait « Prime Totale », « Comm. Totale », « Comm. Pure » et « Réserve ».
     * Or ces montants ne sont pas ceux du contact : ce sont ceux de SON CLIENT, répétés à
     * l'identique sur chacune de ses lignes. Quatre colonnes qui disent la même chose six
     * fois, et qui laissent croire qu'un interlocuteur produirait du chiffre d'affaires.
     *
     * Un contact est une personne : un nom, une fonction, une adresse. C'est ce qu'on vient
     * y chercher, et le reste encombre — d'autant que la colonne principale s'en trouve
     * comprimée, jusqu'à couper les noms dans l'accordéon d'une collection.
     *
     * Les chiffres du client restent disponibles là où ils ont un sens : sur la FICHE du
     * contact, où ContactEntityCanvasProvider les regroupe sous des intitulés qui nomment
     * leur véritable porteur (« Prime (Client) », « Revenu (Client) »). Les retirer de là
     * supprimerait aussi les filtres de recherche correspondants.
     *
     * Même parti pris que Classeur, Document, Tâche ou Chargement : la clé est simplement
     * absente, elle n'a pas besoin d'être déclarée vide.
     */
    public function getCanvas(): array
    {
        return [
            "colonne_principale" => [
                "titre_colonne" => "Contacts",
                "texte_principal" => ["attribut_code" => "nom", "icone" => "contact"],
                "textes_secondaires_separateurs" => " • ",
                "textes_secondaires" => [
                    ["attribut_code" => "fonction"],
                    ["attribut_code" => "email"]
                ],
            ],
        ];
    }
}
