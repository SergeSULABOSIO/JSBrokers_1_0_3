<?php

namespace App\Services\Canvas\Provider\Entity;

use App\Entity\Article;
use App\Entity\Note;
use App\Entity\RevenuPourCourtier;
use App\Entity\Taxe;
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
                    "Article: [[nom]].",
                    " Nature: [[natureArticle]] ([[elementLie]]).",
                    " Montant: [[montantArticle]] ([[pourcentageNote]]% de la note)."
                ]
            ],
            "liste" => array_merge([
                ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                ["code" => "nom", "intitule" => "Description / Nom", "type" => "Texte"],
                ["code" => "montant", "intitule" => "Montant Brut", "type" => "Nombre"],
                ["code" => "note", "intitule" => "Note Parente", "type" => "Relation", "targetEntity" => Note::class, "displayField" => "reference"],
                ["code" => "tranche", "intitule" => "Tranche (Prime)", "type" => "Relation", "targetEntity" => Tranche::class, "displayField" => "nom"],
                ["code" => "revenuFacture", "intitule" => "Revenu / Commission", "type" => "Relation", "targetEntity" => RevenuPourCourtier::class, "displayField" => "nom"],
                ["code" => "taxeFacturee", "intitule" => "Taxe", "type" => "Relation", "targetEntity" => Taxe::class, "displayField" => "nom"],
            ], $this->getSpecificIndicators())
        ];
    }

    private function getSpecificIndicators(): array
    {
        $monnaie = $this->serviceMonnaies->getCodeMonnaieAffichage();
        return [
            ["group" => "Détails", "code" => "natureArticle", "intitule" => "Nature de l'article", "type" => "Calcul", "format" => "Texte", "description" => "Indique la nature comptable de la ligne (Taxe, Commission, Prime, ou Libre)."],
            ["group" => "Détails", "code" => "elementLie", "intitule" => "Élément lié", "type" => "Calcul", "format" => "Texte", "description" => "Affiche le nom de l'entité source (Taxe, Tranche, Revenu) liée à cet article."],
            ["group" => "Finances", "code" => "montantArticle", "intitule" => "Montant de l'article", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Le montant de cette ligne de facturation."],
            ["group" => "Finances", "code" => "pourcentageNote", "intitule" => "Poids dans la note", "type" => "Calcul", "format" => "Pourcentage", "description" => "Le pourcentage que représente cet article par rapport au total de la note parente."],
            ["group" => "Statut", "code" => "statutNoteParent", "intitule" => "Statut Note", "type" => "Calcul", "format" => "Texte", "description" => "Indique si la note à laquelle appartient cet article est validée ou en brouillon."],
        ];
    }
}