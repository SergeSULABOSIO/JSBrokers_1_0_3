<?php

namespace App\Form;

use App\Entity\Entreprise;
use App\Entity\ModelePieceSinistre;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;

class ModelePieceSinistreType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', TextType::class, [
                'label' => "model_piece_sinistre_form_label_name",
                'attr' => [
                    'placeholder' => "model_piece_sinistre_form_label_name_placeholder",
                ],
            ])
            ->add('description', TextareaType::class, [
                'label' => "model_piece_sinistre_form_label_description",
                'attr' => [
                    'placeholder' => "model_piece_sinistre_form_label_description_placeholder",
                ],
            ])
            ->add('obligatoire', ChoiceType::class, [
                'label' => "Est-elle une pièce obligatoire?",
                'expanded' => true,
                'required' => true,
                'choices'  => [
                    "Oui" => true,
                    "Non" => false,
                ],
            ])
            ->add('enregistrer', SubmitType::class, [
                'label' => "model_piece_sinistre_form_save",
                'attr' => [
                    'class' => "btn btn-secondary",
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ModelePieceSinistre::class,
        ]);
    }
}
