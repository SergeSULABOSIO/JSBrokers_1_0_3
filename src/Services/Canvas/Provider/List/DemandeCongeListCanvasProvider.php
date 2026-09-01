<?php

namespace App\Services\Canvas\Provider\List;

use App\Entity\DemandeConge;
use App\Services\Search\CongeStatutScope;

class DemandeCongeListCanvasProvider implements ListCanvasProviderInterface
{
    public function supports(string $entityClassName): bool
    {
        return $entityClassName === DemandeConge::class;
    }

    public function getCanvas(): array
    {
        return [
            "colonne_principale" => [
                "titre_colonne" => "Congés",
                // DES CODES PLATS, JAMAIS DE CHEMIN POINTÉ : « agent.nom » casserait le
                // rendu dès la première ligne. Les libellés viennent de
                // DemandeCongeIndicatorStrategy.
                "texte_principal" => ["attribut_code" => "agentNom", "icone" => "fa-solid:house-user"],
                "textes_secondaires" => [
                    ["attribut_code" => "periodeLibelle"],
                    ["attribut_code" => "typeAbsenceLibelle"],
                    ["attribut_prefixe" => "Statut : ", "attribut_code" => "statutLibelle"],
                ],
            ],
            // LE SOLDE DE L'AGENT, EN COLONNE. C'est l'information dont le valideur a
            // besoin avant de décider — approuver dix jours à quelqu'un qui n'en a plus
            // que trois se répare mal.
            //
            // Le NOMBRE DE JOURS n'y figure pas : il est déjà dans la période, où il se
            // lit avec ses dates. Deux fois le même nombre sur une ligne se lit comme
            // deux nombres différents.
            "colonnes_numeriques" => [],
            "filtres_predefinis" => [
                [
                    // Le statut est une VRAIE colonne : aucun critère synthétique à
                    // traduire. Le vocabulaire, lui, vient de CongeStatutScope — écran,
                    // chips, mails et assistant nomment un même état d'un même mot.
                    "critere" => CongeStatutScope::CRITERION_KEY,
                    "libelle" => "Statut",
                    "options" => CongeStatutScope::optionsChips(),
                ],
            ],
        ];
    }
}
