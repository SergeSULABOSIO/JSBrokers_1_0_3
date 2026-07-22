<?php

namespace App\Form;

use App\DTO\TokenPurchaseDTO;
use App\Services\ServiceNombres;
use App\Token\ParametresTokenService;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;

class TokenPurchaseType extends AbstractType
{
    public function __construct(
        private ParametresTokenService $parametres,
        private ServiceNombres $serviceNombres,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // Libellés des paquets : « Intermédiaire — 10 000 tokens (10 $) ». Le nom
        // d'affichage est le `label` éditable du paquet, avec repli sur ucfirst(clé)
        // pour les paquets historiques qui n'en ont pas. Le volume suit la notation
        // de la langue active (10 000 en français, 10,000 en anglais).
        $choices = [];
        foreach ($this->parametres->packs() as $key => $pack) {
            $nom = $pack['label'] ?? ucfirst($key);
            $label = $nom . ' — ' . $this->serviceNombres->format($pack['tokens'])
                . ' tokens (' . $pack['price'] . ' $)';
            $choices[$label] = $key;
        }

        $builder
            ->add('pack', ChoiceType::class, [
                'label'    => 'token_buy.pack',
                'choices'  => $choices,
                'expanded' => true,
            ])
            // `data-icon` : alias d'icône incrustée par le thème console/_form_theme,
            // exactement comme l'éditeur de tarif en Console (champ continu, icône grise
            // à gauche). cf. IconCanvasProvider pour la table des alias.
            ->add('cardHolder', TextType::class, [
                'label'      => 'token_buy.card_holder',
                'empty_data' => '',
                'attr'       => ['autocomplete' => 'cc-name', 'placeholder' => 'token_buy.card_holder_ph', 'data-icon' => 'utilisateur'],
            ])
            ->add('cardNumber', TextType::class, [
                'label'      => 'token_buy.card_number',
                'empty_data' => '',
                'attr'       => ['autocomplete' => 'cc-number', 'inputmode' => 'numeric', 'placeholder' => '4242 4242 4242 4242', 'data-icon' => 'compte-bancaire'],
            ])
            ->add('expiry', TextType::class, [
                'label'      => 'token_buy.expiry',
                'empty_data' => '',
                'attr'       => ['autocomplete' => 'cc-exp', 'placeholder' => 'MM/AA', 'data-icon' => 'action:renew'],
            ])
            ->add('cvc', TextType::class, [
                'label'      => 'token_buy.cvc',
                'empty_data' => '',
                'attr'       => ['autocomplete' => 'cc-csc', 'inputmode' => 'numeric', 'placeholder' => '123', 'data-icon' => 'action:password'],
            ])
            // Code de réduction OPTIONNEL : laissé vide, l'achat se déroule au plein tarif.
            ->add('couponCode', TextType::class, [
                'label'      => 'token_buy.coupon',
                'required'   => false,
                'empty_data' => '',
                'attr'       => ['placeholder' => 'token_buy.coupon_ph', 'autocapitalize' => 'characters', 'data-icon' => 'offre'],
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
