<?php

namespace App\Services\Canvas;

use App\Entity\Assureur;
use App\Entity\Avenant;
use App\Entity\Client;
use App\Entity\Contact;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Groupe;
use App\Entity\Invite;
use App\Entity\Partenaire;
use App\Entity\Portefeuille;
use App\Entity\Risque;
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

    /**
     * Les rubriques dont chaque ligne appelle getIndicateursGlobaux() avec sa propre
     * cible, et la clé d'option correspondante. Un Contact n'a pas d'agrégat propre :
     * ses chiffres sont ceux de son CLIENT, d'où la cible empruntée.
     */
    private const CIBLES_AGREGEES = [
        Partenaire::class   => 'partenaireCible',
        Client::class       => 'clientCible',
        Assureur::class     => 'assureurCible',
        Risque::class       => 'risqueCible',
        Groupe::class       => 'groupeCible',
        Portefeuille::class => 'portefeuilleCible',
        // Les chiffres d'un invité sont ceux des affaires qu'il a APPORTÉES (agentCible),
        // jamais de celles qu'il gère : une seule clé suffit donc à précharger sa ligne.
        Invite::class       => 'agentCible',
    ];

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
            is_a($entityClass, Contact::class, true)
                => $this->preloadCiblesAgregees(
                    array_filter(array_map(static fn (Contact $c) => $c->getClient(), $items)),
                    'clientCible',
                ),
            default => $this->preloadRubriqueAgregee($entityClass, $items),
        };
    }

    /**
     * Une page de rubrique agrégatrice : son sous-graphe est lu en une passe plutôt
     * qu'une fois par ligne. Les autres rubriques passent sans rien faire.
     */
    private function preloadRubriqueAgregee(string $entityClass, array $items): void
    {
        foreach (self::CIBLES_AGREGEES as $classe => $cleOption) {
            if (is_a($entityClass, $classe, true)) {
                $this->preloadCiblesAgregees($items, $cleOption);

                return;
            }
        }
    }

    /**
     * @param object[] $cibles
     */
    private function preloadCiblesAgregees(array $cibles, string $cleOption): void
    {
        // L'entreprise est celle de la page. Le lot ne franchit jamais cette frontière :
        // une page mêlant deux entreprises ne devrait pas exister, et si elle existait,
        // précharger l'une au nom de l'autre serait une fuite de périmètre.
        $premiere = null;
        foreach ($cibles as $cible) {
            if (method_exists($cible, 'getEntreprise') && $cible->getEntreprise() !== null) {
                $premiere = $cible->getEntreprise();
                break;
            }
        }
        if ($premiere === null) {
            return;
        }

        $memeEntreprise = array_filter(
            $cibles,
            static fn (object $c) => method_exists($c, 'getEntreprise') && $c->getEntreprise() === $premiere,
        );

        $this->calculationHelper->preloadIndicateursGlobauxParCible($premiere, $cleOption, array_values($memeEntreprise));
    }
}