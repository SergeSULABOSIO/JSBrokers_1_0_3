<?php

namespace App\Form;

use App\Entity\Invite;
use App\Entity\Tranche;
use App\Entity\Cotation;
use App\Entity\FactureCommission;
use App\Services\FormListenerFactory;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\PercentType;

class TrancheType extends AbstractType
{
    public function __construct(
        private FormListenerFactory $ecouteurFormulaire,
        private TranslatorInterface $translatorInterface
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
            ->add('montantFlat', MoneyType::class, [
                'label' => "Montant fixe",
                'currency' => "USD",
                'grouping' => true,
                'attr' => [
                    'placeholder' => "Montant fixe",
                ],
            ])
            ->add('pourcentage', PercentType::class, [
                'label' => "Pourcentage",
                'scale' => 3,
                'attr' => [
                    'placeholder' => "Portion",
                ],
            ])
            ->add('payableAt', DateTimeType::class, [
                'label' => "Date d'effet",
                'widget' => 'single_text',
            ])
            // ->add('createdAt', DateTimeImmutable::class, [
            //     'widget' => 'single_text',
            // ])
            // ->add('updatedAt', null, [
            //     'widget' => 'single_text',
            // ])
            // ->add('invite', EntityType::class, [
            //     'class' => Invite::class,
            //     'choice_label' => 'id',
            // ])
            // ->add('cotation', EntityType::class, [
            //     'class' => Cotation::class,
            //     'choice_label' => 'id',
            // ])
            // ->add('factureCommission', EntityType::class, [
            //     'class' => FactureCommission::class,
            //     'choice_label' => 'id',
            // ])
            ->add('enregistrer', SubmitType::class, [
                'label' => "Enregistrer",
                'attr' => [
                    'class' => "btn btn-secondary",
                ],
            ])
            // ->addEventListener(FormEvents::POST_SUBMIT, $this->ecouteurFormulaire->setUtilisateur())
            ->addEventListener(FormEvents::POST_SUBMIT, $this->ecouteurFormulaire->timeStamps())
        
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Tranche::class,
        ]);
    }
}
