<?php

namespace App\Form;

use App\Entity\Utilisateur;
use App\Constantes\Constantes;
use App\Services\FormListenerFactory;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\IsTrue;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;

class RegistrationFormType extends AbstractType
{

    public function __construct(
        private FormListenerFactory $ecouteurFormulaire
    ) {}
    
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', TextType::class, [
                'label' => "Nom complet"
            ])
            ->add('email', TextType::class, [
                'label' => "Adresse mail"
            ])
            ->add('locale', ChoiceType::class, [
                'label' => "Langue",
                'expanded' => false,
                'choices'  => Constantes::TAB_LANGUES,
            ])
            ->add('plainPassword', PasswordType::class, [
                'label' => "Mot de passe",
                // instead of being set onto the object directly,
                // this is read and encoded in the controller
                'mapped' => false,
                'toggle' => true,
                'attr' => ['autocomplete' => 'new-password'],
                'constraints' => [
                    new NotBlank([
                        'message' => 'Prière de fournir le mot de passe.',
                    ]),
                    new Length([
                        'min' => 4,
                        'minMessage' => 'Votre mot de passe devrait avoit au moins {{ limit }} charactère(s)',
                        // max length allowed by Symfony for security reasons
                        'max' => 4096,
                    ]),
                ],
            ])
            ->add('agreeTerms', CheckboxType::class, [
                'label' => "J'accepte les conditions et termes d'utilisation",
                'mapped' => false,
                'constraints' => [
                    new IsTrue([
                        'message' => "Vous devez accepter les termes et conditions d'utilisation.",
                    ]),
                ],
            ])
            //Le bouton d'enregistrement / soumission
            ->add('enregistrer', SubmitType::class, [
                'label' => "Créer un compte"
            ])
            ->addEventListener(FormEvents::POST_SUBMIT, $this->ecouteurFormulaire->timeStamps())
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Utilisateur::class,
        ]);
    }
}
