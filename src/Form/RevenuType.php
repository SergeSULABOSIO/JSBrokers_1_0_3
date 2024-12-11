<?php

namespace App\Form;

use App\Entity\Revenu;
use Doctrine\DBAL\Types\FloatType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\PercentType;

class RevenuType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', TextType::class, [
                'label' => "Nom",
                'attr' => [
                    'placeholder' => "Nom ici",
                ],
            ])
            ->add('formule', ChoiceType::class, [
                'label' => "Quelle est la formule applicable?",
                'expanded' => true,
                'choices'  => [
                    "Un pourcentage du Fronting" => Revenu::FORMULE_POURCENTAGE_FRONTING,
                    "Un pourcentage de la prime nette" => Revenu::FORMULE_POURCENTAGE_PRIME_NETTE,
                    "Un pourcentage de la prime totale" => Revenu::FORMULE_POURCENTAGE_PRIME_TOTALE,
                ],
            ])
            ->add('pourcentage', PercentType::class, [
                'label' => "Taux / Pourcentage",
                'attr' => [
                    'placeholder' => "Pourcentage",
                ],
            ])
            ->add('montantflat', FloatType::class, [
                'label' => "Montant fixe",
                'attr' => [
                    'placeholder' => "Montant fixe",
                ],
            ])
            ->add('shared', ChoiceType::class, [
                'help' => "Le montant partage (l'assiette) équivaut au montant après déduction des toutes les taxes.",
                'label' => "Est-il partageable avec un partenaire?",
                'expanded' => true,
                'choices'  => [
                    "Oui, il peut être payé en plusieurs tranches." => true,
                    "Non, pas du tout." => false,
                ],
            ])
            ->add('multipayments', ChoiceType::class, [
                'label' => "Son paiement peut-il être échélonné?",
                'expanded' => true,
                'choices'  => [
                    "Oui, il peut être payé en plusieurs tranches" => true,
                    "Non, pas du tout" => false,
                ],
            ])
            ->add('redevable', ChoiceType::class, [
                'label' => "Qui est redevable?",
                'expanded' => true,
                'choices'  => [
                    "L'assureur" => Revenu::REDEVABLE_ASSUREUR,
                    "Le client" => Revenu::REDEVABLE_CLIENT,
                    "Le partenaire" => Revenu::REDEVABLE_PARTENAIRE,
                    "Le réassureur" => Revenu::REDEVABLE_REASSURER,
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
            'data_class' => Revenu::class,
        ]);
    }
}
