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
            ->add('fonction', TextType::class, [
                'label' => "Fonction ou Rôle",
                'attr' => [
                    'placeholder' => "Fonction",
                ],
            ])
            // ->add('entreprise', EntrepriseAutocompleteField::class)
            // ->add('client', ClientAutocompleteField::class)
            ->add('client', ClientAutocompleteField::class, [
                'label' => "Client",
                'class' => Client::class,
                'choice_label' => 'nom',
                // 'autocomplete' => true,
                'required' => false,
                'attr' => [
                    'placeholder' => "Client",
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
        ]);
    }
}
