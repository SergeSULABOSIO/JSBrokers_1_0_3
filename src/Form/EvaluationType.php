<?php

namespace App\Form;

use App\Entity\Evaluation;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Formulaire de la fiche d'évaluation : appréciation qualitative + clôture.
 * Les valeurs atteintes manuelles se saisissent sur chaque objectif (ObjectifType).
 * À la clôture, le contrôleur fige le score global. Réservé au super-admin.
 */
class EvaluationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('appreciation', TextareaType::class, [
                'label'    => 'Appréciation générale',
                'required' => false,
                'attr'     => ['rows' => 5, 'placeholder' => 'Synthèse, points forts, axes de progrès…', 'data-icon' => 'action:description'],
            ])
            ->add('cloturee', CheckboxType::class, [
                'label'    => 'Clôturer l\'évaluation (fige le score global de la période)',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Evaluation::class]);
    }
}
