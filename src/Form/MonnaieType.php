<?php

namespace App\Form;

use App\Entity\Monnaie;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;

class MonnaieType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', TextType::class, [
                'label' => "Nom",
                'attr' => [
                    'placeholder' => "Nom de la monnaie",
                ],
            ])
            ->add('code', TextType::class, [
                'label' => "Code",
                'attr' => [
                    'placeholder' => "Code ISO (ex: USD)",
                ],
            ])
            ->add('tauxusd', NumberType::class, [
                'label' => "Taux USD",
                'scale' => 4,
                'attr' => [
                    'placeholder' => "Taux de change",
                ],
            ])
            ->add('fonction', ChoiceType::class, [
                'label' => "Fonction",
                'expanded' => true,
                'choices'  => [
                    "Aucune" => Monnaie::FONCTION_AUCUNE,
                    "Saisie et Affichage" => Monnaie::FONCTION_SAISIE_ET_AFFICHAGE,
                    "Saisie Uniquement" => Monnaie::FONCTION_SAISIE_UNIQUEMENT,
                    "Affichage Uniquement" => Monnaie::FONCTION_AFFICHAGE_UNIQUEMENT,
                ],
            ])
            ->add('locale', ChoiceType::class, [
                'label' => "Monnaie Locale ?",
                'expanded' => true,
                'choices'  => [
                    "Non" => false,
                    "Oui" => true,
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Monnaie::class,
            'csrf_protection' => false,
            'allow_extra_fields' => true,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}
