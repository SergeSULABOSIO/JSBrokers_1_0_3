<?php

namespace App\Form;

use App\Entity\Client;
use App\Entity\Contact;
use App\Entity\Entreprise;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;

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
                'help' => "Dans quelle catégorie voudriez-vous classer ce contact?",
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
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Contact::class,
            // AJOUT 1: Désactive la protection CSRF pour ce formulaire API
            'csrf_protection' => false,

            // AJOUT 2: Autorise les champs non définis dans le form (comme 'id') à être envoyés
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
