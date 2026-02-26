<?php

namespace App\Form;

use App\Entity\Risque;
use App\Services\FormListenerFactory;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\UX\Autocomplete\Form\AsEntityAutocompleteField;
use Symfony\UX\Autocomplete\Form\BaseEntityAutocompleteType;

#[AsEntityAutocompleteField]
class RisqueAutocompleteField extends AbstractType
{
    public function __construct(
        private FormListenerFactory $ecouteurFormulaire,
    ) {}
    
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'class' => Risque::class,
            'placeholder' => 'Sélectionner un risque',
            'query_builder' => $this->ecouteurFormulaire->setFiltreEntreprise(),
            'searchable_fields' => ['nomComplet', 'code'],
            'as_html' => true,
            'choice_label' => function(Risque $risque) {
                return sprintf(
                    '<div><strong>%s</strong><div style="color: #6c757d; font-size: 0.85em; padding-left: 2px; margin-top: 2px;">Code: %s. %s</div></div>',
                    htmlspecialchars($risque->getNomComplet()),
                    htmlspecialchars($risque->getCode()),
                    htmlspecialchars(substr($risque->getDescription() ?? '', 0, 50) . (strlen($risque->getDescription() ?? '') > 50 ? '...' : ''))
                );
            },
        ]);
    }

    public function getParent(): string
    {
        return BaseEntityAutocompleteType::class;
    }
}
