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
                'required' => true,
                'class' => Client::class,
                'choice_label' => 'nom',
            ])
            ->add('typeAvenant', ChoiceType::class, [
                'label' => "Type d'Avenant",
                'expanded' => false,
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
                    // Vous pouvez personnaliser les descriptions ici
                    return '<div><strong>' . $key . '</strong></div>';
                },
            ])
            ->add('renewalCondition', ChoiceType::class, [
                'label' => "Type d'assurance.",
                'help' => "Type d'assurance selon les conditions de renouvellement.",
                'expanded' => false,
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
                'required' => false,
                'entry_options' => [
                    'label' => false,
                ],
                'attr' => [
                    'data-controller' => 'form-collection-entites',
                    'data-form-collection-entites-data-value' => json_encode([
                        'addLabel' => $this->translatorInterface->trans("commom_add"),
                        'deleteLabel' => $this->translatorInterface->trans("commom_delete"),
                        'icone' => "partenaire",
                        'dossieractions' => 0,  //1=On doit chercher l'icone "role" dans le dossier ICONES/ACTIONS, sinon on la chercher dans le dossier racine càd le dossier ICONES (le dossier racime)
                        'tailleMax' => 1,
                    ]),
                ],
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
                'label' => "Liste des cotations",
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
                'label' => "Liste des documents",
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
