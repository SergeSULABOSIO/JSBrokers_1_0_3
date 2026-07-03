<?php

namespace App\Form;

use App\Entity\Charge;
use App\Entity\ChargeCourtier;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Formulaire de saisie/édition d'un type de charge du courtier (référentiel OHADA
 * classe 6 du workspace). Réutilise les référentiels de Charge (console) — DRY.
 */
class ChargeCourtierType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('code', TextType::class, [
                'label' => 'Code de la charge',
                'attr'  => ['placeholder' => 'Ex. LOYER', 'style' => 'text-transform:uppercase;'],
            ])
            ->add('libelle', TextType::class, [
                'label' => 'Libellé',
                'attr'  => ['placeholder' => 'Ex. Loyer du bureau'],
            ])
            ->add('compteOhada', ChoiceType::class, [
                'label'        => 'Compte OHADA (classe 6)',
                'choices'      => array_flip(Charge::COMPTES_OHADA),
                'choice_label' => fn ($choice, $key) => sprintf('%s — %s', $choice, $key),
                'help'         => 'Compte de rattachement au plan comptable SYSCOHADA : détermine le poste de la charge au compte de résultat.',
            ])
            ->add('comportement', ChoiceType::class, [
                'label'   => 'Comportement',
                'choices' => array_flip(Charge::COMPORTEMENTS),
                'help'    => 'Fixe (indépendante de l\'activité) ou variable (proportionnelle à l\'activité).',
            ])
            ->add('periodicite', ChoiceType::class, [
                'label'   => 'Périodicité',
                'choices' => array_flip(Charge::PERIODICITES),
            ])
            ->add('montantBudgeteMensuel', NumberType::class, [
                'label'    => 'Budget mensuel (optionnel)',
                'scale'    => 2,
                'required' => false,
                'attr'     => ['placeholder' => 'Ex. 500.00'],
            ])
            ->add('actif', CheckboxType::class, [
                'label'    => 'Charge active (proposée à la saisie des dépenses)',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ChargeCourtier::class,
            'csrf_protection' => false,
            'allow_extra_fields' => true,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}
