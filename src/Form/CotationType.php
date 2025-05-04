<?php

namespace App\Form;

use App\Entity\Assureur;
use App\Entity\Cotation;
use App\Services\FormListenerFactory;
use App\Services\ServiceMonnaies;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;

class CotationType extends AbstractType
{
    public function __construct(
        private FormListenerFactory $ecouteurFormulaire,
        private TranslatorInterface $translatorInterface,
        private ServiceMonnaies $serviceMonnaies
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
            ->add('assureur', EntityType::class, [
                'class' => Assureur::class,
                'choice_label' => 'nom',
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
            ]);

        if ($options['cotation'] != null) {
            $builder
                //champ non mappé
                ->add('prime', MoneyType::class, [
                    'label' => "Prime TTC",
                    'currency' => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    'grouping' => true,
                    'help' => "La somme des chargements (prime nette, accessoires, tva, etc) ci-haut, payable par le client.",
                    'mapped' => false,
                    'disabled' => true,
                    'attr' => [
                        'placeholder' => "Prime totale",
                    ],
                ]);
        }


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
                'attr' => [
                    'data-controller' => 'form-collection-entites',
                    'data-form-collection-entites-data-value' => json_encode([
                        'addLabel' => $this->translatorInterface->trans("commom_add"),
                        'deleteLabel' => $this->translatorInterface->trans("commom_delete"),
                        'icone' => "revenu",
                        'dossieractions' => 0,  //1=On doit chercher l'icone "role" dans le dossier ICONES/ACTIONS, sinon on la chercher dans le dossier racine càd le dossier ICONES (le dossier racime)
                        'tailleMax' => 10,
                    ]),
                ],
            ]);

        if ($options['cotation'] != null) {
            $builder
                //champ non mappé
                ->add('commissionNette', MoneyType::class, [
                    'label' => "Commission totale ht",
                    'currency' => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    'grouping' => true,
                    'help' => "La somme des revenus ci-haut.",
                    'mapped' => false,
                    'disabled' => true,
                    'attr' => [
                        'placeholder' => "Commission totale ht",
                    ],
                ])
                //champ non mappé
                ->add('commissionNetteTva', MoneyType::class, [
                    'label' => "Taxes",
                    'currency' => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    'grouping' => true,
                    'mapped' => false,
                    'disabled' => true,
                    'attr' => [
                        'placeholder' => "Taxes",
                    ],
                ])
                //champ non mappé
                ->add('commissionTTC', MoneyType::class, [
                    'label' => "Commission TTC",
                    'currency' => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    'grouping' => true,
                    'mapped' => false,
                    'disabled' => true,
                    'attr' => [
                        'placeholder' => "Commission TTC",
                    ],
                ]);
        }


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
                'attr' => [
                    'data-controller' => 'form-collection-entites',
                    'data-form-collection-entites-data-value' => json_encode([
                        'addLabel' => $this->translatorInterface->trans("commom_add"),
                        'deleteLabel' => $this->translatorInterface->trans("commom_delete"),
                        'icone' => "tranche",
                        'dossieractions' => 0,  //1=On doit chercher l'icone "role" dans le dossier ICONES/ACTIONS, sinon on la chercher dans le dossier racine càd le dossier ICONES (le dossier racime)
                        'tailleMax' => 12,
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
                        'tailleMax' => 100,
                    ]),
                ],
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
                'attr' => [
                    'data-controller' => 'form-collection-entites',
                    'data-form-collection-entites-data-value' => json_encode([
                        'addLabel' => $this->translatorInterface->trans("commom_add"),
                        'deleteLabel' => $this->translatorInterface->trans("commom_delete"),
                        'icone' => "avenant",
                        'dossieractions' => 0,  //1=On doit chercher l'icone "role" dans le dossier ICONES/ACTIONS, sinon on la chercher dans le dossier racine càd le dossier ICONES (le dossier racime)
                        'tailleMax' => 1,
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
            'data_class' => Cotation::class,
            'cotation' => null,
            'parent_object' => null, // l'objet parent
        ]);
    }
}
