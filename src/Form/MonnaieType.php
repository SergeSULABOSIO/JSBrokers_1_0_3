<?php

namespace App\Form;

use App\Constantes\Constante;
use App\Entity\Monnaie;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;

class MonnaieType extends AbstractType
{

    public function __construct(
        private Constante $constante
    )
    {
        
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', TextType::class, [
                'label' => "currency_form_label_name",
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
            ->add('tauxusd', NumberType::class, [
                'label' => "Taux de change par rapport au dollars ($)",
                'help' => "Si cette monnaie a le même taux que le dollar Américain (soit 1 unité de cette monnaie est égale à 1 $), alors tapez juste le chiffre '1' dans ce champ.",
                'attr' => [
                    'placeholder' => "Taux en USD",
                ],
            ])
            ->add('fonction', ChoiceType::class, [
                'label' => "Fonction de la monnaie",
                'expanded' => true,
                'choices'  => $this->constante->getTabFonctionsMonnaies(),
            ])
            ->add('locale', ChoiceType::class, [
                'label' => "Est-elle une monnaie locale?",
                'expanded' => true,
                'choices'  => $this->constante->getTabIsMonnaieLocale(),
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
