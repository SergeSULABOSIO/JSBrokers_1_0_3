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
                "description" => "Vous paramétrez une règle de partage des revenus : taux, seuil, unité de mesure, formule d'application et risques concernés. Le bénéficiaire est soit un intermédiaire EXTERNE (sa part se calcule sur la commission partageable), soit un AGENT INTERNE du cabinet (sa part se calcule sur ce qui reste après les intermédiaires — c'est la dernière retirée). L'un ou l'autre, jamais les deux. ATTENTION à la portée : une condition d'intermédiaire s'applique à TOUTES les affaires qu'il apporte dès son enregistrement, tandis que celle d'un agent reste sans effet tant qu'on ne l'a pas rattachée à des affaires.",
            ],
            // Mini-pastille par carte de champ : icône illustrant le champ (alias IconCanvasProvider).
            "field_icons" => [
                "documents" => "document",
                "nom"           => "action:edit",
                "beneficiaireType" => "action:options",
                "agent"         => "invite",
                "partenaire"    => "partenaire",
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
        // Les champs de BÉNÉFICIAIRE, explicitement dans le layout — sans quoi ils
        // tomberaient en bas du formulaire via render_rest, là où personne ne les cherche.
        //
        // TROIS SITUATIONS, et le canevas doit annoncer exactement ce que le FormType
        // déclare : lui promettre un champ qu'il ne rendra pas laisse une carte vide.
        //
        //  1. depuis une AFFAIRE — le choix est « l'intermédiaire de cette affaire » ou un
        //     agent ; le partenaire n'est pas librement désignable (une condition propre à
        //     l'affaire qui nommerait un tiers paierait l'intermédiaire du jour à son taux) ;
        //  2. en création AUTONOME (rubrique) ou en correction — les deux familles se
        //     choisissent librement ; c'est ce qui manquait au partenaire ;
        //  3. depuis la fiche d'un bénéficiaire — il est injecté, aucune question à poser.
        $depuisUneAffaire = $object->getPiste() !== null && $object->getPartenaire() === null;
        $librementChoisi = $object->getPiste() === null
            && !($object->getId() === null
                && ($object->getAgent() !== null || $object->getPartenaire() !== null));

        if ($depuisUneAffaire || $librementChoisi) {
            $conditionne = static fn (string $famille): array => [[
                'field' => 'beneficiaireType',
                'operator' => 'in',
                'value' => [$famille],
            ]];

            // Le sélecteur ne paraît que sur le choix qu'il sert — par le moteur DÉCLARATIF
            // déjà en place, jamais par un contrôleur dédié qui ferait doublon.
            $rangees = [
                ["couleur_fond" => "white", "colonnes" => [["champs" => ["beneficiaireType"]]]],
                ["couleur_fond" => "white", "colonnes" => [["champs" => [[
                    'field_code' => 'agent',
                    'visibility_conditions' => $conditionne(ConditionPartageType::BENEFICIAIRE_AGENT),
                ]]]]],
            ];

            // Le partenaire n'est proposé qu'en création autonome : depuis une affaire, le
            // FormType ne déclare pas le champ, et l'annoncer ici afficherait une carte vide.
            if ($librementChoisi) {
                $rangees[] = ["couleur_fond" => "white", "colonnes" => [["champs" => [[
                    'field_code' => 'partenaire',
                    'visibility_conditions' => $conditionne(ConditionPartageType::BENEFICIAIRE_INTERMEDIAIRE),
                ]]]]];
            }

            array_splice($layout, 1, 0, $rangees);
        } elseif ($object->getPartenaire() === null) {
            // Fiche d'un AGENT : le bénéficiaire est injecté, on le montre sans le discuter.
            array_splice($layout, 1, 0, [
                ["couleur_fond" => "white", "colonnes" => [["champs" => ["agent"]]]],
            ]);
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
