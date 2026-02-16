<?php

namespace App\Form;

use App\Entity\TypeRevenu;
use App\Services\FormListenerFactory;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\UX\Autocomplete\Form\AsEntityAutocompleteField;
use Symfony\UX\Autocomplete\Form\BaseEntityAutocompleteType;

#[AsEntityAutocompleteField]
class TypeRevenuAutocompleteField extends AbstractType
{
    public function __construct(
        private FormListenerFactory $ecouteurFormulaire,
    ) {
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'class' => TypeRevenu::class,
            'placeholder' => 'Sélectionner un type de revenu',
            'query_builder' => $this->ecouteurFormulaire->setFiltreEntreprise(),
            // 'choice_label' est toujours utile pour l'affichage dans le champ une fois sélectionné.
            'choice_label' => 'nom',
            // 'choice_html_loader' est la clé pour le rendu personnalisé des options dans la liste déroulante.
            'choice_html_loader' => 'autocomplete/type_revenu',
            // NOUVEAU : Indique à Symfony UX Autocomplete de s'attendre à du HTML pour les options.
            'as_html' => true,
        ]);
    }

    public function getParent(): string
    {
        return BaseEntityAutocompleteType::class;
    }
}