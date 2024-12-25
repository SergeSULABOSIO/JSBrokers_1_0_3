<?php

namespace App\Form;

use App\Entity\Invite;
use DateTimeImmutable;
use App\Entity\Avenant;
use App\Entity\Cotation;
use App\Services\FormListenerFactory;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;

class AvenantType extends AbstractType
{
    public function __construct(
        private FormListenerFactory $ecouteurFormulaire,
        private TranslatorInterface $translatorInterface
    ) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('referencePolice', TextType::class, [
                'required' => true,
                'label' => "Référence de la police",
                'attr' => [
                    'placeholder' => "Référence de la police",
                ],
            ])
            ->add('description', TextType::class, [
                'required' => false,
                'label' => "Description",
                'attr' => [
                    'placeholder' => "Description",
                ],
            ])
            ->add('type', ChoiceType::class, [
                'label' => "Type d'Avenant",
                'expanded' => true,
                'choices'  => [
                    "SOUSCRIPTION"      => Avenant::AVENANT_SOUSCRIPTION,
                    "INCORPORATION"     => Avenant::AVENANT_INCORPORATION,
                    "PROROGATION"       => Avenant::AVENANT_PROROGATION,
                    "ANNULATION"        => Avenant::AVENANT_ANNULATION,
                    "RENOUVELLEMENT"    => Avenant::AVENANT_RENOUVELLEMENT,
                    "RESILIATION"       => Avenant::AVENANT_RESILIATION,
                ],
            ])
            ->add('startingAt', DateTimeType::class, [
                'label' => "Date début",
                'widget' => 'single_text',
            ])
            ->add('endingAt', DateTimeType::class, [
                'label' => "Echéance",
                'widget' => 'single_text',
            ])
            // ->add('createdAt', null, [
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
            //Le bouton d'enregistrement / soumission
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
            'data_class' => Avenant::class,
        ]);
    }
}
