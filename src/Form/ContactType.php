<?php

namespace App\Form;

use App\DTO\ContactDTO;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;

class ContactType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => "Nom complet",
                'empty_data' => ''
            ])
            ->add('email', EmailType::class, [
                'label' => "Votre addresse mail",
                'empty_data' => ''
            ])
            ->add('message', TextareaType::class, [
                'label' => "Votre message",
                'empty_data' => ''
            ])
            
            //Le bouton d'enregistrement / soumission
            ->add('envoyer', SubmitType::class, [
                'label' => "Envoyer le message"
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ContactDTO::class,
        ]);
    }
}
