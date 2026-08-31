<?php

namespace App\Services\Canvas\Provider\Numeric;

use App\Entity\Partenaire;

/**
 * UN COMPTE N'EST PAS UN MONTANT — et la barre ne sait dire que des montants.
 *
 * Cette rubrique offrait vingt-huit totaux pour six colonnes. Le premier de la liste,
 * donc celui présélectionné à l'ouverture, était « Nb. Pistes » : la barre annonçait
 * « Total 11,00 $US » là où onze était un NOMBRE DE PISTES. Vingt-deux autres options
 * portaient sur des grandeurs que le tableau n'affiche pas.
 *
 * Les options sont désormais les colonnes, et rien d'autre : Assiette, Rétro-comm.,
 * Rétro. Payée, Rétro. Solde, Rétro. Exigible. « Part (%) » en est écartée — la somme des
 * parts de dix partenaires n'est pas une part.
 *
 * Les trois compteurs (pistes, clients, polices) restent là où ils ont un sens : la FICHE
 * du partenaire (PartenaireEntityCanvasProvider), qui les nomme au lieu de les additionner.
 */
class PartenaireNumericCanvasProvider extends AbstractListColumnsNumericCanvasProvider
{
    protected function entityClass(): string
    {
        return Partenaire::class;
    }
}
