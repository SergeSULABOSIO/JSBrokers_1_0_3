<?php

namespace App\Form;

use App\Entity\PlateformeParametres;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Paramètres comptables de JS Brokers : capital social (apport des actionnaires)
 * et date de constitution, édités sur le singleton PlateformeParametres. Alimentent
 * l'écriture fondatrice (D 521 / C 101) des documents comptables.
 */
class ParametresComptablesType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('capitalSocial', NumberType::class, [
                'label'    => 'Capital social (USD)',
                'scale'    => 2,
                'required' => false,
                'help'     => 'Apport des actionnaires. Génère l\'écriture d\'ouverture (banque / capital social) du bilan.',
                'attr'     => ['placeholder' => 'Ex. 50000.00', 'data-icon' => 'monnaie'],
            ])
            ->add('dateConstitution', DateType::class, [
                'label'    => 'Date de constitution',
                'widget'   => 'single_text',
                'required' => false,
                'help'     => 'Date de l\'apport en capital. À défaut, la date de la première opération est utilisée.',
                'attr'     => ['data-icon' => 'action:calendar'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => PlateformeParametres::class]);
    }
}
