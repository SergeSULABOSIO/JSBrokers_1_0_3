<?php

namespace App\Form;


use App\Entity\PieceSinistre;
use App\Entity\ModelePieceSinistre;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;

class PieceSinistreType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('description', TextType::class, [
                'label' => "Brève description de la pièce",
                'required' => true,
                'attr' => [
                    'placeholder' => "Description",
                ],
            ])
            ->add('fourniPar', TextType::class, [
                'label' => "Pièce foeurnie par",
                'required' => true,
                'attr' => [
                    'placeholder' => "Source",
                ],
            ])
            ->add('receivedAt', DateType::class, [
                'label' => "Date de recéption",
                'required' => true,
                'widget' => 'single_text',
            ])
            ->add('type', EntityType::class, [
                'label' => "Nature de la pièce",
                'required' => false,
                'class' => ModelePieceSinistre::class,
                'choice_label' => 'nom',
            ])
            // ->add('enregistrer', SubmitType::class, [
            //     'label' => "Enregistrer",
            //     'attr' => [
            //         'class' => "btn btn-secondary",
            //     ],
            // ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PieceSinistre::class,
            'parent_object' => null, // l'objet parent
            'csrf_protection' => false,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}
