<?php

namespace App\Form;

use App\Constantes\Constantes;
use App\Entity\Monnaie;
use App\Entity\Entreprise;
use Doctrine\DBAL\Types\FloatType;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;

class MonnaieType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', TextType::class, [
                'label' => "Intitulé de la monnaie",
                'attr' => [
                    'placeholder' => "Nom",
                ],
            ])
            ->add('code', TextType::class, [
                'label' => "Code / insigne de la monnaie",
                'attr' => [
                    'placeholder' => "Code",
                ],
            ])
            ->add('tauxusd', FloatType::class, [
                'label' => "Taux de change par rapport au dollars ($)",
                'attr' => [
                    'placeholder' => "Taux en USD",
                ],
            ])
            ->add('fonction', ChoiceType::class, [
                'label' => "Fonction de la monnaie",
                'choices'  => Constantes::TAB_MONNAIE_FONCTIONS,
            ])
            ->add('locale', ChoiceType::class, [
                'label' => "Est-elle une monnaie locale?",
                'choices'  => Constantes::TAB_MONNAIE_MONNAIE_LOCALE,
            ])
            // ->add('entreprise', EntityType::class, [
            //     'class' => Entreprise::class,
            //     'choice_label' => 'id',
            // ])
            //Le bouton d'enregistrement / soumission
            ->add('enregistrer', SubmitType::class, [
                'label' => "Enregistrer"
            ])
            // ->addEventListener(FormEvents::POST_SUBMIT, $this->ecouteurFormulaire->setUtilisateur())
            // ->addEventListener(FormEvents::POST_SUBMIT, $this->ecouteurFormulaire->timeStamps())
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Monnaie::class,
        ]);
    }
}
