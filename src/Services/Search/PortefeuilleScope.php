<?php

namespace App\Services\Search;

/**
 * Registre du « périmètre portefeuille » : pour chaque rubrique concernée, décrit le(s)
 * chemin(s) de relation menant au gestionnaire d'un portefeuille (Portefeuille.gestionnaire).
 *
 * Ce périmètre est appliqué par défaut au premier chargement de ces listes (cf.
 * ControllerUtilsTrait::getInitialSearchCriteria) : l'invité connecté ne voit d'abord que
 * les éléments rattachés — directement ou indirectement — à un portefeuille qu'il gère.
 *
 * Certaines entités (Tâche, Feedback) n'ont pas de lien unique : elles peuvent être reliées
 * à une Piste, une Cotation, un Sinistre… D'où une LISTE de chemins combinés en OU par le
 * moteur de recherche (JSBDynamicSearchService) : l'élément est visible si AU MOINS un de
 * ses parents renseignés relève d'un portefeuille géré par l'invité.
 */
final class PortefeuilleScope
{
    /**
     * Clé de critère synthétique portée par la barre de recherche (badge « Mon portefeuille »).
     * La valeur transmise est l'id de l'invité gestionnaire ; le moteur l'étend aux chemins
     * ci-dessous selon l'entité interrogée.
     */
    public const CRITERION_KEY = '__mon_portefeuille__';

    /**
     * @var array<string, string[]> Nom court d'entité => chemins de relation vers
     *      « …portefeuille.gestionnaire » (combinés en OU).
     */
    private const PATHS = [
        'Client' => ['portefeuille.gestionnaire'],
        'Piste' => ['client.portefeuille.gestionnaire'],
        'Cotation' => ['piste.client.portefeuille.gestionnaire'],
        'Avenant' => ['cotation.piste.client.portefeuille.gestionnaire'],
        'NotificationSinistre' => ['assure.portefeuille.gestionnaire'],
        'OffreIndemnisationSinistre' => ['notificationSinistre.assure.portefeuille.gestionnaire'],
        'Tache' => [
            'piste.client.portefeuille.gestionnaire',
            'cotation.piste.client.portefeuille.gestionnaire',
            'notificationSinistre.assure.portefeuille.gestionnaire',
            'offreIndemnisationSinistre.notificationSinistre.assure.portefeuille.gestionnaire',
        ],
        'Feedback' => [
            'tache.piste.client.portefeuille.gestionnaire',
            'tache.cotation.piste.client.portefeuille.gestionnaire',
            'tache.notificationSinistre.assure.portefeuille.gestionnaire',
            'tache.offreIndemnisationSinistre.notificationSinistre.assure.portefeuille.gestionnaire',
        ],
    ];

    /**
     * Retourne les chemins de périmètre pour une entité (nom court), ou un tableau vide si
     * l'entité n'est pas concernée par ce filtre par défaut.
     *
     * @return string[]
     */
    public static function pathsFor(string $entityShortName): array
    {
        return self::PATHS[$entityShortName] ?? [];
    }

    /**
     * Indique si l'entité (nom court) est soumise au périmètre portefeuille.
     */
    public static function isScopable(string $entityShortName): bool
    {
        return isset(self::PATHS[$entityShortName]);
    }
}
