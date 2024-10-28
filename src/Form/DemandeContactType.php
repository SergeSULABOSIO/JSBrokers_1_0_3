<?php

namespace App\Form;

use App\DTO\DemandeContactDTO;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;

class DemandeContactType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => t("ContactForm.name"),
                'empty_data' => ''
            ])
            ->add('email', EmailType::class, [
                'label' => t("ContactForm.email"),
                'empty_data' => ''
            ])
            ->add('message', TextareaType::class, [
                'label' => t("ContactForm.message"),
                'empty_data' => ''
            ])
            
            //Le bouton d'enregistrement / soumission
            ->add('envoyer', SubmitType::class, [
                'label' => t("ContactForm.send")
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => DemandeContactDTO::class,
        ]);
    }
}
