<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\RegimeTravail;

class RegimeTravailFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === RegimeTravail::class;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        /** @var RegimeTravail $object */
        $isParentNew = ($object->getId() === null);

        $parametres = [
            "titre_creation" => "Nouveau régime de travail",
            "titre_modification" => "Modification du régime #%id%",
            "endpoint_submit_url" => "/admin/regimetravail/api/submit",
            "endpoint_delete_url" => "/admin/regimetravail/api/delete",
            "endpoint_form_url" => "/admin/regimetravail/api/get-form",
            "isCreationMode" => $isParentNew,
            // Colonne gauche du dialogue EN CRÉATION : ce que ce paramètre apporte
            // au cabinet. Le premier paragraphe sert aussi de « pourquoi » sur la
            // carte du guide de démarrage — un seul texte, deux surfaces.
            "description_creation" => [
                "Un régime de travail décrit les jours ouvrés et le temps de travail d'un collaborateur.",
                "C'est lui qui dit combien de jours consomme réellement une absence : un temps partiel et un temps plein ne décomptent pas de la même façon.",
                "Créez-en un par organisation en vigueur dans le cabinet, puis rattachez-le à chaque collaborateur depuis sa fiche.",
            ],
            "form_intro" => [
                "titre" => "Temps de travail du collaborateur",
                "description" => "Les jours cochés sont ceux que le collaborateur travaille : un congé ne lui décomptera que ceux-là. Un régime change ? Ajoutez-en un NOUVEAU en datant sa prise d'effet, plutôt que de corriger l'ancien : une demande posée l'an dernier doit rester lisible avec le régime qui était alors le sien.",
            ],
            "field_icons" => [
                "joursOuvres"    => "action:calendar",
                "tauxOccupation" => "action:count",
                "dateDebut"      => "action:calendar",
                "dateFin"        => "action:calendar",
            ],
        ];

        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["joursOuvres"], 'width' => 12]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["tauxOccupation"], 'width' => 4], ["champs" => ["dateDebut"], 'width' => 4], ["champs" => ["dateFin"], 'width' => 4]]],
        ];

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout),
        ];
    }
}
