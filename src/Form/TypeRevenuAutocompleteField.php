<?php

namespace App\Form;

use App\Entity\TypeRevenu;
use App\Services\FormListenerFactory;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\UX\Autocomplete\Form\AsEntityAutocompleteField;
use Symfony\UX\Autocomplete\Form\BaseEntityAutocompleteType;
use App\Services\Canvas\Autocomplete\RenduOptionAutocomplete;

#[AsEntityAutocompleteField]
class TypeRevenuAutocompleteField extends AbstractType
{
    public function __construct(
        private FormListenerFactory $ecouteurFormulaire,
        private RenduOptionAutocomplete $rendu,
    ) {
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'class' => TypeRevenu::class,
            'placeholder' => 'Sélectionner un type de revenu',
            'query_builder' => $this->ecouteurFormulaire->setFiltreEntreprise(),
            'searchable_fields' => ['nom'],
            // NOUVELLE APPROCHE : On utilise un 'callable' pour générer le HTML directement ici,
            // ce qui est plus robuste que de dépendre d'un fichier template externe.
            'choice_label' => fn (TypeRevenu $typeRevenu) => $this->rendu->libelle(
                titre: $typeRevenu->getNom(),
                contact: [$this->generateDescriptionForChoice($typeRevenu)],
            ),
        ]);
    }

    public function getParent(): string
    {
        return BaseEntityAutocompleteType::class;
    }

    /**
     * Génère la chaîne de description pour une option, en répliquant la logique du template Twig.
     */
    private function generateDescriptionForChoice(TypeRevenu $typeRevenu): string
    {
        $details = [];

        // Logique de calcul
        if ($typeRevenu->getModeCalcul() === TypeRevenu::MODE_CALCUL_POURCENTAGE_CHARGEMENT && $typeRevenu->getPourcentage() !== null && $typeRevenu->getPourcentage() != 0) {
            $details[] = number_format($typeRevenu->getPourcentage(), 2) . '%';
        } elseif ($typeRevenu->getModeCalcul() === TypeRevenu::MODE_CALCUL_MONTANT_FLAT && $typeRevenu->getMontantflat() !== null && $typeRevenu->getMontantflat() != 0) {
            $details[] = 'Fixe de ' . $typeRevenu->getMontantflat();
        } elseif ($typeRevenu->isAppliquerPourcentageDuRisque()) {
            $details[] = 'Taux du risque';
        }

        // Logique du redevable
        $redevableMap = [
            TypeRevenu::REDEVABLE_CLIENT => 'Client',
            TypeRevenu::REDEVABLE_ASSUREUR => 'Assureur',
            TypeRevenu::REDEVABLE_REASSURER => 'Réassureur',
            TypeRevenu::REDEVABLE_PARTENAIRE => 'Partenaire',
        ];
        if (isset($redevableMap[$typeRevenu->getRedevable()])) {
            $details[] = 'Payé par ' . $redevableMap[$typeRevenu->getRedevable()];
        }

        // Logique du partage
        $details[] = $typeRevenu->isShared() ? 'Partageable' : 'Non partageable';

        return implode(' · ', $details);
    }
}