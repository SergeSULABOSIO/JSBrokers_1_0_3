<?php

namespace App\Form;

use App\Entity\Bordereau;
use App\Services\Canvas\Autocomplete\BordereauAutocompleteCanvasProvider;
use App\Services\FormListenerFactory;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\UX\Autocomplete\Form\AsEntityAutocompleteField;
use Symfony\UX\Autocomplete\Form\BaseEntityAutocompleteType;

#[AsEntityAutocompleteField]
class BordereauAutocompleteField extends AbstractType
{
    public function __construct(
        private FormListenerFactory $ecouteurFormulaire,
        private BordereauAutocompleteCanvasProvider $canvasProvider
    ) {}
    
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'class' => Bordereau::class,
            'placeholder' => 'Sélectionner un bordereau',
            'query_builder' => $this->ecouteurFormulaire->setFiltreEntreprise(),
            'searchable_fields' => ['nom', 'reference'],
            'as_html' => true,
            'choice_label' => fn(Bordereau $bordereau) => $this->canvasProvider->getChoiceLabel($bordereau),
        ]);
    }

    public function getParent(): string
    {
        return BaseEntityAutocompleteType::class;
    }
}