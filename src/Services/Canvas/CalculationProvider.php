<?php

namespace App\Services\Canvas;

use App\Entity\Entreprise;
use App\Services\Canvas\Indicator\IndicatorCalculationHelper;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;

class CalculationProvider
{
    /**
     * @var iterable<IndicatorCalculationStrategyInterface>
     */
    private iterable $strategies;

    public function __construct(
        #[TaggedIterator('app.indicator_calculation_strategy')] iterable $strategies,
        private IndicatorCalculationHelper $calculationHelper
    ) {
        $this->strategies = $strategies;
    }

    /**
     * Calcule les indicateurs spécifiques pour une entité donnée
     * en déléguant dynamiquement la tâche à la stratégie correspondante.
     *
     * @param object $entity L'entité pour laquelle calculer les indicateurs.
     * @return array Un tableau associatif d'indicateurs calculés.
     */
    public function getIndicateursSpecifics(object $entity): array
    {
        $entityClass = get_class($entity);

        foreach ($this->strategies as $strategy) {
            if ($strategy->supports($entityClass)) {
                return $strategy->calculate($entity);
            }
        }

        // Retourne un tableau vide si aucune stratégie n'est trouvée pour cette entité
        return [];
    }

    /**
     * Calcule les indicateurs globaux du portefeuille.
     * Délégué au Helper pour maintenir cette classe propre tout en assurant la rétrocompatibilité parfaite.
     *
     * @param Entreprise $entreprise
     * @param bool $isBound
     * @param array $options
     * @return array
     */
    public function getIndicateursGlobaux(Entreprise $entreprise, bool $isBound, array $options = []): array
    {
        return $this->calculationHelper->getIndicateursGlobaux($entreprise, $isBound, $options);
    }
}