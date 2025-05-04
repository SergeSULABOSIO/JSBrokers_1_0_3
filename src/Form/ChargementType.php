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
use Symfony\Component\Form\Extension\Core\Type\TextareaType;

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
            ->add('description', TextareaType::class, [
                'label' => "Description",
                'attr' => [
                    'placeholder' => "Description",
                ],
            ])
            ->add('fonction', ChoiceType::class, [
                'label' => "Quelle est la fonction de ce type de chargement?",
                'expanded' => true,
                'required' => true,
                'choices'  => [
                    "C'est une prime nette" => Chargement::FONCTION_PRIME_NETTE,
                    "C'est un fronting (commisson de cession pour le cédant)" => Chargement::FONCTION_FRONTING,
                    "C'est un frais administratif" => Chargement::FONCTION_FRAIS_ADMIN,
                    "C'est une taxe" => Chargement::FONCTION_TAXE,
                ],
            ])
            
            // ->add('montantflat', MoneyType::class, [
            //     'label' => "Montant Flat",
            //     'currency' => "USD",
            //     'required' => false,
            //     'grouping' => true,
            //     'attr' => [
            //         'placeholder' => "Code",
            //     ],
            // ])
            // ->add('tauxSurPrimeNette', PercentType::class, [
            //     'required' => false,
            //     'label' => "Taux par rapport à la prime nette",
            //     'scale' => 3,
            //     'attr' => [
            //         'placeholder' => "Taux",
            //     ],
            // ])
            // ->add('imposable', ChoiceType::class, [
            //     'label' => "Peut-on appliquer les taxes?",
            //     'help' => "Oui, si les taxes peuvent s'appliquer.",
            //     'expanded' => true,
            //     'choices'  => [
            //         "Oui" => true,
            //         "Non" => false,
            //     ],
            // ])
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
            'parent_object' => null, // l'objet parent
        ]);
    }
}
