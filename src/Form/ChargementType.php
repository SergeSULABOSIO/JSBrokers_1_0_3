<?php

namespace App\Form;

use App\Entity\Chargement;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\PercentType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;

class ChargementType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', TextType::class, [
                'label' => "Nom",
                'attr' => [
                    'placeholder' => "Nom",
                ],
            ])
            ->add('description', TextType::class, [
                'label' => "Description",
                'attr' => [
                    'placeholder' => "Description",
                ],
            ])
            ->add('montantflat', MoneyType::class, [
                'label' => "Montant Flat",
                'attr' => [
                    'placeholder' => "Code",
                ],
            ])
            ->add('tauxSurPrimeNette', PercentType::class, [
                'label' => "Taux par rapport à la prime nette",
                'attr' => [
                    'placeholder' => "Taux",
                ],
            ])
            ->add('imposable', ChoiceType::class, [
                'label' => "Peut-on appliquer les taxes?",
                'help' => "Oui, si les taxes peuvent s'appliquer.",
                'expanded' => true,
                'choices'  => [
                    "Oui" => true,
                    "Non" => false,
                ],
            ])
             //Le bouton d'enregistrement / soumission
             ->add('enregistrer', SubmitType::class, [
                'label' => "Enregistrer",
                'attr' => [
                    'class' => "btn btn-secondary",
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Chargement::class,
        ]);
    }
}
