<?php

namespace App\Services\Canvas\Provider\Form;

use App\Services\CanvasBuilder;

trait FormCanvasProviderTrait
{
    private function addCollectionWidgetsToLayout(array &$layout, object $parentEntity, bool $isParentNew, array $collectionsConfig, ?int $idEntreprise = null, ?int $idInvite = null, ?CanvasBuilder $canvasBuilder = null): void
    {
        $parentId = $parentEntity->getId() ?? 0;
        foreach ($collectionsConfig as $config) {
            $extraOptions = [];
            
            // Capture des options de rendu pour la ligne secondaire enrichie
            if (isset($config['secondaryField'])) $extraOptions['secondaryField'] = $config['secondaryField'];
            if (isset($config['secondaryLabel'])) $extraOptions['secondaryLabel'] = $config['secondaryLabel'];
            if (isset($config['watchIds'])) $extraOptions['watchIds'] = $config['watchIds'];
            // Permet de remplacer l'URL de suppression par défaut (child::delete) par une
            // action non destructive — ex. « détacher » un client d'un portefeuille sans
            // supprimer l'entité partagée. %parentId% est substitué par l'ID du parent.
            if (isset($config['itemDeleteUrl'])) {
                $extraOptions['itemDeleteUrl'] = str_replace('%parentId%', (string) $parentId, $config['itemDeleteUrl']);
            }
            // Mode « sélection de ressources existantes » : le bouton Ajouter ouvre une
            // boîte de choix (ex. clients d'un portefeuille) au lieu d'un formulaire.
            if (isset($config['pickerUrl'])) {
                $extraOptions['pickerUrl'] = str_replace('%parentId%', (string) $parentId, $config['pickerUrl']);
            }
            // Personnalisation des actions de ligne (bouton d'édition masqué, libellé/icône
            // de l'action de suppression — ex. « Retirer » pour un détachement).
            // NOM DE ROUTE DU PARENT, quand il diffère du nom du champ.
            //
            // Les URL de collection se déduisent du `parentFieldName` : /admin/piste/api/…
            // pour un champ « piste ». La déduction tombe dès que le champ ne peut PAS
            // porter le nom de l'entité — c'est le cas des documents rattachés à une
            // entreprise ou à un invité, où « entreprise » et « invite » sont déjà pris
            // sur Document par le scoping d'AuditableTrait, et où le champ s'appelle donc
            // « entrepriseRattachee » / « inviteRattache ». Sans cette échappatoire, le
            // widget appellerait /admin/inviterattache/api/… — une route qui n'existe pas.
            if (isset($config['parentRouteName'])) $extraOptions['parentRouteName'] = $config['parentRouteName'];
            if (isset($config['hideEditAction']))    $extraOptions['hideEditAction'] = $config['hideEditAction'];
            if (isset($config['deleteActionLabel']))  $extraOptions['deleteActionLabel'] = $config['deleteActionLabel'];
            if (isset($config['deleteActionIcon']))   $extraOptions['deleteActionIcon'] = $config['deleteActionIcon'];

            // LE CHAMP TOTALISABLE EST UNE DONNEE DE CONFIGURATION : il vaut en CREATION
            // comme en edition.
            //
            // Il etait auparavant conditionne a `!$isParentNew`, le temps d'une addition
            // faite ici meme. Cette addition n'etait lue par PERSONNE — ni par un gabarit,
            // ni par le navigateur (aucun `totalValue` dans assets/) — et coutait un
            // `loadAllCalculatedValues()` par element de chaque collection totalisable, a
            // CHAQUE construction de formulaire. Le seul qui additionne est
            // `handleCollectionApiRequest()`, qui l'avait deja en main pour sa reponse
            // JSON : une addition, un nombre.
            //
            // Ne plus declarer le champ en creation avait deux effets invisibles : aucun
            // pied de total (le serveur ignorait qu'il y avait quelque chose a totaliser),
            // et une colonne de valeur qui retombait sur la premiere colonne numerique de
            // l'enfant au lieu du champ totalisable — donc pas forcement la meme grandeur
            // qu'en edition.
            if (isset($config['totalizableField'])) {
                $extraOptions['totalizableField'] = $config['totalizableField'];
            }

            // LE VERROU DE LA CRÉATION — ouvert, sauf là où l'enfant ne peut pas s'en passer.
            //
            // Une collection était inerte tant que sa fiche parente n'avait pas d'id : il
            // fallait enregistrer, rouvrir, puis saisir. Ses éléments sont désormais gardés
            // en mémoire du navigateur et créés après l'enregistrement de l'ancêtre
            // (collection-tampon.js), ce qui lève le besoin d'un id à la saisie.
            //
            // UNE SEULE configuration s'y oppose, et elle se lit dans le config lui-même
            // plutôt que dans une liste à maintenir : `defaultValueConfig`, où le SERVEUR
            // pré-remplit l'enfant à partir du parent (le montant d'un paiement déduit de
            // l'offre). Différer priverait la saisie de ses défauts, en silence.
            //
            // Le rattachement (`pickerUrl`), lui, se diffère très bien : on ne retient qu'un
            // id, et on l'attache une fois le parent né.
            $isDisabled = $config['disabled'] ?? ($isParentNew && isset($config['defaultValueConfig']));

            // Une collection désactivée en création est inutilisable (pas d'ID parent, et
            // rien à mettre en attente). Plutôt que de l'afficher grisée, on la masque
            // (d-none) : la ligne reste rendue — ce qui empêche form_end(render_rest) de
            // produire un accordéon brut en bas du formulaire — mais elle n'est pas visible.
            // Une collection peut REFUSER ce masquage. Quand sa presence a l'ecran est
            // elle-meme conditionnee par un autre champ (`visibility_conditions`),
            // la cacher en creation la rendrait invisible EN TOUTES CIRCONSTANCES :
            // celui qui vient de cocher le critere cense la faire paraitre n'aurait
            // alors rien pour comprendre pourquoi rien ne se passe.
            $isHidden = $config['hidden'] ?? ($isParentNew && $isDisabled);

            $layout[] = [
                "couleur_fond" => "white",
                "hidden" => $isHidden,
                // CETTE RANGEE EST UN ONGLET, et voici son intitule. Le gabarit du
                // dialogue partitionne le layout sur cette seule cle : les rangees sans
                // `onglet_titre` forment l'onglet « Principal », celles qui en portent un
                // deviennent un onglet chacune.
                //
                // `formTitle` est deja declare par CHAQUE collection : aucun provider n'est
                // a reprendre pour que les onglets existent. `ongletTitre` ne sert qu'a
                // corriger le nombre la ou le titre du formulaire est au singulier
                // (« Cotation » nomme le formulaire d'ajout, « Cotations » nomme l'onglet).
                "onglet_titre" => $config['ongletTitre'] ?? $config['formTitle'],
                "colonnes" => [
                    ["champs" => [$this->getCollectionWidgetConfig(
                        $config['fieldName'],
                        $config['entityRouteName'],
                        $parentId,
                        $config['formTitle'],
                        $config['parentFieldName'],
                        $config['defaultValueConfig'] ?? null,
                        $isDisabled,
                        $extraOptions,
                        $idEntreprise,
                        $idInvite
                    )]], // Correction: Ajout des IDs
                ]
            ];

            // Conditions de visibilite portees par la COLONNE : c'est le niveau que
            // le moteur generique du dialogue sait traiter. Il masque la colonne, puis
            // la rangee des que toutes ses colonnes le sont (dialog-instance_controller).
            // Les poser sur la RANGEE serait inoperant : la seconde passe la
            // reafficherait aussitot, ses colonnes etant restees visibles.
            if (isset($config['visibility_conditions'])) {
                $dernier = array_key_last($layout);
                $layout[$dernier]['colonnes'][0]['champs'][0]['visibility_conditions'] = $config['visibility_conditions'];
            }
        }
    }

    private function getCollectionWidgetConfig(string $fieldName, string $entityRouteName, int $parentId, string $formtitle, string $parentFieldName, ?array $defaultValueConfig = null, bool $isDisabled = false, array $extraOptions = [], ?int $idEntreprise = null, ?int $idInvite = null): array
    {
        // Le nom de route du parent vaut son nom de champ, sauf mention contraire
        // (cf. parentRouteName ci-dessus). Retiré des options : c'est une donnée de
        // construction d'URL, le navigateur n'en a aucun usage.
        $routeParent = strtolower((string) ($extraOptions['parentRouteName'] ?? $parentFieldName));
        unset($extraOptions['parentRouteName']);

        $config = [
            "field_code" => $fieldName,
            "widget" => "collection",
            "options" => [
                "listUrl"       => "/admin/" . $routeParent . "/api/" . $parentId . "/" . $fieldName,
                "itemFormUrl"   => "/admin/" . $entityRouteName . "/api/get-form",
                "itemSubmitUrl" => "/admin/" . $entityRouteName . "/api/submit",
                "itemDeleteUrl" => "/admin/" . $entityRouteName . "/api/delete",
                "itemTitleCreate" => "Ajouter : " . $formtitle,
                "itemTitleEdit" => "Modifier : " . $formtitle . " #%id%",
                "parentEntityId" => $parentId,
                "parentFieldName" => $parentFieldName,
                "disabled" => $isDisabled,
                "url" => "/admin/" . $routeParent . "/api/" . $parentId . "/" . $fieldName,
                "idEntreprise" => $idEntreprise,
                "idInvite" => $idInvite,
            ]
        ];

        if ($defaultValueConfig) {
            $config['options']['defaultValueConfig'] = json_encode($defaultValueConfig);
        }

        // Merge extra options (like totalizable info)
        if (!empty($extraOptions)) {
            $config['options'] = array_merge($config['options'], $extraOptions);
        }

        return $config;
    }

    private function buildFieldsMap(array $formLayout): array
    {
        $fieldsMap = [];
        if (empty($formLayout)) {
            return $fieldsMap;
        }

        foreach ($formLayout as $row) {
            if (!isset($row['colonnes']) || !is_array($row['colonnes'])) continue;

            foreach ($row['colonnes'] as $col) {
                $fields = $col['champs'] ?? (is_array($col) ? [$col] : []);

                foreach ($fields as $field) {
                    if (is_array($field) && isset($field['field_code'])) {
                        $fieldsMap[$field['field_code']] = $field;
                    }
                }
            }
        }
        return $fieldsMap;
    }
}