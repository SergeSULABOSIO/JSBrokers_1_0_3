<?php

namespace App\Form;

use App\Entity\Objectif;
use App\Enum\ObjectifMode;
use App\Service\Console\EvaluationMetricProvider;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Formulaire de création/édition d'un objectif d'évaluation (SMART).
 * La métrique n'a de sens qu'en mode AUTO ; la valeur atteinte manuelle qu'en
 * mode MANUEL (saisie en revue). Réservé au super-admin (cf. EvaluationController).
 */
class ObjectifType extends AbstractType
{
    public function __construct(private EvaluationMetricProvider $metrics)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $metriqueChoices = ['Aucune (non applicable)' => null];
        foreach ($this->metrics->metriquesDisponibles() as $cle => $label) {
            $metriqueChoices[$label] = $cle;
        }

        $builder
            ->add('titre', TextType::class, [
                'label' => 'Intitulé de l\'objectif',
                'attr'  => ['placeholder' => 'Ex. Résoudre 30 tickets de support', 'data-icon' => 'tache'],
            ])
            ->add('description', TextareaType::class, [
                'label'    => 'Description (facultatif)',
                'required' => false,
                'attr'     => ['rows' => 3, 'placeholder' => 'Précisez le contexte ou les modalités.', 'data-icon' => 'action:description'],
            ])
            ->add('annee', IntegerType::class, [
                'label' => 'Année',
                'attr'  => ['data-icon' => 'action:calendar'],
            ])
            ->add('trimestre', ChoiceType::class, [
                'label'   => 'Période',
                'choices' => [
                    'Annuel'      => 0,
                    'T1 (janv.–mars)'   => 1,
                    'T2 (avr.–juin)'    => 2,
                    'T3 (juil.–sept.)'  => 3,
                    'T4 (oct.–déc.)'    => 4,
                ],
                'attr'    => ['data-icon' => 'action:calendar'],
            ])
            ->add('cible', NumberType::class, [
                'label' => 'Valeur cible',
                'scale' => 2,
                'attr'  => ['placeholder' => 'Ex. 30', 'data-icon' => 'action:count'],
            ])
            ->add('unite', TextType::class, [
                'label'    => 'Unité',
                'required' => false,
                'attr'     => ['placeholder' => 'Ex. tickets, %, ventes', 'data-icon' => 'tranche'],
            ])
            ->add('poids', IntegerType::class, [
                'label' => 'Poids dans le score (%)',
                'attr'  => ['placeholder' => 'Ex. 25', 'data-icon' => 'action:count'],
            ])
            ->add('mode', EnumType::class, [
                'class'        => ObjectifMode::class,
                'label'        => 'Mode de suivi',
                'choice_label' => fn (ObjectifMode $m): string => $m->label(),
                'attr'         => ['data-icon' => 'action:settings'],
            ])
            ->add('metrique', ChoiceType::class, [
                'label'       => 'Métrique automatique (si mode automatique)',
                'choices'     => $metriqueChoices,
                'required'    => false,
                'placeholder' => false,
                'attr'        => ['data-icon' => 'action:analyser'],
            ])
            ->add('valeurManuelle', NumberType::class, [
                'label'    => 'Valeur atteinte (suivi manuel)',
                'scale'    => 2,
                'required' => false,
                'attr'     => ['placeholder' => 'Saisie en revue (mode manuel)', 'data-icon' => 'action:count'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Objectif::class]);
    }
}
