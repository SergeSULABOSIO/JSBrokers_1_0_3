<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\ConditionPartage;
use App\Services\CanvasBuilder;
use Doctrine\ORM\EntityManagerInterface;

class ConditionPartageFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function __construct(
        private CanvasBuilder $canvasBuilder,
        private EntityManagerInterface $em
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === ConditionPartage::class;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        /** @var ConditionPartage $object */
        $isParentNew = ($object->getId() === null);
        $conditionId = $object->getId() ?? 0;

        $parametres = [
            "titre_creation" => "Nouvelle Condition de Partage",
            "titre_modification" => "Modification de la Condition #%id%",
            "endpoint_submit_url" => "/admin/conditionpartage/api/submit",
            "endpoint_delete_url" => "/admin/conditionpartage/api/delete",
            "endpoint_form_url" => "/admin/conditionpartage/api/get-form",
            "isCreationMode" => $isParentNew,
            // Les « risques ciblés » n'ont de sens que pour deux des trois critères : ce
            // contrôleur les montre ou les cache à la volée, plutôt que d'exposer en
            // permanence un champ sans objet (Bastien & Scapin > Charge de travail).
            "form_controller" => "condition-partage-fields",
            // AUCUNE CRÉATION DEPUIS LA RUBRIQUE. Une condition de partage n'existe que
            // rattachée à quelque chose : un partenaire, un agent, ou une piste. La créer
            // depuis la liste produirait une règle orpheline — sans bénéficiaire, donc
            // invalide, ou avec un bénéficiaire mais rattachée à aucune affaire, donc sans
            // effet et sans rien pour le signaler.
            //
            // On crée donc EXCLUSIVEMENT depuis la fiche Partenaire, la fiche Invité ou la
            // Piste, où le rattachement est posé par construction. La rubrique reste la
            // vue d'ensemble : consulter, éditer, supprimer, auditer les taux en vigueur.
            //
            // Le drapeau masque « Ajouter » dans la barre d'outils, le menu contextuel et
            // l'état vide. La sécurité, elle, ne dépend pas de l'écran : le POST reste
            // gouverné par le droit « Intermédiaires » comme toute autre écriture.
            "creation_interdite" => true,
            // Entête contextuel du volet de saisie (pastille + description).
            "form_intro" => [
                "titre" => "Condition de partage",
                "description" => "Vous paramétrez une règle de partage des revenus : taux, seuil, unité de mesure, formule d'application et risques concernés. Le bénéficiaire est soit un partenaire EXTERNE (sa part se calcule sur la commission partageable), soit un AGENT INTERNE du cabinet (sa part se calcule sur ce qui reste après les partenaires — c'est la dernière retirée). L'un ou l'autre, jamais les deux.",
            ],
            // Mini-pastille par carte de champ : icône illustrant le champ (alias IconCanvasProvider).
            "field_icons" => [
                "documents" => "document",
                "nom"           => "action:edit",
                "agent"         => "invite",
                "taux"          => "action:count",
                "seuil"         => "action:count",
                "uniteMesure"   => "action:options",
                "formule"       => "action:options",
                "critereRisque" => "risque",
                "produits"      => "risque",
            ],
        ];
        $layout = $this->buildConditionPartageLayout($object, $isParentNew);

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout)
        ];
    }

    private function buildConditionPartageLayout(ConditionPartage $object, bool $isParentNew): array
    {
        $conditionId = $object->getId() ?? 0;
        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["nom"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["taux"]], ["champs" => ["seuil"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["uniteMesure"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["formule"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["critereRisque"]]]],
        ];
        // Le bénéficiaire INTERNE, explicitement dans le layout — sans quoi le champ
        // tomberait en bas du formulaire via render_rest, là où personne ne le cherche.
        // Absent dès que la condition appartient à un PARTENAIRE : la question de l'agent
        // ne se pose pas, et le FormType ne déclare alors même pas le champ.
        if ($object->getPartenaire() === null) {
            array_splice($layout, 1, 0, [
                ["couleur_fond" => "white", "colonnes" => [["champs" => ["agent"]]]],
            ]);
        }

        $collections = [['fieldName' => 'produits', 'entityRouteName' => 'risque', 'formTitle' => 'Risque', 'parentFieldName' => 'conditionPartage']];
        // Pièces jointes de cette fiche.
        $collections[] = ['fieldName' => 'documents', 'entityRouteName' => 'document', 'formTitle' => 'Document', 'parentFieldName' => 'conditionPartage'];
        $this->addCollectionWidgetsToLayout($layout, $object, $isParentNew, $collections);
        return $layout;
    }
}
