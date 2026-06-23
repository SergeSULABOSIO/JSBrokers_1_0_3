<?php

namespace App\Form;

use App\Entity\TaxeVente;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Formulaire de création/édition d'une taxe sur les ventes JS Brokers.
 */
class TaxeVenteType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('code', TextType::class, [
                'label' => 'Code',
                'attr'  => ['placeholder' => 'Ex. TVA', 'style' => 'text-transform:uppercase;', 'data-icon' => 'taxe'],
            ])
            ->add('libelle', TextType::class, [
                'label' => 'Libellé',
                'attr'  => ['placeholder' => 'Ex. Taxe sur la valeur ajoutée', 'data-icon' => 'action:description'],
            ])
            ->add('taux', NumberType::class, [
                'label' => 'Taux (% appliqué sur la vente totale)',
                'scale' => 2,
                'attr'  => ['placeholder' => 'Ex. 16', 'data-icon' => 'tranche'],
            ])
            ->add('autoriteNom', TextType::class, [
                'label' => "Nom de l'autorité fiscale",
                'attr'  => ['placeholder' => 'Ex. Direction Générale des Impôts', 'data-icon' => 'autorite-fiscale'],
            ])
            ->add('autoriteAbreviation', TextType::class, [
                'label' => "Abréviation de l'autorité fiscale",
                'attr'  => ['placeholder' => 'Ex. DGI', 'data-icon' => 'autorite-fiscale'],
            ])
            ->add('actif', CheckboxType::class, [
                'label'    => 'Active',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => TaxeVente::class]);
    }
}
