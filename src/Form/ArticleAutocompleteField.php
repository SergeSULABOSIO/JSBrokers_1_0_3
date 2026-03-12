<?php

namespace App\Form;

use App\Entity\Taxe;
use App\Services\FormListenerFactory;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\UX\Autocomplete\Form\AsEntityAutocompleteField;
use Symfony\UX\Autocomplete\Form\BaseEntityAutocompleteType;

#[AsEntityAutocompleteField]
class TaxeAutocompleteField extends AbstractType
{
    public function __construct(
        private FormListenerFactory $ecouteurFormulaire,
    ) {}
    
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'class' => Taxe::class,
            'placeholder' => 'Rechercher une taxe',
            'query_builder' => $this->ecouteurFormulaire->setFiltreEntreprise(),
            'searchable_fields' => ['code', 'nom'],
            'as_html' => true,
            'choice_label' => function(Taxe $taxe) {
                // Utilise le code si disponible (vu qu'il était utilisé dans l'ancien ArticleType)
                $affichage = $taxe->getCode() ?? 'Taxe';
                return sprintf(
                    '<div><strong>%s</strong><div style="color: #6c757d; font-size: 0.85em; padding-left: 2px; margin-top: 2px;">Compte de taxe</div></div>',
                    htmlspecialchars($affichage)
                );
            },
        ]);
    }

    public function getParent(): string
    {
        return BaseEntityAutocompleteType::class;
    }
}