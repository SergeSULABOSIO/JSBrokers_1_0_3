<?php

namespace App\Services\Canvas\Provider\Numeric;

use App\Entity\ReversementRetroAgent;
use App\Service\Retro\LotDeVersement;

/**
 * Valeurs numériques totalisables de la rubrique « Rétros agents » (barre des totaux :
 * total global + total de la sélection). Montants en CENTIMES — c'est le contrat du
 * contrôleur Stimulus `list-summary`, partagé par les 34 rubriques.
 *
 * POURQUOI CE FICHIER EXISTE. La rubrique était la SEULE à n'avoir aucun fournisseur
 * numérique : la barre annonçait « aucune valeur numérique » et un total figé à 0,00,
 * alors que chaque ligne porte un décaissement. Rien n'était cassé — la pièce manquait.
 *
 * ⚠ LE PIÈGE DU LOT, DEVENU RÉEL AVEC LE REPLI. Ce fichier lisait `getMontant()` — la part
 * de la LIGNE — et c'était juste tant que la rubrique montrait une ligne par échéance.
 * Depuis qu'elle replie chaque lot sur son porteur, additionner les parts des seuls
 * porteurs aurait rendu le sixième du décaissement réel, sans erreur ni avertissement : un
 * total d'apparence normale, et faux.
 *
 * La barre lit donc EXACTEMENT ce que la colonne affiche — `montantAffiche`, qui vaut le
 * virement entier sur une vue repliée et la seule ligne sur la vue détaillée. Une seule
 * source, deux surfaces : c'est la seule façon qu'elles ne divergent jamais.
 */
class ReversementRetroAgentNumericCanvasProvider implements NumericCanvasProviderInterface
{
    public function __construct(private readonly LotDeVersement $lots)
    {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === ReversementRetroAgent::class;
    }

    public function getCanvas(object $object): array
    {
        /** @var ReversementRetroAgent $object */

        return [
            'montant' => [
                'description' => 'Montant versé',
                'value' => $this->lots->montantAffiche($object) * 100,
            ],
        ];
    }
}
