<?php

namespace App\Services\Canvas\Provider\Entity;

use App\Entity\Article;
use App\Entity\Note;
use App\Entity\Tranche;
use App\Services\ServiceMonnaies;

class ArticleEntityCanvasProvider implements EntityCanvasProviderInterface
{
    public function __construct(
        private ServiceMonnaies $serviceMonnaies
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Article::class;
    }

    public function getCanvas(): array
    {
        return [
            "parametres" => [
                "description" => "Ligne de facturation (Article) appartenant à une Note.",
                "icone" => "default", // Icône générique
                'background_image' => '/images/fitures/default.jpg',
                'description_template' => [
                    " Nature: [[natureArticle]] ([[elementLie]]).",
                    " Montant: [[montantArticle]] ([[pourcentageNote]]% de la note)."
                ]
            ],
            "liste" => array_merge([
                ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                ["code" => "note", "intitule" => "Note Parente", "type" => "Relation", "targetEntity" => Note::class, "displayField" => "reference"],
                ["code" => "tranche", "intitule" => "Tranche (Prime)", "type" => "Relation", "targetEntity" => Tranche::class, "displayField" => "nom"],
            ], $this->getSpecificIndicators())
        ];
    }

    private function getSpecificIndicators(): array
    {
        $monnaie = $this->serviceMonnaies->getCodeMonnaieAffichage();
        return [
            ["group" => "Détails", "code" => "description", "intitule" => "Description contextuelle", "type" => "Calcul", "format" => "Texte", "description" => "Description dynamique de l'article basée sur le contexte de la note."],
            ["group" => "Détails", "code" => "natureArticle", "intitule" => "Nature de l'article", "type" => "Calcul", "format" => "Texte", "description" => "Indique la nature comptable de la ligne (Taxe, Commission, Prime, ou Libre)."],
            ["group" => "Détails", "code" => "elementLie", "intitule" => "Élément lié", "type" => "Calcul", "format" => "Texte", "description" => "Affiche le nom de l'entité source (Taxe, Tranche, Revenu) liée à cet article."],
            ["group" => "Finances", "code" => "montantArticle", "intitule" => "Montant Payable", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Le montant de cette ligne de facturation."],
            ["group" => "Finances", "code" => "valeurUnitaire", "intitule" => "Valeur Unitaire", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "La valeur unitaire de l'article (Montant / Quantité)."],
            ["group" => "Finances", "code" => "pourcentageNote", "intitule" => "Poids dans la note", "type" => "Calcul", "format" => "Pourcentage", "description" => "Le pourcentage que représente cet article par rapport au total de la note parente."],
            ["group" => "Statut", "code" => "statutNoteParent", "intitule" => "Statut Note", "type" => "Calcul", "format" => "Texte", "description" => "Indique si la note à laquelle appartient cet article est validée ou en brouillon."],
        ];
    }
}