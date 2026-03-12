<?php

namespace App\Form;

use App\Entity\Taxe;
use App\Services\FormListenerFactory;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\UX\Autocomplete\Form\AsEntityAutocompleteField;
use Symfony\UX\Autocomplete\Form\BaseEntityAutocompleteType;

#[AsEntityAutocompleteField]
class TaxeAutocompleteField extends AbstractType
{
    public function __construct(
        private FormListenerFactory $ecouteurFormulaire,
    ) {}
    
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'class' => Taxe::class,
            'placeholder' => 'Rechercher une taxe',
            'query_builder' => $this->ecouteurFormulaire->setFiltreEntreprise(),
            'searchable_fields' => ['code', 'description'],
            'as_html' => true,
            'choice_label' => function(Taxe $taxe) {
                // Extraction des taux
                $iard = $taxe->getTauxIARD() !== null ? ((float)$taxe->getTauxIARD() * 100) . '%' : '-';
                $vie = $taxe->getTauxVIE() !== null ? ((float)$taxe->getTauxVIE() * 100) . '%' : '-';
                
                $affichage = $taxe->getCode() ?? 'Taxe';
                // On tronque la description si elle est trop longue pour ne pas casser l'interface
                $desc = mb_substr($taxe->getDescription() ?? '', 0, 40) . (mb_strlen($taxe->getDescription() ?? '') > 40 ? '...' : '');
                
                return sprintf(
                    '<div><strong>%s</strong><div style="color: #6c757d; font-size: 0.85em; padding-left: 2px; margin-top: 2px;">%s | IARD: %s | VIE: %s</div></div>',
                    htmlspecialchars($affichage),
                    htmlspecialchars($desc),
                    $iard,
                    $vie
                );
            },
        ]);
    }

    public function getParent(): string
    {
        return BaseEntityAutocompleteType::class;
    }
}