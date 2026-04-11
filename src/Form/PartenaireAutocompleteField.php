<?php

namespace App\Form;

use App\Entity\Partenaire;
use App\Services\Canvas\Autocomplete\PartenaireAutocompleteCanvasProvider;
use App\Services\FormListenerFactory;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\UX\Autocomplete\Form\AsEntityAutocompleteField;
use Symfony\UX\Autocomplete\Form\BaseEntityAutocompleteType;

#[AsEntityAutocompleteField]
class PartenaireAutocompleteField extends AbstractType
{

    public function __construct(
        private FormListenerFactory $ecouteurFormulaire,
        private PartenaireAutocompleteCanvasProvider $canvasProvider
    ) {}
    
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'class' => Partenaire::class,
            'placeholder' => 'Sélectionner le partenaire',
            'query_builder' => $this->ecouteurFormulaire->setFiltreEntreprise(),
            'searchable_fields' => ['nom', 'email'],
            'as_html' => true,
            // La logique de rendu est maintenant déléguée au service dédié.
            'choice_label' => fn(Partenaire $partenaire) => $this->canvasProvider->getChoiceLabel($partenaire),
        ]);
    }

    public function getParent(): string
    {
        return BaseEntityAutocompleteType::class;
    }
}
