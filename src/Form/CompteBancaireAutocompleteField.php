<?php

namespace App\Form;

use App\Entity\CompteBancaire;
use App\Services\CanvasBuilder;
use App\Services\FormListenerFactory;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\UX\Autocomplete\Form\AsEntityAutocompleteField;
use Symfony\UX\Autocomplete\Form\BaseEntityAutocompleteType;

#[AsEntityAutocompleteField]
class CompteBancaireAutocompleteField extends AbstractType
{
    public function __construct(
        private FormListenerFactory $ecouteurFormulaire,
        private CanvasBuilder $canvasBuilder
    ) {}
    
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'class' => CompteBancaire::class,
            'placeholder' => "Séléctionnez le compte",
            'query_builder' => $this->ecouteurFormulaire->setFiltreEntreprise(),
            'searchable_fields' => ['nom', 'numero', 'banque'],
            'as_html' => true,
            'choice_label' => function (CompteBancaire $compte): string {
                // 1. Hydratation de l'entité avec les valeurs calculées (solde, totaux, etc.)
                $this->canvasBuilder->loadAllCalculatedValues($compte);

                // 2. Extraction des données pour l'affichage
                $nomCompte = $compte->getNom() ?? 'Compte sans nom';
                $nomEntreprise = $compte->getEntreprise()?->getNom() ?? 'N/A';
                $iban = $compte->getNumero() ?? 'N/A';
                $swift = $compte->getCodeSwift() ?? 'N/A';

                // Utilisation des propriétés hydratées par le CanvasBuilder
                $totalEntrees = $compte->totalEntrees ?? 0.0;
                $totalSorties = $compte->totalSorties ?? 0.0;
                $soldeActuel = $compte->soldeActuel ?? 0.0;

                // 3. Construction du HTML en s'inspirant de RevenuPourCourtierAutocompleteField
                return sprintf(
                    '<div class="jsb-autocomplete-item" style="background-color: #ffffff;">
                        <div class="jsb-autocomplete-title">%s</div>
                        <div class="jsb-autocomplete-context">
                            <span>Entreprise: <strong>%s</strong></span>
                            <span class="jsb-context-separator">|</span>
                            <span>IBAN: <strong>%s</strong></span>
                            <span class="jsb-context-separator">|</span>
                            <span>SWIFT: <strong>%s</strong></span>
                        </div>
                        <div class="jsb-autocomplete-indicators" style="grid-template-columns: repeat(3, 1fr);">
                            <div>
                                <div><span class="jsb-indicator-label">Total Entrées</span><span class="jsb-indicator-value text-success">%s</span></div>
                            </div>
                            <div>
                                <div><span class="jsb-indicator-label">Total Sorties</span><span class="jsb-indicator-value text-danger">%s</span></div>
                            </div>
                            <div>
                                <div><span class="jsb-indicator-label">Solde Actuel</span><span class="jsb-indicator-value text-cobalt">%s</span></div>
                            </div>
                        </div>
                    </div>',
                    htmlspecialchars($nomCompte),
                    htmlspecialchars($nomEntreprise),
                    htmlspecialchars($iban),
                    htmlspecialchars($swift),
                    number_format($totalEntrees, 2, ',', ' '),
                    number_format($totalSorties, 2, ',', ' '),
                    number_format($soldeActuel, 2, ',', ' ')
                );
            },
        ]);
    }

    public function getParent(): string
    {
        return BaseEntityAutocompleteType::class;
    }
}
