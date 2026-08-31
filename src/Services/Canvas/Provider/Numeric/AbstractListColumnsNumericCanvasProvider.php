<?php

namespace App\Services\Canvas\Provider\Numeric;

/**
 * SOCLE DES RUBRIQUES DONT LA BARRE DES TOTAUX EST EXACTEMENT LE TABLEAU.
 *
 * Une sous-classe ne déclare qu'une chose : l'entité qu'elle sert. Tout le reste — quelles
 * colonnes sont totalisables, sous quel libellé, à quelle échelle — se déduit du canevas
 * de liste, par ListColumnsNumericCanvasBuilder.
 *
 * ⚠ UN PROVIDER PAR ENTITÉ, ET NON UN PROVIDER GÉNÉRIQUE QUI LES SUPPORTERAIT TOUTES.
 * L'aiguilleur (App\Services\Canvas\NumericCanvasProvider) retient le PREMIER provider
 * dont supports() répond vrai, dans l'ordre du TaggedIterator — c'est-à-dire l'ordre de
 * découverte des fichiers, qui n'est contractuel nulle part. Un provider fourre-tout
 * entrerait donc en concurrence silencieuse avec les trente-trois autres, et le gagnant
 * changerait au gré des renommages.
 *
 * Cette classe étant abstraite, le conteneur ne l'enregistre pas (seules les classes
 * instanciables le sont) : elle ne reçoit pas le tag `app.numeric_canvas_provider` et
 * n'apparaît jamais dans l'aiguilleur. Rien à déclarer dans services.yaml.
 */
abstract class AbstractListColumnsNumericCanvasProvider implements NumericCanvasProviderInterface
{
    public function __construct(
        protected readonly ListColumnsNumericCanvasBuilder $builder,
    ) {
    }

    /** @return class-string */
    abstract protected function entityClass(): string;

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === $this->entityClass();
    }

    public function getCanvas(object $object): array
    {
        return $this->builder->build($object);
    }
}
