<?php

namespace App\Services\Canvas\Provider\Entity;

use App\Services\Search\ProductionScope;

/**
 * LE CANEVAS D'ENTITÉ DE LA RUBRIQUE « PRODUCTION INTERMÉDIAIRES ».
 *
 * ⚠ IL N'Y A PAS D'ENTITÉ. Les lignes de cette rubrique sont calculées par le moteur de
 * partage — une affaire y figure parce qu'un agent ou un partenaire y a une part, ce
 * qu'aucune colonne de base ne dit. Le canevas ne sert donc pas à décrire un enregistrement
 * mais à donner à la coquille ce dont elle a besoin : un titre, une icône, et une liste de
 * champs VIDE.
 *
 * Cette liste vide est délibérée, et c'est elle qui referme la recherche avancée : proposer
 * des critères que rien n'appliquerait aurait rendu une liste inchangée sans dire pourquoi.
 * Les trois chips, eux, filtrent réellement.
 */
class ProductionIntermediaireEntityCanvasProvider implements EntityCanvasProviderInterface
{
    public function supports(string $entityClassName): bool
    {
        return $entityClassName === ProductionScope::ENTITE;
    }

    public function getCanvas(): array
    {
        return [
            'parametres' => [
                'description' => 'Production des intermédiaires',
                'icone' => 'invite',
                'background_image' => '/images/fitures/default.jpg',
            ],
            // Aucun champ : voir l'entête. La recherche avancée n'a rien à proposer sur une
            // rubrique dont les lignes ne sont pas des enregistrements.
            'liste' => [],
        ];
    }
}
