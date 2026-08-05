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

        // Critères synthétiques « Paiement » (Tranche) : UN PAR AXE (prime, commission,
        // rétro, échéance). Les soldes sont dérivés à la volée, donc absents du canevas
        // d'entité ; ces clés spéciales sont interceptées par le moteur qui filtre/trie en
        // mémoire (cf. TranchePaiementService). Le type 'Boolean' rend un <select> depuis la
        // map de valeurs, badge inclus. Les axes se CUMULENT : chaque badge est indépendant,
        // et le dialogue avancé peut donc croiser « prime impayée » et « échues ».
        // array_unshift en ordre inverse pour que les axes apparaissent dans l'ordre de AXES.
        if ($shortName === 'Tranche') {
            foreach (array_reverse(\App\Services\Search\TranchePaiementScope::AXES, true) as $cleAxe => $axe) {
                array_unshift($searchCriteria, [
                    'Nom' => $cleAxe,
                    'Display' => $axe['libelle'],
                    'Type' => 'Boolean',
                    'Valeur' => $axe['valeurs'],
                    'isDefault' => false,
                    'Icone' => $this->iconCanvasProvider->resolveIconName($axe['icone']) !== null ? $axe['icone'] : 'action:check',
                ]);
            }
        }

        // Critère synthétique « Échéance » (Avenant) : l'échéance est une vraie colonne, mais
        // la traduction valeur → fenêtre temporelle est portée par la clé spéciale
        // AvenantEcheanceScope::CRITERION_KEY, interceptée par le moteur (filtre/tri SQL).
        // Le type 'Boolean' rend un <select> depuis la map de valeurs, badge inclus — les
        // chips de la liste et ce badge manipulent la même clé et restent synchronisés.
        if ($shortName === 'Avenant') {
            array_unshift($searchCriteria, [
                'Nom' => \App\Services\Search\AvenantEcheanceScope::CRITERION_KEY,
                'Display' => 'Échéance',
                'Type' => 'Boolean',
                'Valeur' => \App\Services\Search\AvenantEcheanceScope::VALEURS,
                'isDefault' => false,
                'Icone' => $this->iconCanvasProvider->resolveIconName('avenant') !== null ? 'avenant' : 'action:calendar',
            ]);
        }

        // Critère synthétique « Statut de souscription » (Cotation) : une cotation est
        // « souscrite » dès qu'elle porte un avenant (présence exprimable en SQL, EXISTS),
        // absente du canevas d'entité car dérivée. Porté par la clé spéciale
        // CotationSouscriptionScope::CRITERION_KEY, interceptée par le moteur. Le type
        // 'Boolean' rend un <select> depuis la map de valeurs, badge inclus — les chips de la
        // rubrique et ce badge manipulent la même clé et restent synchronisés.
        if ($shortName === 'Cotation') {
            array_unshift($searchCriteria, [
                'Nom' => \App\Services\Search\CotationSouscriptionScope::CRITERION_KEY,
                'Display' => 'Statut',
                'Type' => 'Boolean',
                'Valeur' => \App\Services\Search\CotationSouscriptionScope::VALEURS,
                'isDefault' => false,
                'Icone' => $this->iconCanvasProvider->resolveIconName('cotation') !== null ? 'cotation' : 'action:check',
            ]);
        }

        // Critère synthétique « Statut de transformation » (Piste) : une piste est
        // « transformée » dès qu'une de ses cotations est souscrite (présence exprimable en SQL,
        // EXISTS), absente du canevas d'entité car dérivée. Porté par la clé spéciale
        // PisteTransformationScope::CRITERION_KEY, interceptée par le moteur. Le type 'Boolean'
        // rend un <select> depuis la map de valeurs, badge inclus — les chips de la rubrique et
        // ce badge manipulent la même clé et restent synchronisés.
        if ($shortName === 'Piste') {
            array_unshift($searchCriteria, [
                'Nom' => \App\Services\Search\PisteTransformationScope::CRITERION_KEY,
                'Display' => 'Statut',
                'Type' => 'Boolean',
                'Valeur' => \App\Services\Search\PisteTransformationScope::VALEURS,
                'isDefault' => false,
                'Icone' => $this->iconCanvasProvider->resolveIconName('piste') !== null ? 'piste' : 'action:check',
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