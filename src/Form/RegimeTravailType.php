<?php

namespace App\Form;

use App\Entity\RegimeTravail;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Formulaire de saisie/édition d'un régime de travail, depuis la fiche de l'invité.
 *
 * Les jours travaillés sont des cases à cocher : c'est la seule forme où l'on VOIT
 * immédiatement qu'un temps partiel ne travaille pas le mercredi. Les libellés viennent
 * de RegimeTravail::JOURS_LABELS — source unique, partagée avec l'affichage.
 */
class RegimeTravailType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('joursOuvres', ChoiceType::class, [
                'label'    => 'Jours travaillés',
                'choices'  => array_flip(RegimeTravail::JOURS_LABELS),
                'multiple' => true,
                'expanded' => true,
                'required' => false,
            ])
            ->add('tauxOccupation', NumberType::class, [
                'label' => "Taux d'occupation",
                'scale' => 2,
                'help'  => '1,00 pour un temps plein.',
                'attr'  => ['placeholder' => 'Ex. 0.80'],
            ])
            ->add('dateDebut', DateType::class, [
                'label'  => 'En vigueur depuis le',
                'widget' => 'single_text',
                'input'  => 'datetime_immutable',
            ])
            ->add('dateFin', DateType::class, [
                'label'    => "Jusqu'au",
                'widget'   => 'single_text',
                'input'    => 'datetime_immutable',
                'required' => false,
                'help'     => 'Laisser vide tant que le régime est en vigueur.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => RegimeTravail::class,
            'csrf_protection'    => false,
            'allow_extra_fields' => true,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}
