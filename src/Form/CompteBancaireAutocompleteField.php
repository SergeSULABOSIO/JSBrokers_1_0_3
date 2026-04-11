<?php

namespace App\Form;

use App\Entity\CompteBancaire;
use App\Services\Canvas\Autocomplete\CompteBancaireAutocompleteCanvasProvider;
use App\Services\FormListenerFactory;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\UX\Autocomplete\Form\AsEntityAutocompleteField;
use Symfony\UX\Autocomplete\Form\BaseEntityAutocompleteType;

#[AsEntityAutocompleteField]
class CompteBancaireAutocompleteField extends AbstractType
{
    public function __construct(
        private FormListenerFactory $ecouteurFormulaire,
        private CompteBancaireAutocompleteCanvasProvider $canvasProvider
    ) {}
    
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'class' => CompteBancaire::class,
            'placeholder' => "Séléctionnez le compte",
            'query_builder' => $this->ecouteurFormulaire->setFiltreEntreprise(),
            'searchable_fields' => ['nom', 'numero', 'banque'],
            'as_html' => true,
            // La logique de rendu est maintenant déléguée au service dédié.
            'choice_label' => fn(CompteBancaire $compte) => $this->canvasProvider->getChoiceLabel($compte),
        ]);
    }

    public function getParent(): string
    {
        return BaseEntityAutocompleteType::class;
    }
}
