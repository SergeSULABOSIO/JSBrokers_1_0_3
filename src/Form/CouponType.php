<?php

namespace App\Form;

use App\Entity\Coupon;
use App\Token\ParametresTokenService;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Formulaire de création/édition d'un coupon de réduction sur les tokens.
 */
class CouponType extends AbstractType
{
    public function __construct(private ParametresTokenService $parametres)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // Choix de paquets ciblés : tous, ou un paquet précis du plan courant.
        $packChoices = ['Tous les paquets' => null];
        foreach (array_keys($this->parametres->packs()) as $key) {
            $packChoices[ucfirst($key)] = $key;
        }

        $builder
            ->add('code', TextType::class, [
                'label' => 'Code',
                'attr'  => ['placeholder' => 'Ex. PROMO2026', 'style' => 'text-transform:uppercase;', 'data-icon' => 'offre'],
            ])
            ->add('type', ChoiceType::class, [
                'label'   => 'Type de remise',
                'choices' => [
                    'Pourcentage (%)'   => Coupon::TYPE_PERCENT,
                    'Montant fixe (USD)' => Coupon::TYPE_FIXED,
                ],
                'attr'    => ['data-icon' => 'tranche'],
            ])
            ->add('valeur', NumberType::class, [
                'label' => 'Valeur (% si pourcentage, sinon montant en USD)',
                'scale' => 2,
                'attr'  => ['placeholder' => 'Ex. 15', 'data-icon' => 'monnaie'],
            ])
            ->add('dateDebut', DateTimeType::class, [
                'label'  => 'Début de validité',
                'widget' => 'single_text',
                'attr'   => ['placeholder' => 'Date de début', 'data-icon' => 'action:calendar'],
            ])
            ->add('dateFin', DateTimeType::class, [
                'label'  => 'Fin de validité',
                'widget' => 'single_text',
                'attr'   => ['placeholder' => 'Date de fin', 'data-icon' => 'action:calendar'],
            ])
            ->add('usageLimit', IntegerType::class, [
                'label'    => 'Limite d\'utilisations (vide = illimité)',
                'required' => false,
                'attr'     => ['placeholder' => 'Illimité si vide', 'data-icon' => 'action:count'],
            ])
            ->add('packCible', ChoiceType::class, [
                'label'       => 'Paquet ciblé',
                'choices'     => $packChoices,
                'required'    => false,
                'placeholder' => false,
                'attr'        => ['data-icon' => 'offre'],
            ])
            ->add('actif', CheckboxType::class, [
                'label'    => 'Actif',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Coupon::class]);
    }
}
