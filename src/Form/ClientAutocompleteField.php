<?php

namespace App\Form;

use App\Entity\Client;
use App\Services\Canvas\Autocomplete\ClientAutocompleteCanvasProvider;
use App\Services\FormListenerFactory;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\UX\Autocomplete\Form\AsEntityAutocompleteField;
use Symfony\UX\Autocomplete\Form\BaseEntityAutocompleteType;

#[AsEntityAutocompleteField]
class ClientAutocompleteField extends AbstractType
{
    public function __construct(
        private FormListenerFactory $ecouteurFormulaire,
        private ClientAutocompleteCanvasProvider $canvasProvider
    ) {}
    
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'class' => Client::class,
            'placeholder' => 'Sélectionner le client',
            'query_builder' => $this->ecouteurFormulaire->setFiltreEntreprise(),
            'searchable_fields' => ['nom', 'email'],
            'as_html' => true,
            // La logique de rendu est maintenant déléguée au service dédié.
            'choice_label' => fn(Client $client) => $this->canvasProvider->getChoiceLabel($client),
        ]);
    }

    public function getParent(): string
    {
        return BaseEntityAutocompleteType::class;
    }
}
