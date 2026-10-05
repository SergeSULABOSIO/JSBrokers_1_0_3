<?php

namespace App\Form;

use App\Entity\Classeur;
use App\Services\FormListenerFactory;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\UX\Autocomplete\Form\AsEntityAutocompleteField;
use Symfony\UX\Autocomplete\Form\BaseEntityAutocompleteType;
use App\Services\Canvas\Autocomplete\RenduOptionAutocomplete;

#[AsEntityAutocompleteField]
class ClasseurAutocompleteField extends AbstractType
{
    public function __construct(
        private FormListenerFactory $ecouteurFormulaire,
        private RenduOptionAutocomplete $rendu,
    ) {}
    
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'class' => Classeur::class,
            'placeholder' => 'Sélectionner un classeur',
            'query_builder' => $this->ecouteurFormulaire->setFiltreEntreprise(),
            'searchable_fields' => ['nom'],
            'choice_label' => fn (Classeur $classeur) => $this->rendu->libelle(
                titre: $classeur->getNom(),
                contact: [
                    $classeur->getDocuments()->count() . ' document(s)',
                    $classeur->getDescription(),
                ],
            ),
        ]);
    }

    public function getParent(): string
    {
        return BaseEntityAutocompleteType::class;
    }
}
