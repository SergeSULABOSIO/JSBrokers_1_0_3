<?php

namespace App\Form;

use App\Entity\Article;
use App\Services\FormListenerFactory;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\UX\Autocomplete\Form\AsEntityAutocompleteField;
use Symfony\UX\Autocomplete\Form\BaseEntityAutocompleteType;

#[AsEntityAutocompleteField]
class ArticleAutocompleteField extends AbstractType
{
    public function __construct(
        private FormListenerFactory $ecouteurFormulaire,
    ) {}
    
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'class' => Article::class,
            'placeholder' => 'Sélectionner un article',
            // ATTENTION: Commenté car l'entité Article n'a pas de relation "Entreprise" directe par défaut
            // 'query_builder' => $this->ecouteurFormulaire->setFiltreEntreprise(), 
            'searchable_fields' => ['nom'],
            'as_html' => true,
            'choice_label' => function(Article $article) {
                $montant = $article->getMontant() !== null ? number_format($article->getMontant(), 2, ',', ' ') : 'Montant non défini';
                
                return sprintf(
                    '<div><strong>%s</strong><div style="color: #6c757d; font-size: 0.85em; padding-left: 2px; margin-top: 2px;">Montant : %s</div></div>',
                    htmlspecialchars($article->getNom() ?? 'Sans nom'),
                    htmlspecialchars($montant)
                );
            },
        ]);
    }

    public function getParent(): string
    {
        return BaseEntityAutocompleteType::class;
    }
}