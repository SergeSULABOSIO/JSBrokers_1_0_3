<?php

namespace App\Form;

use App\Entity\Client;
use App\Entity\Groupe;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;

class GroupeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', TextType::class, [
                'label' => "Nom du groupe",
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
            ->add('clients', EntityType::class, [
                'class' => Client::class,
                'choice_label' => 'nom',
                'multiple' => true,
                'expanded' => false, // 'false' pour un select multiple, 'true' pour des checkboxes
                'by_reference' => false,
                'label' => "Clients membres du groupe",
                'required' => false,
                'attr' => [
                    // Vous pouvez ajouter un contrôleur Stimulus pour améliorer l'UX de ce champ
                    // ex: 'data-controller' => 'tom-select'
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Groupe::class,
            'csrf_protection' => false,
            'allow_extra_fields' => true,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}
