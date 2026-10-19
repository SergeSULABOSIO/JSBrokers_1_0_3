<?php

namespace App\Form;

use App\Entity\Groupe;
use App\Form\Autocomplete\RechercheAutocomplete;
use App\Services\FormListenerFactory;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\UX\Autocomplete\Form\AsEntityAutocompleteField;
use Symfony\UX\Autocomplete\Form\BaseEntityAutocompleteType;
use App\Services\Canvas\Autocomplete\RenduOptionAutocomplete;

#[AsEntityAutocompleteField]
class GroupeAutocompleteField extends AbstractType
{
    public function __construct(
        private FormListenerFactory $ecouteurFormulaire,
        private RenduOptionAutocomplete $rendu,
    ) {}

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'class' => Groupe::class,
            'placeholder' => 'Sélectionner un groupe',
            'query_builder' => $this->ecouteurFormulaire->setFiltreEntreprise(),
            // Notre LIKE, pas celui du bundle : jokers tapés échappés (RechercheAutocomplete).
            'filter_query' => RechercheAutocomplete::surLesChamps(['nom', 'description']),
            'choice_label' => fn (Groupe $groupe) => $this->rendu->libelle(
                titre: $groupe->getNom(),
                contact: [$groupe->getDescription()],
            ),
        ]);
    }

    public function getParent(): string
    {
        return BaseEntityAutocompleteType::class;
    }
}
