<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\ConditionPartage;
use App\Form\ConditionPartageType;
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
            // Le motif accompagne le drapeau : l'infobulle du bouton grisé doit dire OÙ
            // l'on crée, sans quoi il ne dit que « non ».
            "creation_interdite_message" => "Une condition de partage se crée depuis la fiche d'un partenaire, d'un invité ou d'une piste.",
            // Entête contextuel du volet de saisie (pastille + description).
            "form_intro" => [
                "titre" => "Condition de partage",
                "description" => "Vous paramétrez une règle de partage des revenus : taux, seuil, unité de mesure, formule d'application et risques concernés. Le bénéficiaire est soit un partenaire EXTERNE (sa part se calcule sur la commission partageable), soit un AGENT INTERNE du cabinet (sa part se calcule sur ce qui reste après les partenaires — c'est la dernière retirée). L'un ou l'autre, jamais les deux.",
            ],
            // Mini-pastille par carte de champ : icône illustrant le champ (alias IconCanvasProvider).
            "field_icons" => [
                "documents" => "document",
                "nom"           => "action:edit",
                "beneficiaireType" => "action:options",
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
            // LE CHOIX DU BÉNÉFICIAIRE, puis le sélecteur qu'il commande.
            //
            // Sur une condition qui appartient à une AFFAIRE, la question se pose : la part
            // revient-elle à l'intermédiaire de l'affaire, ou à un agent du cabinet ? Le
            // FormType ne déclare le champ que dans ce cas — d'où la garde ci-dessous, qui
            // évite d'annoncer au canevas un champ que le formulaire ne rendra pas.
            $rangees = [];

            if ($object->getPiste() !== null) {
                $rangees[] = [
                    "couleur_fond" => "white",
                    "colonnes" => [["champs" => ["beneficiaireType"]]],
                ];
            }

            // Le sélecteur d'agent ne paraît que sur le second choix — par le moteur
            // DÉCLARATIF déjà en place, jamais par un contrôleur dédié qui ferait doublon.
            // Hors affaire (fiche d'un agent), aucune condition : il est toujours visible.
            $champAgent = ['field_code' => 'agent'];
            if ($object->getPiste() !== null) {
                $champAgent['visibility_conditions'] = [[
                    'field' => 'beneficiaireType',
                    'operator' => 'in',
                    'value' => [ConditionPartageType::BENEFICIAIRE_AGENT],
                ]];
            }
            $rangees[] = ["couleur_fond" => "white", "colonnes" => [["champs" => [$champAgent]]]];

            array_splice($layout, 1, 0, $rangees);
        }

        // LES RISQUES CIBLES NE PARAISSENT QUE S'ILS ONT UN OBJET.
        //
        // Le critere sur le risque a trois valeurs : ne cibler AUCUN risque, ne partager
        // QUE sur certains, ou ne PAS partager sur certains. Dans le premier cas la liste
        // n'a rien a designer — la laisser a l'ecran demande a l'utilisateur de comprendre
        // seul qu'elle ne sert a rien ici (Bastien & Scapin > Charge de travail).
        //
        // La regle est DECLAREE, pas codee : le dialogue possede deja un moteur de
        // visibilite conditionnelle (dialog-instance_controller), qui ecoute le champ
        // source et masque la colonne puis la rangee. Un controleur Stimulus dedie ferait
        // doublon, et divergerait au premier changement du moteur.
        //
        // `hidden => false` est indispensable : sans lui, une collection est masquee
        // d'office en creation (pas encore d'id parent), et le bloc resterait donc
        // invisible QUOI QU'ON COCHE — exactement ce que l'utilisateur constatait. En
        // creation le widget reste desactive : on voit le bloc, on comprend qu'il se
        // remplira apres l'enregistrement.
        $collections = [[
            'fieldName' => 'produits',
            'entityRouteName' => 'risque',
            'formTitle' => 'Risque',
            'parentFieldName' => 'conditionPartage',
            'hidden' => false,
            // ON CHOISIT AU CATALOGUE, ON NE FABRIQUE PAS. Un risque (« Incendie », « RC
            // automobile ») appartient au catalogue de l'entreprise, pas à une condition :
            // le créer d'ici produirait un doublon par condition. Depuis le passage en
            // ManyToMany, plusieurs conditions peuvent viser le même risque, ce qui rend
            // ce rattachement sûr — il ne le retire à personne.
            'pickerUrl' => '/admin/conditionpartage/api/%parentId%/risque-picker',
            'hideEditAction' => true,
            // « Retirer », jamais « Supprimer » : on sort le risque des cibles, on ne le
            // raye pas du catalogue où il sert ailleurs.
            'deleteActionLabel' => 'Retirer',
            'itemDeleteUrl' => '/admin/conditionpartage/api/%parentId%/detach-risque',
            'visibility_conditions' => [[
                'field' => 'critereRisque',
                'operator' => 'in',
                'value' => [
                    ConditionPartage::CRITERE_EXCLURE_TOUS_CES_RISQUES,
                    ConditionPartage::CRITERE_INCLURE_TOUS_CES_RISQUES,
                ],
            ]],
        ]];
        // Pièces jointes de cette fiche.
        $collections[] = ['fieldName' => 'documents', 'entityRouteName' => 'document', 'formTitle' => 'Document', 'parentFieldName' => 'conditionPartage'];
        $this->addCollectionWidgetsToLayout($layout, $object, $isParentNew, $collections);
        return $layout;
    }
}
