<?php

namespace App\Services\Canvas\Provider\List;

use App\Entity\Invite;

class InviteListCanvasProvider implements ListCanvasProviderInterface
{
    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Invite::class;
    }

    public function getCanvas(): array
    {
        return [
            "colonne_principale" => [
                "titre_colonne" => "Invitations",
                "texte_principal" => ["attribut_code" => "email", "icone" => "invite"],
                "textes_secondaires_separateurs" => " • ",
                "textes_secondaires" => [
                    ["attribut_code" => "nom"],
                    ["attribut_prefixe" => "Statut: ", "attribut_code" => "status_string"],
                ],
            ],
        ];
    }
}