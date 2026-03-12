<?php

namespace App\Form;

use App\Entity\Article;
use App\Services\FormListenerFactory;
use App\Services\ServiceMonnaies;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;

class ArticleType extends AbstractType
{
    public function __construct(
        private FormListenerFactory $ecouteurFormulaire,
        private TranslatorInterface $translatorInterface,
        private ServiceMonnaies $serviceMonnaies
    ) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', TextType::class, [
                'label' => "Nom",
                'attr' => [
                    'placeholder' => "Description de la ligne",
                ],
            ])
            ->add('montant', MoneyType::class, [
                'label' => "Montant (TTC)",
                'currency' => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                'grouping' => true,
                'disabled' => false,
                'attr' => [
                    'placeholder' => "Montant payable",
                ],
            ])
            // Utilisation des Autocompletes modernes sans écraser leur configuration interne
            ->add('tranche', TrancheAutocompleteField::class, [
                'label' => "Lié à une Tranche",
                'required' => false,
            ])
            ->add('revenuFacture', RevenuPourCourtierAutocompleteField::class, [
                'label' => "Lié à un Revenu/Commission",
                'required' => false,
            ])
            ->add('taxeFacturee', TaxeAutocompleteField::class, [
                'label' => "Lié à une Taxe",
                'required' => false,
            ]);
            
            // Note: Le champ 'pourcentage' n'est pas dans l'entité Article
            // Note: Le bouton 'Enregistrer' est retiré car géré par la modale du Canvas
            // Note: Le champ 'note' est retiré car injecté dynamiquement
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Article::class,
            'csrf_protection' => false,
            'allow_extra_fields' => true,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}