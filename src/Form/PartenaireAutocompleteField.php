<?php

namespace App\Form;

use App\Entity\Partenaire;
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
    ) {}
    
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'class' => Partenaire::class,
            'placeholder' => 'Sélectionner le partenaire',
            'query_builder' => $this->ecouteurFormulaire->setFiltreEntreprise(),
            'searchable_fields' => ['nom', 'email'],
            'as_html' => true,
            'choice_label' => function(Partenaire $partenaire) {
                return sprintf(
                    '<div><strong>%s</strong><div style="color: #6c757d; font-size: 0.85em; padding-left: 2px; margin-top: 2px;">%s (Part: %s%%)</div></div>',
                    htmlspecialchars($partenaire->getNom()),
                    htmlspecialchars($partenaire->getEmail() ?? 'Email non disponible'),
                    ($partenaire->getPart() ?? 0) * 100
                );
            },
        ]);
    }

    public function getParent(): string
    {
        return BaseEntityAutocompleteType::class;
    }
}
