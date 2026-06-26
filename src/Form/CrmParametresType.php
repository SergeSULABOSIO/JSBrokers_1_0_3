<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Formulaire des paramètres CRM (poids du score, seuils, automatisations), sur le
 * pattern Console (Coupon). Formulaire NON mappé : les valeurs courantes sont
 * fournies par le contrôleur (options) et relues après soumission pour être
 * écrites sur PlateformeParametres.
 */
class CrmParametresType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $weights = $options['weights'];
        $thresholds = $options['thresholds'];
        $automation = $options['automation'];

        foreach ($weights as $key => $valeur) {
            $builder->add('w_' . $key, IntegerType::class, [
                'label'  => 'Poids — ' . ucfirst($key),
                'data'   => $valeur,
                'mapped' => false,
                'attr'   => ['min' => 0, 'max' => 100, 'data-icon' => 'tranche'],
            ]);
        }

        $builder
            ->add('t_vert', IntegerType::class, ['label' => 'Vert ≥', 'data' => $thresholds['vert'], 'mapped' => false, 'attr' => ['data-icon' => 'action:check']])
            ->add('t_jaune', IntegerType::class, ['label' => 'Jaune ≥', 'data' => $thresholds['jaune'], 'mapped' => false, 'attr' => ['data-icon' => 'action:check']])
            ->add('t_orange', IntegerType::class, ['label' => 'Orange ≥', 'data' => $thresholds['orange'], 'mapped' => false, 'attr' => ['data-icon' => 'action:check']])
            ->add('a_inactivite', IntegerType::class, ['label' => 'Inactivité (jours)', 'data' => $automation['inactiviteJours'], 'mapped' => false, 'attr' => ['min' => 1, 'data-icon' => 'action:calendar']])
            ->add('a_soldebas', IntegerType::class, ['label' => 'Solde bas (tokens)', 'data' => $automation['soldeBas'], 'mapped' => false, 'attr' => ['min' => 0, 'data-icon' => 'monnaie']])
            ->add('a_churn', IntegerType::class, ['label' => 'Churn (jours d\'inactivité)', 'data' => $automation['churnJours'], 'mapped' => false, 'attr' => ['min' => 1, 'data-icon' => 'action:alert']]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'weights'    => [],
            'thresholds' => [],
            'automation' => [],
        ]);
        $resolver->setAllowedTypes('weights', 'array');
        $resolver->setAllowedTypes('thresholds', 'array');
        $resolver->setAllowedTypes('automation', 'array');
    }
}
