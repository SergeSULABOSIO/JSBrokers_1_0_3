<?php

namespace App\Form;

use App\Entity\Portefeuille;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\TextType;

class PortefeuilleType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', TextType::class, [
                'label' => "Nom du portefeuille",
                'attr' => [
                    'placeholder' => "Ex. Portefeuille de Serge SULA BOSIO",
                ],
            ])
            ->add('gestionnaire', InviteAutocompleteField::class, [
                'label' => "Gestionnaire de compte",
                'placeholder' => "Désigner l'invité responsable",
                'required' => true,
                // Rend la liste déroulante sur <body> : sinon elle est tronquée par le
                // conteneur défilant de la fiche (.form-column { overflow-y:auto }).
                'tom_select_options' => ['dropdownParent' => 'body'],
            ])
            ->add('clients', ClientAutocompleteField::class, [
                'label' => "Clients du portefeuille",
                'placeholder' => "Rattacher des clients",
                'required' => false,
                'multiple' => true,
                'by_reference' => false,
                'tom_select_options' => ['dropdownParent' => 'body'],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Portefeuille::class,
            'csrf_protection' => false,
            'allow_extra_fields' => true,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}
