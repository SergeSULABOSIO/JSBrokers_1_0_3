<?php

namespace App\Form;

use App\Entity\Tranche;
use App\Services\FormListenerFactory;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\PercentType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;

class TrancheType extends AbstractType
{
    public function __construct(
        private FormListenerFactory $ecouteurFormulaire
    ) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', TextType::class, [
                'label' => "Nom",
                'attr' => [
                    'placeholder' => "Nom",
                ],
            ])
            ->add('modeCalcul', ChoiceType::class, [
                'label' => "Mode de calcul",
                'mapped' => false, // Ce champ n'est pas lié directement à l'entité
                'expanded' => true,
                'data' => 'pourcentage', // Valeur par défaut pour l'initialisation
                'choices' => [
                    'Pourcentage' => 'pourcentage',
                    'Montant Fixe' => 'montant_fixe',
                ],
                'label_html' => true,
                'choice_label' => function ($choice, $key, $value) {
                    if ($value === 'pourcentage') return '<div><strong>Pourcentage</strong><div class="text-muted small">Calculé sur la prime totale.</div></div>';
                    if ($value === 'montant_fixe') return '<div><strong>Montant Fixe</strong><div class="text-muted small">Valeur forfaitaire définie.</div></div>';
                    return $key;
                },
            ])
            ->add('payableAt', DateTimeType::class, [
                // 'data' => $defautDateA,
                'label' => "Date d'effet",
                'widget' => 'single_text',
            ]) //
            ->add('echeanceAt', DateTimeType::class, [
                // 'data' => $defautDateB,
                'label' => "Echéance",
                'widget' => 'single_text',
            ])
            ->add('pourcentage', PercentType::class, [
                'label' => "Pourcentage",
                'required' => false,
                'scale' => 2,
                'html5' => true,
                'attr' => [
                    'placeholder' => "Portion",
                    'step' => 0.01,
                    'min' => 0,
                ],
            ])
            ->add('montantFlat', MoneyType::class, [
                'label' => "Montant fixe",
                'required' => false,
                'currency' => "USD",
                'grouping' => true,
                'attr' => [
                    'placeholder' => "Montant fixe",
                ],
            ])
            ->addEventListener(FormEvents::POST_SUBMIT, $this->ecouteurFormulaire->timeStamps())
            ->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) {
                $tranche = $event->getData();
                $form = $event->getForm();

                // Si un montant fixe est déjà défini (mode édition), on bascule la sélection
                if ($tranche && $tranche->getMontantFlat() !== null && $tranche->getMontantFlat() > 0) {
                    $form->get('modeCalcul')->setData('montant_fixe');
                }
            })
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Tranche::class,
            'csrf_protection' => false,
            'allow_extra_fields' => true,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}
