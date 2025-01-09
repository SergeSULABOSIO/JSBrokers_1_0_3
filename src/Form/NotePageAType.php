<?php

namespace App\Form;

use App\DTO\DemandeContactDTO;
use App\DTO\NotePageADTO;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;

class NotePageAType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', TextType::class, [
                'label' => "Nom",
                'empty_data' => ''
            ])
            // ->add('email', EmailType::class, [
            //     'label' => "ContactForm.email",
            //     'empty_data' => ''
            // ])
            // ->add('message', TextareaType::class, [
            //     'label' => "ContactForm.message",
            //     'empty_data' => ''
            // ])
            
            //Le bouton d'enregistrement / soumission
            ->add('Ajouter les article', SubmitType::class, [
                'label' => "Ajouter les articles"
            ])
            ->add('Annuler', SubmitType::class, [
                'label' => "Annuler"
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => NotePageADTO::class,
        ]);
    }
}
