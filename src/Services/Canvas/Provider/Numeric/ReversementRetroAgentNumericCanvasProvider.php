<?php

namespace App\Services\Canvas\Provider\Numeric;

use App\Entity\ReversementRetroAgent;

/**
 * Valeurs numériques totalisables de la rubrique « Rétros agents » (barre des totaux :
 * total global + total de la sélection). Montants en CENTIMES — c'est le contrat du
 * contrôleur Stimulus `list-summary`, partagé par les 34 rubriques.
 *
 * POURQUOI CE FICHIER EXISTE. La rubrique était la SEULE à n'avoir aucun fournisseur
 * numérique : la barre annonçait « aucune valeur numérique » et un total figé à 0,00,
 * alors que chaque ligne porte un décaissement. Rien n'était cassé — la pièce manquait.
 *
 * LE PIÈGE DU LOT, ICI ABSENT. Un virement groupé solde N affaires d'un seul ordre, mais
 * chaque ligne porte SA part (1 250 + 830), jamais le total répété : additionner les
 * lignes donne donc le décaissement réel. C'est l'inverse du cumul d'une colonne répétée
 * déjà rencontré sur les tranches, et c'est vérifié par un test plutôt que supposé.
 */
class ReversementRetroAgentNumericCanvasProvider implements NumericCanvasProviderInterface
{
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
                'value' => (float) ($object->getMontant() ?? 0.0) * 100,
            ],
        ];
    }
}
