<?php

namespace App\Services\Canvas\Indicator;

use App\Entity\RegimeTravail;

/**
 * CE QU'UNE LIGNE DE RÉGIME DE TRAVAIL DOIT MONTRER.
 *
 * Les jours travaillés vivent dans une colonne JSON : illisible tel quel. Le libellé
 * vient de l'entité (RegimeTravail::getJoursOuvresLibelle) — source unique partagée avec
 * le reste de l'application, pour qu'un régime se lise du même mot partout.
 */
class RegimeTravailIndicatorStrategy implements IndicatorCalculationStrategyInterface
{
    public function supports(string $entityClassName): bool
    {
        return $entityClassName === RegimeTravail::class;
    }

    public function calculate(object $entity): array
    {
        /** @var RegimeTravail $entity */
        $debut = $entity->getDateDebut()?->format('d/m/Y');
        $fin = $entity->getDateFin()?->format('d/m/Y');

        return [
            'joursLibelle' => $entity->getJoursOuvresLibelle() ?: 'Aucun jour déclaré',
            // Le taux se lit en pourcentage : « 0.80 » est un nombre de base de données,
            // « 80 % » est ce que dit un contrat de travail.
            'tauxLibelle' => sprintf('%s %%', rtrim(rtrim(number_format(((float) $entity->getTauxOccupation()) * 100, 1, ',', ' '), '0'), ',')),
            // Un régime sans fin est le régime EN VIGUEUR : le dire, plutôt que de
            // laisser une date vide qu'on lit comme une donnée manquante.
            'periodeLibelle' => $fin === null
                ? sprintf('Depuis le %s (en vigueur)', $debut ?? '?')
                : sprintf('Du %s au %s', $debut ?? '?', $fin),
        ];
    }
}
