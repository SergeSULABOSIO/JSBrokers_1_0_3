<?php

namespace App\Services\Canvas\Indicator;

use App\Entity\TypeAbsence;

/**
 * CE QU'UNE LIGNE DE LA LISTE DES TYPES D'ABSENCE DOIT MONTRER.
 *
 * Un type d'absence ne se lit pas sans sa RÈGLE : « décompté du solde » est ce qui
 * sépare un congé annuel d'un arrêt maladie, et c'est invisible tant qu'on n'ouvre pas
 * la fiche. On le remonte donc sur la ligne, en une phrase.
 */
class TypeAbsenceIndicatorStrategy implements IndicatorCalculationStrategyInterface
{
    public function supports(string $entityClassName): bool
    {
        return $entityClassName === TypeAbsence::class;
    }

    public function calculate(object $entity): array
    {
        /** @var TypeAbsence $entity */
        $regles = [
            $entity->isDecompte() ? 'Décompté du solde' : 'Sans effet sur le solde',
        ];

        if ($entity->isJustificatifRequis()) {
            $regles[] = 'justificatif obligatoire';
        }
        if ($entity->getPlafondParDemande() !== null) {
            $regles[] = sprintf('plafonné à %s j par demande', rtrim(rtrim((string) $entity->getPlafondParDemande(), '0'), '.'));
        }
        if (!$entity->isAutoriseDemiJournee()) {
            $regles[] = 'demi-journées interdites';
        }
        if (!$entity->isActif()) {
            $regles[] = 'DÉSACTIVÉ';
        }

        return [
            'regleLibelle' => implode(' · ', $regles),
            'actifLabel'   => $entity->isActif() ? 'Actif' : 'Désactivé',
        ];
    }
}
