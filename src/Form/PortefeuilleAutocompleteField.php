<?php

namespace App\Form;

use App\Entity\Portefeuille;
use App\Services\FormListenerFactory;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\UX\Autocomplete\Form\AsEntityAutocompleteField;
use Symfony\UX\Autocomplete\Form\BaseEntityAutocompleteType;
use App\Services\Canvas\Autocomplete\RenduOptionAutocomplete;

#[AsEntityAutocompleteField]
class PortefeuilleAutocompleteField extends AbstractType
{
    public function __construct(
        private FormListenerFactory $ecouteurFormulaire,
        private RenduOptionAutocomplete $rendu,
    ) {}

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'class' => Portefeuille::class,
            'placeholder' => 'Sélectionner un portefeuille',
            'query_builder' => $this->ecouteurFormulaire->setFiltreEntreprise(),
            'searchable_fields' => ['nom'],
            'choice_label' => fn (Portefeuille $portefeuille) => $this->rendu->libelle(
                titre: $portefeuille->getNom(),
                contact: [$portefeuille->getGestionnaire()?->getNom()],
            ),
        ]);
    }

    public function getParent(): string
    {
        return BaseEntityAutocompleteType::class;
    }
}
