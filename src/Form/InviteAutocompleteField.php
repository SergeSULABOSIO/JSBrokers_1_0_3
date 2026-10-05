<?php

namespace App\Form;

use App\Entity\Invite;
use App\Services\FormListenerFactory;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\UX\Autocomplete\Form\AsEntityAutocompleteField;
use Symfony\UX\Autocomplete\Form\BaseEntityAutocompleteType;
use App\Services\Canvas\Autocomplete\RenduOptionAutocomplete;

#[AsEntityAutocompleteField]
class InviteAutocompleteField extends AbstractType
{
    public function __construct(
        private FormListenerFactory $ecouteurFormulaire,
        private RenduOptionAutocomplete $rendu,
    ) {}
    
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'class' => Invite::class,
            'placeholder' => 'Ajouter un invité',
            'query_builder' => $this->ecouteurFormulaire->setFiltreEntreprise(),
            'searchable_fields' => ['nom'],
            'choice_label' => fn (Invite $invite) => $this->rendu->libelle(
                titre: $invite->getNom(),
                contact: [$invite->getUtilisateur()?->getEmail()],
            ),
        ]);
    }

    public function getParent(): string
    {
        return BaseEntityAutocompleteType::class;
    }
}
