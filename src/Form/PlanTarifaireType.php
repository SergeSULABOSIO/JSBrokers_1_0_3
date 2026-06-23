<?php

namespace App\Form;

use App\Entity\PlateformeParametres;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Édition du plan tarifaire global (entité PlateformeParametres).
 * Les champs scalaires sont mappés directement ; les structures (paquets et
 * poids d'écriture par entité) sont éditées en JSON via des zones de texte
 * (non mappées) — simple et totalement général. Le contrôleur décode/valide.
 */
class PlanTarifaireType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('freeAllowance', IntegerType::class, [
                'label' => 'Allocation gratuite (tokens / fenêtre)',
                'attr'  => ['placeholder' => 'Ex. 1000', 'data-icon' => 'action:count'],
            ])
            ->add('freeWindowHours', IntegerType::class, [
                'label' => 'Durée de la fenêtre gratuite (heures)',
                'attr'  => ['placeholder' => 'Ex. 8', 'data-icon' => 'action:calendar'],
            ])
            ->add('readWeight', IntegerType::class, [
                'label' => 'Poids d\'une lecture (tokens / entité)',
                'attr'  => ['placeholder' => 'Ex. 2', 'data-icon' => 'action:count'],
            ])
            ->add('defaultWriteWeight', IntegerType::class, [
                'label' => 'Poids d\'écriture par défaut (tokens)',
                'attr'  => ['placeholder' => 'Ex. 5', 'data-icon' => 'action:count'],
            ])
            ->add('usdPerToken', NumberType::class, [
                'label' => 'Taux de conversion (USD / token)',
                'scale' => 5,
                'attr'  => ['placeholder' => 'Ex. 0.001', 'data-icon' => 'monnaie'],
            ])
            // Paquets prépayés : édités via une collection + boîte de dialogue (contrôleur
            // Stimulus `packs-editor`) qui tient ce champ caché synchronisé en JSON. Le
            // contrôleur PHP décode ce JSON de façon générique (cf. decodeJsonMap).
            ->add('packsJson', HiddenType::class, [
                'mapped' => false,
                'data'   => $options['packs_json'],
            ])
            ->add('writeWeightsJson', TextareaType::class, [
                'label'  => 'Poids d\'écriture par entité (JSON : { "App\\\\Entity\\\\Cotation": n })',
                'mapped' => false,
                'required' => false,
                'attr'   => ['rows' => 8, 'style' => 'font-family:monospace;', 'placeholder' => '{ "App\\\\Entity\\\\Cotation": 5 }', 'data-icon' => 'action:settings'],
                'data'   => $options['write_weights_json'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => PlateformeParametres::class,
            'packs_json'         => '',
            'write_weights_json' => '',
        ]);
    }
}
