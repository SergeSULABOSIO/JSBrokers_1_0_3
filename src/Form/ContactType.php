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
            //Le bouton d'enregistrement / soumission
            ->add('enregistrer', SubmitType::class, [
                'label' => "Enregistrer",
                'attr' => [
                    'class' => "btn btn-secondary",
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Contact::class,
            'parent_object' => null, // l'objet parent
        ]);
    }
}
