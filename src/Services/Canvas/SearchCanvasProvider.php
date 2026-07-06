<?php

namespace App\Services\Canvas;

use App\Services\Canvas\Provider\Icon\IconCanvasProvider;

class SearchCanvasProvider
{
    public function __construct(
        private EntityCanvasProvider $entityCanvasProvider,
        private IconCanvasProvider $iconCanvasProvider,
    ) {
    }

    /**
     * Construit le "canevas de recherche" pour une entité donnée.
     * Ce canevas définit les critères disponibles pour la recherche simple et avancée,
     * en s'inspirant de la structure utilisée par le contrôleur Stimulus `search-bar`.
     *
     * @param string $entityClassName Le FQCN (Fully Qualified Class Name) de l'entité.
     * @return array Un tableau de définitions de critères.
     */
    public function getCanvas(string $entityClassName): array
    {
        $searchCriteria = [];
        $entityCanvas = $this->entityCanvasProvider->getCanvas($entityClassName);

        // Si aucun canevas n'est défini pour cette entité, on ne peut rien faire.
        if (empty($entityCanvas) || !isset($entityCanvas['liste'])) {
            return [];
        }

        foreach ($entityCanvas['liste'] as $field) {
            // On ignore les collections car elles ne sont pas des champs de recherche directs.
            if ($field['type'] === 'Collection') {
                continue;
            }

            // NOUVEAU : On ignore le champ 'id' qui n'est pas un critère de recherche pertinent.
            if ($field['code'] === 'id') {
                continue;
            }

            $criterion = [
                'Nom' => $field['code'],
                'Display' => $field['intitule'],
                'isDefault' => false, // Par défaut, aucun n'est le critère simple.
            ];

            // Mappage des types PHP vers les types attendus par le JavaScript
            switch ($field['type']) {
                case 'Texte':
                    $criterion['Type'] = 'Text';
                    $criterion['Valeur'] = '';
                    break;
                case 'Relation':
                    // Une relation est désormais un vrai sélecteur autocomplété côté frontend :
                    // on conserve le nom court de l'entité cible (pour l'endpoint générique
                    // d'autocomplétion) et le champ d'affichage (libellé + recherche texte de
                    // repli). `targetField` reste fourni pour la rétrocompatibilité de la
                    // recherche simple (LIKE sur le displayField).
                    $criterion['Type'] = 'Relation';
                    $criterion['Valeur'] = '';
                    $criterion['displayField'] = $field['displayField'] ?? 'nom';
                    $criterion['targetField'] = $field['displayField'] ?? 'nom';
                    $criterion['targetEntity'] = isset($field['targetEntity'])
                        ? $this->shortEntityName($field['targetEntity'])
                        : null;
                    break;

                case 'Nombre':
                case 'Entier':
                    $criterion['Type'] = 'Number';
                    $criterion['Valeur'] = 0;
                    break;

                case 'Date':
                    // Un champ de date unique est transformé en une plage de dates pour la recherche.
                    $criterion['Type'] = 'DateTimeRange';
                    $criterion['Valeur'] = ['from' => '', 'to' => ''];
                    break;

                case 'Booleen':
                    // Booléen tri-état : « Tous » (option vide côté frontend) / Oui / Non.
                    $criterion['Type'] = 'Boolean';
                    $criterion['Valeur'] = [
                        '1' => 'Oui',
                        '0' => 'Non',
                    ];
                    break;

                default:
                    continue 2; // On saute ce champ si son type n'est pas géré.
            }

            // Icône « de signification » du critère (alias IconCanvasProvider), pour le
            // dialogue de recherche avancée. Purement présentationnel.
            $criterion['Icone'] = $this->criterionIcon($criterion);
            $searchCriteria[] = $criterion;
        }

        // Critère synthétique « Mon portefeuille » pour les rubriques soumises au périmètre
        // portefeuille (Client, Piste, Cotation, Avenant, Sinistres, Tâche, Feedback…).
        // Porté par la clé spéciale PortefeuilleScope::CRITERION_KEY, il permet de retirer,
        // via le badge ou le dialogue avancé, le périmètre appliqué par défaut au chargement
        // (cf. ControllerUtilsTrait::getInitialSearchCriteria). Le moteur l'étend au(x)
        // chemin(s) de relation propre(s) à l'entité (cf. JSBDynamicSearchService).
        $shortName = (new \ReflectionClass($entityClassName))->getShortName();
        if (\App\Services\Search\PortefeuilleScope::isScopable($shortName)) {
            array_unshift($searchCriteria, [
                'Nom' => \App\Services\Search\PortefeuilleScope::CRITERION_KEY,
                'Display' => 'Mon portefeuille',
                'Type' => 'Relation',
                'Valeur' => '',
                'displayField' => 'nom',
                'targetField' => 'nom',
                'targetEntity' => 'Invite',
                'isDefault' => false,
                'Icone' => 'portefeuille',
            ]);
        }

        return $searchCriteria;
    }

    /**
     * Retourne le nom court d'une entité à partir de son FQCN.
     * Ex. : "App\Entity\Client" => "Client".
     */
    private function shortEntityName(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');
        return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
    }

    /**
     * Choisit une icône (alias IconCanvasProvider) adaptée à un critère de recherche.
     * - Relation : icône de l'entité cible si un alias existe (ex. « client », « assureur »),
     *   sinon une icône de filtre générique.
     * - Autres types : icône représentative (date, compteur, case à cocher, description).
     */
    private function criterionIcon(array $criterion): string
    {
        switch ($criterion['Type'] ?? '') {
            case 'DateTimeRange':
                return 'action:calendar';
            case 'Number':
                return 'action:count';
            case 'Boolean':
                return 'action:check';
            case 'Relation':
                $alias = $this->toKebabCase($criterion['targetEntity'] ?? '');
                return ($alias !== '' && $this->iconCanvasProvider->resolveIconName($alias) !== null)
                    ? $alias
                    : 'action:filter';
            case 'Text':
            default:
                return 'action:description';
        }
    }

    /**
     * Convertit un nom court d'entité (CamelCase) en alias kebab-case.
     * Ex. : "CompteBancaire" => "compte-bancaire".
     */
    private function toKebabCase(string $name): string
    {
        if ($name === '') {
            return '';
        }
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $name));
    }
}