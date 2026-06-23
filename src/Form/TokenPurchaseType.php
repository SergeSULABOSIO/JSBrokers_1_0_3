<?php

namespace App\Form;

use App\DTO\TokenPurchaseDTO;
use App\Token\ParametresTokenService;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;

class TokenPurchaseType extends AbstractType
{
    public function __construct(private ParametresTokenService $parametres)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // Libellés des paquets : « Intermédiaire — 10 000 tokens (10 $) ». Le nom
        // d'affichage est le `label` éditable du paquet, avec repli sur ucfirst(clé)
        // pour les paquets historiques qui n'en ont pas.
        $choices = [];
        foreach ($this->parametres->packs() as $key => $pack) {
            $nom = $pack['label'] ?? ucfirst($key);
            $label = $nom . ' — ' . number_format($pack['tokens'], 0, ',', ' ')
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
            // Code de réduction OPTIONNEL : laissé vide, l'achat se déroule au plein tarif.
            ->add('couponCode', TextType::class, [
                'label'      => 'token_buy.coupon',
                'required'   => false,
                'empty_data' => '',
                'attr'       => ['placeholder' => 'token_buy.coupon_ph', 'autocapitalize' => 'characters'],
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
