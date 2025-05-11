<?php

namespace App\Form;

use App\Entity\Note;
use App\Entity\Client;
use App\Entity\Assureur;
use App\Entity\Partenaire;
use App\Entity\CompteBancaire;
use App\Entity\AutoriteFiscale;
use App\Services\ServiceMonnaies;
use App\Services\FormListenerFactory;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;

class NoteType extends AbstractType
{
    private string $helpArticle = "";
    private string $helpAssureur = "";
    private string $helpClient = "";
    private string $helppartenaire = "";
    private string $helpautorite = "";

    public function __construct(
        private FormListenerFactory $ecouteurFormulaire,
        private TranslatorInterface $translatorInterface,
        private ServiceMonnaies $serviceMonnaies,
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
            ->add('reference', TextType::class, [
                'required' => false,
                'disabled' => true,
                'label' => "Référence",
                'attr' => [
                    'placeholder' => "Référence",
                ],
            ])
            ->add('description', TextareaType::class, [
                'label' => "Description",
                'attr' => [
                    'placeholder' => "Description",
                ],
            ])
            ->add('type', ChoiceType::class, [
                'label' => "Type",
                'required' => true,
                'expanded' => false,
                'choices'  => [
                    // "Null" => Note::TYPE_NULL,
                    "Débit" => Note::TYPE_NOTE_DE_DEBIT,
                    "Crédit" => Note::TYPE_NOTE_DE_CREDIT,
                ]
            ])
            ->add('addressedTo', ChoiceType::class, [
                'label' => "Destination",
                'required' => true,
                'expanded' => false,
                'choices'  => [
                    // "Null" => Note::TO_NULL,
                    "Le client" => Note::TO_CLIENT,
                    "L'assureur" => Note::TO_ASSUREUR,
                    "L'intermédiaire" => Note::TO_PARTENAIRE,
                    "L'autorité fiscale" => Note::TO_AUTORITE_FISCALE,
                ]
            ])
            ->add('signedBy', TextType::class, [
                'label' => "Signataire",
                'attr' => [
                    'placeholder' => "Nom du signataire",
                ],
            ])
            ->add('titleSignedBy', TextType::class, [
                'label' => "Titre du signataire",
                'attr' => [
                    'placeholder' => "Titre du signataire",
                ],
            ])
            //champ non mappé
            ->add('montantDue', MoneyType::class, [
                'label' => "Montant dû",
                'currency' => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                'grouping' => true,
                'mapped' => false,
                'disabled' => true,
                'attr' => [
                    'placeholder' => "Montant dû",
                ],
            ])
            //champ non mappé
            ->add('montantPaye', MoneyType::class, [
                'label' => "Montant payé",
                'currency' => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                'grouping' => true,
                'mapped' => false,
                'disabled' => true,
                'attr' => [
                    'placeholder' => "Montant payé",
                ],
            ])
            //champ non mappé
            ->add('montantSolde', MoneyType::class, [
                'label' => "Solde restant dû",
                'currency' => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                'grouping' => true,
                'mapped' => false,
                'disabled' => true,
                'attr' => [
                    'placeholder' => "Solde restant dû",
                ],
            ])

        ;

        /**
         * @var Note $note
         */
        $note = $options['note'];
        if ($note != null) {
            if ($note->getId() != null) {
                $builder
                    ->add('articles', CollectionType::class, [
                        'label' => "Articles",
                        'help' => $this->helpArticle,
                        'entry_type' => ArticleType::class,
                        'by_reference' => false,
                        'allow_add' => false,
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
                                'tailleMax' => 0,
                            ]),
                        ],
                    ]);
            }
        }

        $builder
            ->add('paiements', CollectionType::class, [
                'label' => "Paiements",
                'help' => "Les paiements relatives à cette note.",
                'entry_type' => PaiementType::class,
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
                        'icone' => "paiement",
                        'dossieractions' => 0,  //1=On doit chercher l'icone "role" dans le dossier ICONES/ACTIONS, sinon on la chercher dans le dossier racine càd le dossier ICONES (le dossier racime)
                        'tailleMax' => 12,
                    ]),
                ],
            ])
            ->add('assureur', AssureurAutocompleteField::class, [
                'label' => "Assureur",
                'help' => $this->helpAssureur,
                'class' => Assureur::class,
                'required' => false,
                'choice_label' => 'nom',
            ])
            ->add('client', ClientAutocompleteField::class, [
                'label' => "Client",
                'help' => $this->helpClient,
                'class' => Client::class,
                'required' => false,
                'choice_label' => 'nom',
            ])
            ->add('partenaire', PartenaireAutocompleteField::class, [
                'label' => "Intermédiaire",
                'help' => $this->helppartenaire,
                'class' => Partenaire::class,
                'required' => false,
                'choice_label' => 'nom',
            ])
            ->add('sentAt', DateTimeType::class, [
                'label' => "Date de soumission",
                'widget' => 'single_text',
            ])
            ->add('autoritefiscale', AutoriteFiscaleAutocompleteField::class, [
                'label' => "Autorité fiscale",
                'help' => $this->helpautorite,
                'class' => AutoriteFiscale::class,
                'required' => false,
                'choice_label' => 'nom',
            ])
            ->add('comptes', CompteBancaireAutocompleteField::class, [
                'label' => "Comptes bancaires",
                'help' => "Comptes bancaires auxquels vous désirez vous faire payés.",
                'attr' => [
                    'placeholder' => "Séléctionner le compte",
                ],
                'class' => CompteBancaire::class,
                'required' => false,
                'multiple' => true,
                'choice_label' => 'nom',
            ])
            //Le bouton enregistrer
            ->add('enregistrer', SubmitType::class, [
                'label' => "ENREGISTRER",
                'attr' => [
                    'class' => "btn btn-secondary",
                ],
            ])
            //Le bouton enregistrer
            ->add('ajouterarticles', SubmitType::class, [
                'label' => "AJOUTER LES ARTICLES",
                'attr' => [
                    'class' => "btn btn-primary",
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Note::class,
            "idNote" => -1,
            "note" => null,
            'parent_object' => null, // l'objet parent
        ]);
    }
}
