<?php

namespace App\Services\Canvas\Indicator;

use App\Entity\ParametresConge;

/**
 * LES RÉGLAGES SE LISENT SUR LA LIGNE, pas dans la fiche.
 *
 * La rubrique ne contient qu'un enregistrement : si sa ligne se contente d'annoncer
 * « Paramètres des congés », il faut l'ouvrir pour savoir ce qui est réglé. Le résumé dit
 * l'essentiel — et surtout ce qui est DÉSACTIVÉ, qu'un formulaire à zéro laisse deviner.
 */
class ParametresCongeIndicatorStrategy implements IndicatorCalculationStrategyInterface
{
    public function supports(string $entityClassName): bool
    {
        return $entityClassName === ParametresConge::class;
    }

    public function calculate(object $entity): array
    {
        /** @var ParametresConge $entity */
        $morceaux = [
            $entity->controlePreavis()
                ? sprintf('préavis %d j', $entity->getDelaiPreavisJours())
                : 'préavis désactivé',
            $entity->controleAbsentsSimultanes()
                ? sprintf('%d absent(s) max par équipe', (int) $entity->getMaxAbsentsSimultanes())
                : 'plafond d\'absents désactivé',
            sprintf('dotation %s j', rtrim(rtrim(number_format($entity->dotationAnnuelleFloat(), 1, ',', ' '), '0'), ',')),
            $entity->getRelanceApresJours() > 0
                ? sprintf('relance à %d j', $entity->getRelanceApresJours())
                : 'relances désactivées',
        ];

        return [
            'resumeReglages' => implode(' · ', $morceaux),
            'nombrePeriodesBlocage' => $entity->getPeriodesBlocage()->count(),
        ];
    }
}
