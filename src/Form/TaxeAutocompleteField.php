<?php

namespace App\Form;

use App\Entity\Taxe;
use App\Form\Autocomplete\RechercheAutocomplete;
use App\Service\TexteRiche\TexteRiche;
use App\Services\FormListenerFactory;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\UX\Autocomplete\Form\AsEntityAutocompleteField;
use Symfony\UX\Autocomplete\Form\BaseEntityAutocompleteType;
use App\Services\Canvas\Autocomplete\RenduOptionAutocomplete;

#[AsEntityAutocompleteField]
class TaxeAutocompleteField extends AbstractType
{
    public function __construct(
        private FormListenerFactory $ecouteurFormulaire,
        private RenduOptionAutocomplete $rendu,
    ) {}
    
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'class' => Taxe::class,
            'placeholder' => 'Rechercher une taxe',
            'query_builder' => $this->ecouteurFormulaire->setFiltreEntreprise(),
            // Notre LIKE, pas celui du bundle : jokers tapés échappés (RechercheAutocomplete).
            'filter_query' => RechercheAutocomplete::surLesChamps(['code', 'description']),
            'choice_label' => fn (Taxe $taxe) => $this->rendu->libelle(
                titre: $taxe->getCode() ?: 'Taxe',
                contact: [
                    TexteRiche::lisible($taxe->getDescription()), // le TEXTE du HTML de l'éditeur
                    'IARD ' . ($taxe->getTauxIARD() !== null ? $taxe->tauxPourcentage(true)->format(2) . ' %' : '—'),
                    'VIE ' . ($taxe->getTauxVIE() !== null ? $taxe->tauxPourcentage(false)->format(2) . ' %' : '—'),
                ],
            ),
        ]);
    }

    public function getParent(): string
    {
        return BaseEntityAutocompleteType::class;
    }
}