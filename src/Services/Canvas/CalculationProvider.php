<?php

namespace App\Services\Canvas;

use App\Entity\Avenant;
use App\Entity\Cotation;
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

        // Résolution des Proxies Doctrine : on récupère la classe parente (la vraie entité)
        if ($entity instanceof \Doctrine\Persistence\Proxy) {
            $entityClass = get_parent_class($entity);
        }

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

    public function batchPreload(array $items): void
    {
        if (empty($items)) return;
        $first = reset($items);
        $entityClass = ($first instanceof \Doctrine\Persistence\Proxy)
            ? get_parent_class($first)
            : get_class($first);

        match (true) {
            is_a($entityClass, Cotation::class, true)
                => $this->calculationHelper->preloadCotationRelations($items),
            is_a($entityClass, Avenant::class, true)
                => $this->calculationHelper->preloadAvenantRelations($items),
            default => null,
        };
    }
}