<?php

namespace App\Form;

use App\Entity\Contact;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;

class ContactType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', TextType::class, [
                'label' => "Nom",
                'attr' => [
                    'placeholder' => "Nom",
                ],
            ])
            ->add('telephone', TextType::class, [
                'label' => "Téléphone",
                'attr' => [
                    'placeholder' => "Téléphone",
                ],
            ])
            ->add('email', TextType::class, [
                'label' => "Email",
                'attr' => [
                    'placeholder' => "Email",
                ],
            ])
            ->add('type', ChoiceType::class, [
                'label' => "Domaine d'activité",
                // 'help' => "Dans quelle catégorie?",
                'expanded' => false,
                'choices'  => [
                    "Dans l'administration" => Contact::TYPE_CONTACT_ADMINISTRATION,
                    "Dans la production" => Contact::TYPE_CONTACT_PRODUCTION,
                    "Dans le sinistre" => Contact::TYPE_CONTACT_SINISTRE,
                    "Autres" => Contact::TYPE_CONTACT_AUTRES,
                ],
            ])
            ->add('fonction', TextType::class, [
                'label' => "Fonction ou Rôle",
                'attr' => [
                    'placeholder' => "Fonction",
                ],
            ])
            ->add('client', ClientAutocompleteField::class, [
                'label' => "Client associé",
                'required' => false, // Un contact peut être lié à un sinistre sans client direct
                'help' => "Associez ce contact à un client existant."
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Contact::class,
            'csrf_protection' => false,
            'allow_extra_fields' => true,
        ]);
    }

    /**
     * AJOUTEZ CETTE MÉTHODE
     * * En retournant une chaîne vide, on dit à Symfony de ne pas
     * préfixer les champs du formulaire. Le formulaire n'aura pas de nom racine.
     */
    public function getBlockPrefix(): string
    {
        return '';
    }
}
