<?php

namespace App\Form;

use App\Entity\Portefeuille;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;

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
            ])
            // Liste des clients gérée comme une COLLECTION paginée (widget dédié piloté
            // par le FormCanvasProvider), adaptée à un portefeuille de plusieurs dizaines
            // de clients. `mapped => false` : chaque client est créé/détaché via son propre
            // contrôleur (add = fiche client rattachée ; retrait = détachement non destructif).
            ->add('clients', CollectionType::class, [
                'label' => "Clients du portefeuille",
                'entry_type' => ClientType::class,
                'by_reference' => false,
                'allow_add' => true,
                'allow_delete' => true,
                'entry_options' => ['label' => false],
                'mapped' => false,
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
