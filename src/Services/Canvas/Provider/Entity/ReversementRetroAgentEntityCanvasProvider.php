<?php

namespace App\Services\Canvas\Provider\Entity;

use App\Entity\Avenant;
use App\Entity\CompteBancaire;
use App\Entity\Document;
use App\Entity\Invite;
use App\Entity\ReversementRetroAgent;
use App\Services\ServiceMonnaies;

/**
 * LA FICHE D'UN REVERSEMENT — et, avec elle, sa collection « Documents ».
 *
 * C'est cette collection qui rend le justificatif consultable ligne à ligne, et qui fait
 * apparaître les deux actions documentaires de la barre d'outils : elles sont injectées par
 * FormCanvasProvider dès qu'une entité porte une relation depuis Document, ce qui n'avait
 * jamais servi ici faute d'écran.
 */
class ReversementRetroAgentEntityCanvasProvider implements EntityCanvasProviderInterface
{
    public function __construct(
        private ServiceMonnaies $serviceMonnaies
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === ReversementRetroAgent::class;
    }

    public function getCanvas(): array
    {
        return [
            "parametres" => [
                "description" => "Reversement de rétrocommission à un agent",
                "icone" => "depense",
                'background_image' => '/images/fitures/default.jpg',
                'description_template' => [
                    "Versé le [[paidAt|date('d/m/Y')]] ([[*reference]]).",
                    " Montant : [[montant]] [[currency_symbol]].",
                    " Sortie de fonds réelle — comptabilisée en charges de personnel (SYSCOHADA 6611).",
                ],
            ],
            "liste" => [
                ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                ["code" => "reference", "intitule" => "Référence", "type" => "Texte"],
                ["code" => "montant", "intitule" => "Montant versé", "type" => "Nombre", "format" => "Monetaire", "unite" => $this->serviceMonnaies->getCodeMonnaieAffichage()],
                ["code" => "paidAt", "intitule" => "Date du versement", "type" => "Date"],
                ["code" => "agent", "intitule" => "Bénéficiaire", "type" => "Relation", "targetEntity" => Invite::class, "displayField" => "nom"],
                ["code" => "avenant", "intitule" => "Police réglée", "type" => "Relation", "targetEntity" => Avenant::class, "displayField" => "referencePolice"],
                ["code" => "compteBancaire", "intitule" => "Compte débité", "type" => "Relation", "targetEntity" => CompteBancaire::class, "displayField" => "intitule"],
                // La référence de lot n'est pas décorative : c'est elle qui dit que ce
                // versement partage UN virement — et donc UNE pièce — avec d'autres lignes.
                ["code" => "lotReference", "intitule" => "Virement groupé", "type" => "Texte"],
                ["code" => "description", "intitule" => "Description", "type" => "Texte"],
                ["code" => "documents", "intitule" => "Justificatifs", "type" => "Collection", "targetEntity" => Document::class, "displayField" => "nom"],
            ],
        ];
    }
}
