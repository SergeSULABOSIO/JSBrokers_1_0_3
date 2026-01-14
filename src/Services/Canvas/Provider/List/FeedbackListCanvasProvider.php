<?php

namespace App\Services\Canvas\Provider\List;

use App\Entity\Feedback;

class FeedbackListCanvasProvider implements ListCanvasProviderInterface
{
    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Feedback::class;
    }

    public function getCanvas(): array
    {
        return [
            "colonne_principale" => [
                "titre_colonne" => "Feedbacks",
                "texte_principal" => ["attribut_code" => "description", "icone" => "mdi:message-reply-text", "attribut_taille_max" => 50],
                "textes_secondaires" => [
                    ["attribut_prefixe" => "Créé le: ", "attribut_code" => "createdAt", "attribut_type" => "date"],
                ],
            ],
        ];
    }
}