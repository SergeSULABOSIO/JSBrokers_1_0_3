<?php

namespace App\Form;

use App\Entity\Piste;
use App\Entity\Partenaire;
use App\Services\FormListenerFactory;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;

class PisteType extends AbstractType
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
                    'placeholder' => "Nom de la piste",
                ],
            ])
            ->add('client', ClientAutocompleteField::class, [
                'label' => "Client / Assurée ou Prospect",
                'placeholder' => "Sélectionnez un client ou un prospect",
            ])
            ->add('typeAvenant', ChoiceType::class, [
                'label' => "Type d'Avenant",
                'expanded' => true,
                'label_html' => true,
                'required' => true,
                'choices'  => [
                    "Souscription"      => Piste::AVENANT_SOUSCRIPTION,
                    "Incorporation"     => Piste::AVENANT_INCORPORATION,
                    "Prorogation"       => Piste::AVENANT_PROROGATION,
                    "Annulation"        => Piste::AVENANT_ANNULATION,
                    "Renouvellement"    => Piste::AVENANT_RENOUVELLEMENT,
                    "Résiliation"       => Piste::AVENANT_RESILIATION,
                ],
                'choice_label' => function ($choice, $key, $value) {
                    $desc = match ($choice) {
                        Piste::AVENANT_SOUSCRIPTION => "Création d'une nouvelle police d'assurance.",
                        Piste::AVENANT_INCORPORATION => "Ajout d'une garantie ou d'un assuré.",
                        Piste::AVENANT_PROROGATION => "Prolongation de la durée de la police.",
                        Piste::AVENANT_ANNULATION => "Annulation complète de la police.",
                        Piste::AVENANT_RENOUVELLEMENT => "Renouvellement de la police à son échéance.",
                        Piste::AVENANT_RESILIATION => "Rupture du contrat par l'une des parties.",
                        default => ""
                    };
                    return '<div><strong>' . $key . '</strong><div class="text-muted small">' . $desc . '</div></div>';
                },
            ])
            ->add('renewalCondition', ChoiceType::class, [
                'label' => "Type d'assurance.",
                'help' => "Type d'assurance selon les conditions de renouvellement.",
                'expanded' => true,
                'label_html' => true,
                'required' => true,
                'choices'  => [
                    "A terme renouvellable"           => Piste::RENEWAL_CONDITION_RENEWABLE,
                    "Avec ajustement"                 => Piste::RENEWAL_CONDITION_ADJUSTABLE_AT_EXPIRY,
                    "Temporaire non renouvellable"    => Piste::RENEWAL_CONDITION_ONCE_OFF_AND_EXTENDABLE,
                ],
                'choice_label' => function ($choice, $key, $value) {
                    $desc = match($choice) {
                        Piste::RENEWAL_CONDITION_RENEWABLE => "Contrat renouvelable tacitement.",
                        Piste::RENEWAL_CONDITION_ADJUSTABLE_AT_EXPIRY => "Prime ajustable à la fin de la période.",
                        Piste::RENEWAL_CONDITION_ONCE_OFF_AND_EXTENDABLE => "Couverture unique, extension possible.",
                        default => ""
                    };
                    return '<div><strong>' . $key . '</strong><div class="text-muted small">' . $desc . '</div></div>';
                },
            ])

            ->add('exercice', NumberType::class, [
                'label' => "Exercice",
                'grouping' => false,
                'attr' => [
                    'placeholder' => "Année",
                ],
            ])
            ->add('primePotentielle', MoneyType::class, [
                'label' => "Prime Potentielle",
                'currency' => "USD",
                'grouping' => true,
                'attr' => [
                    'placeholder' => "Prime potentielle",
                ],
            ])
            ->add('commissionPotentielle', MoneyType::class, [
                'label' => "Commission Potentielle",
                'currency' => "USD",
                'grouping' => true,
                'attr' => [
                    'placeholder' => "Commission potentielle",
                ],
            ])
            ->add('descriptionDuRisque', TextareaType::class, [
                'label' => "Description du risque",
                'attr' => [
                    'placeholder' => "Description du risque",
                ],
            ])
            ->add('risque', RisqueAutocompleteField::class, [
                'label' => "Couverture d'assurance",
                'required' => true,
                'placeholder' => 'Sélectionner un risque',
            ])
            ->add('partenaires', PartenaireAutocompleteField::class, [
                'required' => false,
                'label' => "Partenaires",
                'class' => Partenaire::class,
                'choice_label' => "nom",
                'multiple' => true,
                'expanded' => false,
                'by_reference' => false,
                'autocomplete' => true,
                'attr' => [
                    'placeholder' => "Les intermédiaires",
                ],
            ])
            ->add('conditionsPartageExceptionnelles', CollectionType::class, [
                'label' => "Liste des conditions spéciales de partage",
                'entry_type' => ConditionPartageType::class,
                'by_reference' => false,
                'allow_add' => true,
                'allow_delete' => true,
                'entry_options' => [
                    'label' => false,
                ],
                'mapped' => false,
            ])
            ->add('taches', CollectionType::class, [
                'label' => "Liste des tâches",
                'entry_type' => TacheType::class,
                'by_reference' => false,
                'allow_add' => true,
                'allow_delete' => true,
                'entry_options' => [
                    'label' => false,
                ],
                'mapped' => false,
            ])
            ->add('cotations', CollectionType::class, [
                'label' => "Liste des cotations",
                'entry_type' => CotationType::class,
                'by_reference' => false,
                'allow_add' => true,
                'allow_delete' => true,
                'entry_options' => [
                    'label' => false,
                ],
                'mapped' => false,
            ])
            ->add('documents', CollectionType::class, [
                'label' => "Liste des documents",
                'entry_type' => DocumentType::class,
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
            'data_class' => Piste::class,
            'csrf_protection' => false,
            'allow_extra_fields' => true,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}
