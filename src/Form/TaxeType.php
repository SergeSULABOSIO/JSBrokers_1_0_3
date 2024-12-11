<?php

namespace App\Form;

use App\Entity\Taxe;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\PercentType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;

class TaxeType extends AbstractType
{

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('description', TextareaType::class, [
                'label' => "Description",
                'attr' => [
                    'placeholder' => "Description ici",
                ],
            ])
            ->add('tauxIARD', PercentType::class, [
                'label' => "Taux (IARD)",
                'attr' => [
                    'placeholder' => "Taux",
                ],
            ])
            ->add('tauxVIE', PercentType::class, [
                'label' => "Taux (VIE)",
                'attr' => [
                    'placeholder' => "Taux",
                ],
            ])
            ->add('organisation', TextType::class, [
                'label' => "Organisation",
                'attr' => [
                    'placeholder' => "Organisation",
                ],
            ])
            ->add('code', TextType::class, [
                'label' => "Code de la taxe",
                'attr' => [
                    'placeholder' => "Code",
                ],
            ])
            ->add('redevable', ChoiceType::class, [
                'label' => "Qui sont-ils redevables à cette taxe?",
                'expanded' => true,
                'choices'  => [
                    "Le client et le courtier" => Taxe::REDEVABLE_COURTIER_ET_CLIENT,
                    "Le courtier" => Taxe::REDEVABLE_COURTIER,
                    "Le client" => Taxe::REDEVABLE_CLIENT,
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
            'data_class' => Taxe::class,
        ]);
    }
}
