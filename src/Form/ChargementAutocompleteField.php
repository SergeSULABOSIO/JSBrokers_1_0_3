<?php

namespace App\Form;

use App\Entity\Chargement;
use App\Services\FormListenerFactory;
use App\Services\Canvas\Indicator\IndicatorCalculationHelper;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\UX\Autocomplete\Form\AsEntityAutocompleteField;
use Symfony\UX\Autocomplete\Form\BaseEntityAutocompleteType;
use App\Services\Canvas\Autocomplete\RenduOptionAutocomplete;

#[AsEntityAutocompleteField]
class ChargementAutocompleteField extends AbstractType
{
    public function __construct(
        private FormListenerFactory $ecouteurFormulaire,
        private IndicatorCalculationHelper $calculationHelper, // <-- Injection du BON service !
        private RenduOptionAutocomplete $rendu,
    ) {
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'class' => Chargement::class,
            'placeholder' => 'Sélectionner un type de chargement',
            'query_builder' => $this->ecouteurFormulaire->setFiltreEntreprise(),
            'searchable_fields' => ['nom'],
            'choice_label' => fn (Chargement $chargement) => $this->rendu->libelle(
                titre: $chargement->getNom(),
                contact: [$this->calculationHelper->Chargement_getFonctionString($chargement)],
            ),
        ]);
    }

    public function getParent(): string
    {
        return BaseEntityAutocompleteType::class;
    }
}