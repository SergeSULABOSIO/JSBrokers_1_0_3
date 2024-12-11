<?php

namespace App\Form;

use App\Entity\Entreprise;
use App\Entity\CompteBancaire;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;

class CompteBancaireType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('intitule', TextType::class, [
                'label' => "Intitulé du compte",
                'attr' => [
                    'placeholder' => "Intitulé du compte ici",
                ],
            ])
            ->add('numero', TextType::class, [
                'label' => "Numéro du compte",
                'attr' => [
                    'placeholder' => "Numéro du compte ici",
                ],
            ])
            ->add('banque', TextType::class, [
                'label' => "Nom de la Banque",
                'attr' => [
                    'placeholder' => "Nom de la banque",
                ],
            ])
            ->add('codeSwift', TextType::class, [
                'label' => "Code Swift",
                'attr' => [
                    'placeholder' => "Code Swift",
                ],
            ])
            // ->add('entreprise', EntityType::class, [
            //     'class' => Entreprise::class,
            //     'choice_label' => 'id',
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
            'data_class' => CompteBancaire::class,
        ]);
    }
}
