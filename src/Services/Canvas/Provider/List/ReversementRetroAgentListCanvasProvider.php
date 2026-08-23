<?php

namespace App\Services\Canvas\Provider\List;

use App\Entity\ReversementRetroAgent;
use App\Services\ServiceMonnaies;

/**
 * LA LISTE DES REVERSEMENTS — une ligne par versement, telle qu'elle est en base.
 *
 * C'est la surface canonique : recherche, tri, fiche, et surtout les deux actions
 * documentaires que FormCanvasProvider injecte sur toute entité portant une collection
 * de documents. Sans cette rubrique, un bordereau oublié au moment du virement n'avait
 * plus AUCUN endroit où être joint.
 *
 * ⚠ ELLE NE REMPLACE PAS LE VOLET « VERSEMENTS ENREGISTRÉS » du rapport de production.
 * Ici, un virement qui solde trois affaires apparaît en TROIS lignes — c'est la vérité
 * de la base, et c'est ce qu'il faut pour retrouver une ligne précise. Le volet, lui,
 * groupe par virement : un décaissement, une ligne, une preuve. Deux lectures, deux
 * questions différentes.
 */
class ReversementRetroAgentListCanvasProvider implements ListCanvasProviderInterface
{
    public function __construct(private ServiceMonnaies $serviceMonnaies)
    {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === ReversementRetroAgent::class;
    }

    public function getCanvas(): array
    {
        return [
            "colonne_principale" => [
                "titre_colonne" => "Reversements de rétrocommission",
                "texte_principal" => ["attribut_code" => "reference", "icone" => "depense"],
                "textes_secondaires_separateurs" => " • ",
                // DES CODES PLATS, JAMAIS DE CHEMIN POINTÉ. Le rendu d'une ligne fait
                // `attribute(entity, code)` : « agent.nom » n'y est pas un chemin mais un nom
                // de propriété, et la rubrique tombait en erreur dès la première ligne — ce
                // qu'une liste vide ne pouvait pas révéler. Les données de relation passent
                // donc par ReversementRetroAgentIndicatorStrategy.
                "textes_secondaires" => [
                    ["attribut_prefixe" => "Bénéficiaire : ", "attribut_code" => "beneficiaireNom", "attribut_type" => "text"],
                    ["attribut_prefixe" => "Police : ", "attribut_code" => "policeReference", "attribut_type" => "text"],
                    ["attribut_prefixe" => "Versé le : ", "attribut_code" => "paidAt", "attribut_type" => "date"],
                    ["attribut_prefixe" => "Débité de : ", "attribut_code" => "compteLibelle", "attribut_type" => "text"],
                    // Un virement groupé se dit : sans cela, trois lignes d'un même
                    // décaissement se liraient comme trois virements distincts.
                    ["attribut_code" => "virementGroupe", "attribut_type" => "text"],
                ],
            ],
            "colonnes_numeriques" => [
                [
                    "titre_colonne" => "Montant versé",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    "attribut_code" => "montant",
                    "attribut_type" => "nombre",
                ],
            ],
        ];
    }
}
