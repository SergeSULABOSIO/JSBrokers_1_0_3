<?php

namespace App\Services\Canvas\Provider\Entity;

use App\Entity\Avenant;
use App\Entity\CompteBancaire;
use App\Entity\Document;
use App\Entity\Invite;
use App\Entity\Partenaire;
use App\Entity\ReversementRetroAgent;
use App\Entity\Tranche;
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
                // Le libellé de la FICHE suit celui de la rubrique : elle porte les deux
                // familles, et « Rétro agent » aurait fait douter de la place d'un partenaire.
                "description" => "Rétro intermédiaire",
                "icone" => "depense",
                'background_image' => '/images/fitures/default.jpg',
                'description_template' => [
                    "Versé le [[paidAt|date('d/m/Y')]] ([[*reference]]).",
                    " Montant : [[montant]] [[currency_symbol]].",
                    " Sortie de fonds réelle — comptabilisée selon le bénéficiaire : charges de personnel (6611) pour un agent, rétrocommissions (632) pour un partenaire.",
                ],
            ],
            "liste" => [
                ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                ["code" => "reference", "intitule" => "Référence", "type" => "Texte"],
                ["code" => "montant", "intitule" => "Montant versé", "type" => "Nombre", "format" => "Monetaire", "unite" => $this->serviceMonnaies->getCodeMonnaieAffichage()],
                ["code" => "paidAt", "intitule" => "Date du versement", "type" => "Date"],
                // LES DEUX FAMILLES DE BÉNÉFICIAIRE, en XOR : une seule des deux colonnes est
                // renseignée par enregistrement. Les DEUX doivent figurer ici — ce canevas ne
                // sert pas que la fiche, il fournit aussi les champs de la recherche avancée :
                // omettre `partenaire`, c'est retirer un FILTRE, pas seulement une ligne.
                ["code" => "agent", "intitule" => "Agent bénéficiaire", "type" => "Relation", "targetEntity" => Invite::class, "displayField" => "nom"],
                ["code" => "partenaire", "intitule" => "Partenaire bénéficiaire", "type" => "Relation", "targetEntity" => Partenaire::class, "displayField" => "nom"],
                // L'ÉCHÉANCE dit QUAND le versement se rattache, l'affaire dit SUR QUOI : la
                // rémunération suit le rythme des paiements de prime et de commission.
                ["code" => "tranche", "intitule" => "Échéance réglée", "type" => "Relation", "targetEntity" => Tranche::class, "displayField" => "nom"],
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
