<?php

namespace App\Form;

use App\Entity\Risque;
use App\Entity\Entreprise;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\PercentType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;

class RisqueType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nomComplet', TextType::class, [
                'label' => "Nom Complet du risque",
                'attr' => [
                    'placeholder' => "Nom",
                ],
            ])
            ->add('code', TextType::class, [
                'label' => "Code du risque",
                'attr' => [
                    'placeholder' => "Code",
                ],
            ])
            ->add('description', TextareaType::class, [
                'label' => "Description",
                'attr' => [
                    'placeholder' => "Description ici",
                ],
            ])
            ->add('pourcentageCommissionSpecifiqueHT', PercentType::class, [
                'label' => "Taux de commission",
                'help' => "Il s'agit ici du taux que l'application doit considérer uniquement pour ce risque.",
                'attr' => [
                    'placeholder' => "Taux",
                ],
            ])
            ->add('branche', ChoiceType::class, [
                'label' => "Branche d'assurance",
                'help' => "IARD pour 'I'ncendie 'A'ccident et 'R'isques 'D'ivers. Càd autres que les assurances Vie.",
                'expanded' => true,
                'choices'  => [
                    "Les IARD (non vie)" => Risque::BRANCHE_IARD_OU_NON_VIE,
                    "Vie" => Risque::BRANCHE_VIE,
                ],
            ])
            ->add('imposable', ChoiceType::class, [
                'label' => "Ce risque est-il imposable?",
                'help' => "Oui, si les taxes doivent être chargées.",
                'expanded' => true,
                'choices'  => [
                    "Oui" => true,
                    "Non" => false,
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
            'data_class' => Risque::class,
        ]);
    }
}
