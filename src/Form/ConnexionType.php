<?php

namespace App\Form;

use App\DTO\ConnexionDTO;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;

class ConnexionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label' => "Votre addresse mail",
                'empty_data' => ''
            ])
            ->add('motdepasse', PasswordType::class, [
                'label' => "Votre mot de Passe"
            ])
            //Le bouton d'enregistrement / soumission
            ->add('Connexion', SubmitType::class, [
                'label' => "Connexion"
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ConnexionDTO::class,
        ]);
    }
}
