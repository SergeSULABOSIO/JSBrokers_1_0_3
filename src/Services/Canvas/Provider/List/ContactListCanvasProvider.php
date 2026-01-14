<?php

namespace App\Services\Canvas\Provider\List;

use App\Entity\Contact;

class ContactListCanvasProvider implements ListCanvasProviderInterface
{
    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Contact::class;
    }

    public function getCanvas(): array
    {
        return [
            "colonne_principale" => [
                "titre_colonne" => "Contacts",
                "texte_principal" => ["attribut_code" => "nom", "icone" => "mdi:account-box"],
                "textes_secondaires" => [
                    ["attribut_code" => "fonction"],
                    ["attribut_code" => "email"]
                ],
            ],
        ];
    }
}