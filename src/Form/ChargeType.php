<?php

namespace App\Form;

use App\Entity\Charge;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Formulaire de création/édition d'un type de charge (référentiel OHADA).
 */
class ChargeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // Comptes OHADA classe 6 : libellé affiché « 66 — Charges de personnel ».
        $comptesChoices = [];
        foreach (Charge::COMPTES_OHADA as $code => $label) {
            $comptesChoices[sprintf('%s — %s', $code, $label)] = $code;
        }

        $builder
            ->add('code', TextType::class, [
                'label' => 'Code',
                'attr'  => ['placeholder' => 'Ex. LOYER-BUREAU', 'style' => 'text-transform:uppercase;', 'data-icon' => 'charge'],
            ])
            ->add('libelle', TextType::class, [
                'label' => 'Libellé',
                'attr'  => ['placeholder' => 'Ex. Loyer du bureau de Kinshasa', 'data-icon' => 'action:description'],
            ])
            ->add('compteOhada', ChoiceType::class, [
                'label'   => 'Compte OHADA (classe 6)',
                'choices' => $comptesChoices,
                'attr'    => ['data-icon' => 'autorite-fiscale'],
            ])
            ->add('destination', ChoiceType::class, [
                'label'   => 'Destination analytique',
                'choices' => array_flip(Charge::DESTINATIONS),
                'help'    => 'Pilote le coût d\'acquisition (Acquisition) et la marge brute (Coût direct).',
                'attr'    => ['data-icon' => 'action:analyser'],
            ])
            ->add('comportement', ChoiceType::class, [
                'label'   => 'Comportement',
                'choices' => array_flip(Charge::COMPORTEMENTS),
                'attr'    => ['data-icon' => 'tranche'],
            ])
            ->add('periodicite', ChoiceType::class, [
                'label'   => 'Périodicité',
                'choices' => array_flip(Charge::PERIODICITES),
                'attr'    => ['data-icon' => 'action:calendar'],
            ])
            ->add('montantBudgeteMensuel', NumberType::class, [
                'label'    => 'Budget mensuel prévisionnel (USD, optionnel)',
                'scale'    => 2,
                'required' => false,
                'attr'     => ['placeholder' => 'Ex. 1500', 'data-icon' => 'monnaie'],
            ])
            ->add('actif', CheckboxType::class, [
                'label'    => 'Active',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Charge::class]);
    }
}
