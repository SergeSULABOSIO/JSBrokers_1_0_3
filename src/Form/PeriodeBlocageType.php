<?php

namespace App\Form;

use App\Entity\PeriodeBlocage;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Une période pendant laquelle aucun congé ne peut être posé sans l'accord d'un valideur.
 */
class PeriodeBlocageType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('libelle', TextType::class, [
                'label' => 'Motif',
                'attr'  => ['placeholder' => "Ex. Clôture de l'exercice"],
            ])
            ->add('dateDebut', DateType::class, [
                'label'  => 'Du',
                'widget' => 'single_text',
                'input'  => 'datetime_immutable',
            ])
            ->add('dateFin', DateType::class, [
                'label'  => 'Au',
                'widget' => 'single_text',
                'input'  => 'datetime_immutable',
            ])
            ->add('actif', CheckboxType::class, [
                'label'    => 'Période active',
                'required' => false,
                'help'     => "Décochez plutôt que de supprimer : une période passée explique des refus passés.",
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => PeriodeBlocage::class,
            'csrf_protection'    => false,
            'allow_extra_fields' => true,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}
