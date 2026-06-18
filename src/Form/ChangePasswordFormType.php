<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;

/**
 * Formulaire de définition d'un nouveau mot de passe (parcours « mot de passe oublié »).
 *
 * Le champ est NON mappé : le contrôleur récupère la valeur et la hache lui-même
 * (aucune entité n'est liée au formulaire — on ne touche qu'au champ `password`).
 * Mêmes contraintes que {@see RegistrationFormType} pour rester cohérent.
 */
class ChangePasswordFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'mapped' => false,
                'first_options' => [
                    'label' => 'Nouveau mot de passe',
                    'attr' => [
                        'autocomplete' => 'new-password',
                        'placeholder' => 'Au moins 4 caractères',
                        'data-password-toggle-target' => 'input',
                    ],
                ],
                'second_options' => [
                    'label' => 'Confirmez le mot de passe',
                    'attr' => [
                        'autocomplete' => 'new-password',
                        'placeholder' => 'Saisissez à nouveau le mot de passe',
                        'data-password-toggle-target' => 'input',
                    ],
                ],
                'invalid_message' => 'Les deux mots de passe doivent être identiques.',
                'constraints' => [
                    new NotBlank([
                        'message' => 'Prière de fournir le mot de passe.',
                    ]),
                    new Length([
                        'min' => 4,
                        'minMessage' => 'Votre mot de passe devrait avoir au moins {{ limit }} caractère(s)',
                        // max length allowed by Symfony for security reasons
                        'max' => 4096,
                    ]),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([]);
    }
}
