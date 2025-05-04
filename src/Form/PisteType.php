<?php

namespace App\Form;

use App\Entity\Piste;
use App\Entity\Client;
use App\Entity\Risque;
use App\Entity\Avenant;
use App\Entity\Partenaire;
use App\Services\FormListenerFactory;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;

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
                'required' => true,
                'class' => Client::class,
                'choice_label' => 'nom',
            ])
            ->add('typeAvenant', ChoiceType::class, [
                'label' => "Type d'Avenant",
                'expanded' => false,
                'choices'  => [
                    "SOUSCRIPTION"      => Piste::AVENANT_SOUSCRIPTION,
                    "INCORPORATION"     => Piste::AVENANT_INCORPORATION,
                    "PROROGATION"       => Piste::AVENANT_PROROGATION,
                    "ANNULATION"        => Piste::AVENANT_ANNULATION,
                    "RENOUVELLEMENT"    => Piste::AVENANT_RENOUVELLEMENT,
                    "RESILIATION"       => Piste::AVENANT_RESILIATION,
                ],
            ])

            ->add('renewalCondition', ChoiceType::class, [
                'label' => "Type d'assurance selon la condition de renouvellement",
                'expanded' => false,
                'required' => true,
                'choices'  => [
                    "ASSURANCE A TERME RENOUVELLABLE"           => Piste::RENEWAL_CONDITION_RENEWABLE,
                    "ASSURANCE AVEC AJUSTEMENT"                 => Piste::RENEWAL_CONDITION_ADJUSTABLE_AT_EXPIRY,
                    "ASSURANCE TEMPORAIRE NON RENOUVELLABLE"    => Piste::RENEWAL_CONDITION_ONCE_OFF_AND_EXTENDABLE,
                ],
            ])

            ->add('exercice', NumberType::class, [
                'label' => "Exercice comptable (année)",
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
            ->add('descriptionDuRisque', TextType::class, [
                'label' => "Description du risque",
                'attr' => [
                    'placeholder' => "Description du risque",
                ],
            ])
            ->add('risque', EntityType::class, [
                'label' => "Couverture d'assurance",
                'class' => Risque::class,
                'required' => false,
                'choice_label' => 'nomComplet',
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
                'label' => "Conditions spéciales de partage",
                'entry_type' => ConditionPartageType::class,
                'by_reference' => false,
                'allow_add' => true,
                'allow_delete' => true,
                'required' => false,
                'entry_options' => [
                    'label' => false,
                ],
                'attr' => [
                    'data-controller' => 'form-collection-entites',
                    'data-form-collection-entites-add-label-value' => $this->translatorInterface->trans("commom_add"), //'Ajouter',
                    'data-form-collection-entites-delete-label-value' => $this->translatorInterface->trans("commom_delete"),
                    'data-form-collection-entites-edit-label-value' => $this->translatorInterface->trans("commom_edit"),
                    'data-form-collection-entites-close-label-value' => $this->translatorInterface->trans("commom_close"),
                    'data-form-collection-entites-new-element-label-value' => $this->translatorInterface->trans("commom_new_element"),
                    'data-form-collection-entites-view-field-value' => "nom",
                ],
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
                'attr' => [
                    'data-controller' => 'form-collection-entites',
                    'data-form-collection-entites-data-value' => json_encode([
                        'addLabel' => $this->translatorInterface->trans("commom_add"),
                        'deleteLabel' => $this->translatorInterface->trans("commom_delete"),
                        'icone' => "tache",
                        'dossieractions' => 0,  //1=On doit chercher l'icone "role" dans le dossier ICONES/ACTIONS, sinon on la chercher dans le dossier racine càd le dossier ICONES (le dossier racime)
                        'tailleMax' => 10,
                    ]),
                ],
            ])
            ->add('cotations', CollectionType::class, [
                'label' => "Cotations",
                'entry_type' => CotationType::class,
                'by_reference' => false,
                'allow_add' => true,
                'allow_delete' => true,
                'entry_options' => [
                    'label' => false,
                ],
                'attr' => [
                    'data-controller' => 'form-collection-entites',
                    'data-form-collection-entites-data-value' => json_encode([
                        'addLabel' => $this->translatorInterface->trans("commom_add"),
                        'deleteLabel' => $this->translatorInterface->trans("commom_delete"),
                        'icone' => "cotation",
                        'dossieractions' => 0,  //1=On doit chercher l'icone "role" dans le dossier ICONES/ACTIONS, sinon on la chercher dans le dossier racine càd le dossier ICONES (le dossier racime)
                        'tailleMax' => 10,
                    ]),
                ],
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
                'attr' => [
                    'data-controller' => 'form-collection-entites',
                    'data-form-collection-entites-data-value' => json_encode([
                        'addLabel' => $this->translatorInterface->trans("commom_add"),
                        'deleteLabel' => $this->translatorInterface->trans("commom_delete"),
                        'icone' => "document",
                        'dossieractions' => 0,  //1=On doit chercher l'icone "role" dans le dossier ICONES/ACTIONS, sinon on la chercher dans le dossier racine càd le dossier ICONES (le dossier racime)
                        'tailleMax' => 10,
                    ]),
                ],
            ])
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
            'data_class' => Piste::class,
            'parent_object' => null, // l'objet parent
        ]);
    }
}
