<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\JourFerie;

class JourFerieFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === JourFerie::class;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        /** @var JourFerie $object */
        $isParentNew = ($object->getId() === null);

        $parametres = [
            "titre_creation" => "Nouveau jour férié",
            "titre_modification" => "Modification du jour férié #%id%",
            "endpoint_submit_url" => "/admin/jourferie/api/submit",
            "endpoint_delete_url" => "/admin/jourferie/api/delete",
            "endpoint_form_url" => "/admin/jourferie/api/get-form",
            "isCreationMode" => $isParentNew,
            // Colonne gauche du dialogue EN CRÉATION : ce que ce paramètre apporte
            // au cabinet. Le premier paragraphe sert aussi de « pourquoi » sur la
            // carte du guide de démarrage — un seul texte, deux surfaces.
            "description_creation" => [
                "Les jours fériés que vous déclarez ici ne sont pas décomptés des congés de vos collaborateurs.",
                "Sans eux, une semaine de congé posée sur un pont est facturée en entier au compteur du collaborateur — une erreur qu'il remarquera avant vous.",
                "Déclarez le calendrier officiel de votre pays, puis complétez-le chaque année.",
            ],
            "form_intro" => [
                "titre" => "Jour férié",
                "description" => "Un jour férié tombant dans une demande n'est pas décompté du solde. Aucun calendrier n'est fourni d'office : les fériés dépendent du pays de votre cabinet, et les dates mobiles changent chaque année. Saisissez les vôtres — un calendrier vide ne fausse rien, il ne retire simplement que les week-ends.",
            ],
            // L'exercice n'est PAS dans le formulaire : il découle de la date.
            "field_icons" => [
                "date"    => "action:calendar",
                "libelle" => "jour-ferie",
            ],
        ];

        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["date"], 'width' => 5], ["champs" => ["libelle"], 'width' => 7]]],
        ];

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout),
        ];
    }
}
