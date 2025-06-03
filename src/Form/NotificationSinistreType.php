<?php

namespace App\Form;

use App\Entity\Client;
use App\Entity\Invite;
use App\Entity\Risque;
use App\Entity\Assureur;
use App\Entity\NotificationSinistre;
use App\Services\FormListenerFactory;
use Doctrine\DBAL\Types\DateTimeType;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\AbstractType;
use Doctrine\DBAL\Types\DateTimeImmutableType;
use Eckinox\TinymceBundle\Form\Type\TinymceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;

class NotificationSinistreType extends AbstractType
{
    public function __construct(
        private FormListenerFactory $ecouteurFormulaire,
        private TranslatorInterface $translatorInterface
    ) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('referencePolice', TextType::class, [
                'help' => "Vous devez fournir la référence de la police d'assurance",
                'label' => "Référence de la police",
                'required' => true,
                'attr' => [
                    'placeholder' => "Réf. Police",
                ],
            ])
            ->add('assureur', EntityType::class, [
                'help' => "L'assureur concerné par le sinistre",
                'label' => "Assureur",
                'autocomplete' => true,
                'required' => false,
                'class' => Assureur::class,
                'choice_label' => 'nom',
                'attr' => [
                    'placeholder' => "Séléctionnez l'assureur",
                ],
            ])
            ->add('referenceSinistre', TextType::class, [
                'label' => "Référence du sinistre",
                'help' => "Si vous n'avez pas encore de numéro sinistre, veuillez sauter ce champ.",
                'required' => false,
                'attr' => [
                    'placeholder' => "Réf. Sinistre",
                ],
            ])
            // ->add('descriptionDeFait', TextareaType::class, [
            //     'label' => "Description des faits",
            //     'required' => true,
            //     'attr' => [
            //         'placeholder' => "Description",
            //     ],
            // ])
            // ->add('descriptionDeFait', TinymceType::class, [
            //     'label' => "Description des faits",
            //     'required' => true,
            //     "attr" => [
            //         'placeholder' => "Description",
            //         "toolbar" => "bold italic underline | bullist numlist",
            //     ],
            // ])

            ->add('descriptionVictimes', TextareaType::class, [
                'label' => "Description ou détails sur les victimes",
                'required' => true,
                'attr' => [
                    'placeholder' => "Victimes",
                ],
            ])
            ->add('notifiedAt', DateType::class, [
                'label' => "Date de la notification",
                'required' => true,
                'widget' => 'single_text',
            ])
            ->add('occuredAt', DateType::class, [
                'label' => "Date de la survénance",
                'required' => true,
                'widget' => 'single_text',
            ])
            ->add('lieu', TextType::class, [
                'label' => "Lieu de survénance",
                'required' => true,
                'attr' => [
                    'placeholder' => "Lieu",
                ],
            ])
            ->add('dommage', MoneyType::class, [
                'label' => "Valeur de la perte",
                'help' => "Il s'agit d'une estimation chiffrée du coût de reparation des dégats causés et/ou subis lors de l'évènement survenu.",
                'currency' => "USD",
                'required' => false,
                'grouping' => true,
                'attr' => [
                    'placeholder' => "Dommage",
                ],
            ])
            ->add('evaluationChiffree', MoneyType::class, [
                'label' => "Evaluation chiffrée",
                'help' => "Il s'agit d'une confirmation chiffré du dommage après évaluation.",
                'currency' => "USD",
                'grouping' => true,
                'required' => false,
                'attr' => [
                    'placeholder' => "Evaluation ciffrée",
                ],
            ])
            ->add('assure', EntityType::class, [
                'label' => "Assuré(e) ou Client(e)",
                'autocomplete' => true,
                'required' => false,
                'class' => Client::class,
                'choice_label' => 'nom',
            ])
            ->add('risque', EntityType::class, [
                'label' => "Couverture d'assurance concernée",
                'autocomplete' => true,
                'required' => false,
                'class' => Risque::class,
                'choice_label' => 'nomComplet',
            ])
            ->add('contacts', CollectionType::class, [
                'label' => "Liste des personnes clées, à contacter pour tout ce qui concerne cette reclamation.",
                'entry_type' => ContactType::class,
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
                        'icone' => "contact",
                        'dossieractions' => 0,  //1=On doit chercher l'icone "role" dans le dossier ICONES/ACTIONS, sinon on la chercher dans le dossier racine càd le dossier ICONES (le dossier racime)
                        'tailleMax' => 10,
                    ]),
                ],
            ])
            ->add('pieces', CollectionType::class, [
                'label' => "Liste des documents relatifs au sinistre reclamé.",
                'entry_type' => PieceSinistreType::class,
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
                        'tailleMax' => 20,
                    ]),
                ],
            ])
            ->add('offreIndemnisationSinistres', CollectionType::class, [
                'label' => "Liste d'offres d'indemnisations.",
                'entry_type' => OffreIndemnisationSinistreType::class,
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
                        'icone' => "offreindemnisation",
                        'dossieractions' => 0,  //1=On doit chercher l'icone "role" dans le dossier ICONES/ACTIONS, sinon on la chercher dans le dossier racine càd le dossier ICONES (le dossier racime)
                        'tailleMax' => 20,
                    ]),
                ],
            ])
            ->add('taches', CollectionType::class, [
                'label' => "Liste des taches",
                'help' => "Tâches ou actions à exécuter par les utilisateurs dans le cadre de cette notification.",
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
                        'tailleMax' => 20,
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
            'data_class' => NotificationSinistre::class,
            'parent_object' => null, // l'objet parent
        ]);
    }
}
