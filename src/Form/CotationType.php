<?php

namespace App\Form;

use App\Entity\Cotation;
use App\Services\FormListenerFactory;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;

class CotationType extends AbstractType
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
            ->add('duree', NumberType::class, [
                'label' => "Durée (en mois)",
                'grouping' => true,
                'attr' => [
                    'placeholder' => "Durée en mois",
                ],
            ])
            ->add('assureur', AssureurAutocompleteField::class, [
                'label' => "Assureur",
                'placeholder' => "Sélectionnez un assureur",
            ])
            ->add('chargements', CollectionType::class, [
                'label' => "Composition de la prime d'assurance",
                'entry_type' => ChargementPourPrimeType::class,
                'by_reference' => false,
                'allow_add' => true,
                'allow_delete' => true,
                'entry_options' => [
                    'label' => false,
                ],
                'mapped' => false,
            ]);

        $builder
            ->add('revenus', CollectionType::class, [
                'label' => "Revenus",
                'entry_type' => RevenuPourCourtierType::class,
                'by_reference' => false,
                'allow_add' => true,
                'allow_delete' => true,
                'entry_options' => [
                    'label' => false,
                ],
                'mapped' => false,
            ]);

        $builder
            ->add('tranches', CollectionType::class, [
                'label' => "Tranches",
                'entry_type' => TrancheType::class,
                'by_reference' => false,
                'allow_add' => true,
                'allow_delete' => true,
                'entry_options' => [
                    'label' => false,
                ],
                'mapped' => false,
            ])
            ->add('documents', CollectionType::class, [
                'label' => "Documents",
                'entry_type' => DocumentType::class,
                'by_reference' => false,
                'allow_add' => true,
                'allow_delete' => true,
                'entry_options' => [
                    'label' => false,
                ],
                'mapped' => false,
            ])

            ->add('taches', CollectionType::class, [
                'label' => "Tâches",
                'entry_type' => TacheType::class,
                'by_reference' => false,
                'allow_add' => true,
                'allow_delete' => true,
                'entry_options' => [
                    'label' => false,
                ],
                'mapped' => false,
            ])

            ->add('avenants', CollectionType::class, [
                'label' => "Avenants",
                'entry_type' => AvenantType::class,
                'by_reference' => false,
                'allow_add' => true,
                'allow_delete' => true,
                'entry_options' => [
                    'label' => false,
                ],
                'mapped' => false,
            ])

            // ->addEventListener(FormEvents::POST_SUBMIT, $this->ecouteurFormulaire->setUtilisateur())
            ->addEventListener(FormEvents::POST_SUBMIT, $this->ecouteurFormulaire->timeStamps())
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Cotation::class,
            'csrf_protection' => false,
            'allow_extra_fields' => true,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}
