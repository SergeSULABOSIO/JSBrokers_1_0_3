<?php

namespace App\Form;

use Symfony\Component\Form\Extension\Core\Type\CollectionType;

use App\Entity\ModelePieceSinistre;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
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
                'label' => "Description",
                'attr' => [
                    'placeholder' => "Description",
                ],
            ])
            ->add('obligatoire', ChoiceType::class, [
                'label' => "model_piece_sinistre_form_label_name_obligatoire",
                'expanded' => true,
                'required' => true,
                'choices'  => [
                    "Oui" => true,
                    "Non" => false,
                ],
            ])
            // PIÈCES JOINTES de cette fiche. `mapped: false` comme les onze autres
            // collections de documents du projet : chaque pièce est créée, modifiée et
            // supprimée par son propre dialogue, via l'API de Document — le formulaire
            // parent ne fait que porter le widget.
            ->add('documents', CollectionType::class, [
                'label' => "Documents",
                'entry_type' => DocumentType::class,
                'by_reference' => false,
                'allow_add' => true,
                'allow_delete' => true,
                'required' => false,
                'entry_options' => ['label' => false],
                'mapped' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ModelePieceSinistre::class,
            'csrf_protection' => false,
            'allow_extra_fields' => true,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}
