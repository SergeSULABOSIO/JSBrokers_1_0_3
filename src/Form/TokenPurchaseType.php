<?php

namespace App\Form;

use App\DTO\TokenPurchaseDTO;
use App\Token\TokenPricing;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;

class TokenPurchaseType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // Libellés des paquets : « Intermédiaire — 10 000 tokens (10 $) ».
        $choices = [];
        foreach (TokenPricing::PACKS as $key => $pack) {
            $label = ucfirst($key) . ' — ' . number_format($pack['tokens'], 0, ',', ' ')
                . ' tokens (' . $pack['price'] . ' $)';
            $choices[$label] = $key;
        }

        $builder
            ->add('pack', ChoiceType::class, [
                'label'    => 'token_buy.pack',
                'choices'  => $choices,
                'expanded' => true,
            ])
            ->add('cardHolder', TextType::class, [
                'label'      => 'token_buy.card_holder',
                'empty_data' => '',
                'attr'       => ['autocomplete' => 'cc-name', 'placeholder' => 'token_buy.card_holder_ph'],
            ])
            ->add('cardNumber', TextType::class, [
                'label'      => 'token_buy.card_number',
                'empty_data' => '',
                'attr'       => ['autocomplete' => 'cc-number', 'inputmode' => 'numeric', 'placeholder' => '4242 4242 4242 4242'],
            ])
            ->add('expiry', TextType::class, [
                'label'      => 'token_buy.expiry',
                'empty_data' => '',
                'attr'       => ['autocomplete' => 'cc-exp', 'placeholder' => 'MM/AA'],
            ])
            ->add('cvc', TextType::class, [
                'label'      => 'token_buy.cvc',
                'empty_data' => '',
                'attr'       => ['autocomplete' => 'cc-csc', 'inputmode' => 'numeric', 'placeholder' => '123'],
            ])
            // Le bouton de soumission est fourni par le template (style + icône).
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => TokenPurchaseDTO::class,
        ]);
    }
}
