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
                    'placeholder' => "currency_form_label_name_placeholder",
                ],
            ])
            ->add('code', TextType::class, [
                'label' => "currency_form_code",
                'attr' => [
                    'placeholder' => "currency_form_code_placeholder",
                ],
            ])
            ->add('tauxusd', NumberType::class, [
                'label' => "currency_form_rate",
                'help' => "currency_form_rate_help",
                'attr' => [
                    'placeholder' => "currency_form_rate_place_holder",
                ],
            ])
            ->add('fonction', ChoiceType::class, [
                'label' => "currency_form_fonction",
                'expanded' => true,
                'choices'  => $this->constante->getTabFonctionsMonnaies(),
            ])
            ->add('locale', ChoiceType::class, [
                'label' => "currency_form_local",
                'expanded' => true,
                'choices'  => $this->constante->getTabIsMonnaieLocale(),
            ])
            //Le bouton d'enregistrement / soumission
            ->add('enregistrer', SubmitType::class, [
                'label' => "currency_form_save",
                'attr' => [
                    'class' => "btn btn-secondary",
                ],
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
