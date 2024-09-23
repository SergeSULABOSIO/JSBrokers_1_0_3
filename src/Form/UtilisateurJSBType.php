<?php

namespace App\Form;

use App\Entity\UtilisateurJSB;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class UtilisateurJSBType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', TextType::class, [
                'label' => "Nom Complet"
            ])
            ->add('email', EmailType::class, [
                'label' => "Votre Adresse mail"
            ])
            ->add('motDePasse', PasswordType::class, [
                'label' => "Votre Mot de Passe"
            ])
            ->add('motDePasseConfirme', PasswordType::class, [
                'label' => "Conformer Votre Mot de Passe"
            ])
            ->add('enregistrer', SubmitType::class, [
                'label' => "Enregistrer"
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => UtilisateurJSB::class,
        ]);
    }
}
