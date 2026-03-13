<?php

namespace App\Form;

use App\Entity\Chargement;
use App\Services\FormListenerFactory;
use App\Services\Canvas\Indicator\IndicatorCalculationHelper;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\UX\Autocomplete\Form\AsEntityAutocompleteField;
use Symfony\UX\Autocomplete\Form\BaseEntityAutocompleteType;

#[AsEntityAutocompleteField]
class ChargementAutocompleteField extends AbstractType
{
    public function __construct(
        private FormListenerFactory $ecouteurFormulaire,
        private IndicatorCalculationHelper $calculationHelper // <-- Injection du BON service !
    ) {
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'class' => Chargement::class,
            'placeholder' => 'Sélectionner un type de chargement',
            'query_builder' => $this->ecouteurFormulaire->setFiltreEntreprise(),
            'searchable_fields' => ['nom'],
            'as_html' => true,
            'choice_label' => function(Chargement $chargement) {
                // On appelle la méthode depuis le bon helper
                $description = $this->calculationHelper->Chargement_getFonctionString($chargement);
                
                return sprintf(
                    '<div><strong>%s</strong><div style="color: #6c757d; font-size: 0.85em; padding-left: 2px; margin-top: 2px;">Fonction: %s</div></div>',
                    htmlspecialchars($chargement->getNom() ?? 'Sans nom'),
                    htmlspecialchars($description ?? 'Non définie')
                );
            },
        ]);
    }

    public function getParent(): string
    {
        return BaseEntityAutocompleteType::class;
    }
}