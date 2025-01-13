<?php

namespace App\Form;

use App\Entity\Note;
use App\Entity\Client;
use App\Entity\Invite;
use App\Entity\Assureur;
use App\Entity\AutoriteFiscale;
use App\Entity\Partenaire;
use App\Entity\CompteBancaire;
use App\Services\FormListenerFactory;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
// use Symfony\Component\Form\Extension\Core\Type\CollectionType;

class NoteType extends AbstractType
{
    private string $helpArticle = "";
    private string $labelbtSubmit = "PAGE SUIVANTE";
    private string $labelbtDelete = "SUPPRIMER";
    private string $labelbtBack = "PAGE PRECEDENTE";
    private string $helpAssureur = "";
    private string $helpClient = "";
    private string $helppartenaire = "";
    private string $helpautorite = "";

    public function __construct(
        private FormListenerFactory $ecouteurFormulaire,
        private TranslatorInterface $translatorInterface
    ) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if ($options["page"] != -1) {
            if ($options["type"] != -1 && $options["addressedTo"] != -1) {
                $this->labelbtSubmit = match (true) {
                    $options['page'] == $options['pageMax'] => "TERMINER",
                    default => "PAGE SUIVANTE",
                };
            }
        }

        if ($options['page'] == 1) {
            //PAGE 1
            $this->buildPageA($builder, $options);
        }

        if ($options['page'] == 2) {
            // dd($options['page']);
            //PAGE 2
            $this->buildPageB($builder, $options);
        }

        //BAS DE PAGE
        if ($options['page'] > 1) {
            $builder
                //Le bouton précédent
                ->add('precedent', SubmitType::class, [
                    'label' => $this->labelbtBack,
                    'attr' => [
                        'class' => "btn btn-secondary",
                    ],
                ]);
        }
        $builder
            //Le bouton suivant
            ->add('suivant', SubmitType::class, [
                'label' => $this->labelbtSubmit,
                'attr' => [
                    'class' => "btn btn-secondary",
                ],
            ]);
        if ($options['idNote'] != -1) {
            $builder
                //Le bouton suppression
                ->add('delete', SubmitType::class, [
                    'label' => $this->labelbtDelete,
                    'attr' => [
                        'class' => "btn btn-danger",
                    ],
                ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Note::class,
            "page" => -1,
            "idNote" => -1,
            "pageMax" => -100,
            "type" => -1,
            "addressedTo" => -1,
        ]);
    }

    private function buildPageA(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('reference', TextType::class, [
                'required' => false,
                'label' => "Référence",
                'attr' => [
                    'placeholder' => "Référence",
                ],
            ])
            ->add('type', ChoiceType::class, [
                'label' => "Type",
                'expanded' => true,
                'choices'  => [
                    "Null" => Note::TYPE_NULL,
                    "Note de débit" => Note::TYPE_NOTE_DE_DEBIT,
                    "Note de crédit" => Note::TYPE_NOTE_DE_CREDIT,
                ]
            ])
            ->add('addressedTo', ChoiceType::class, [
                'label' => "A destination",
                'expanded' => true,
                'choices'  => [
                    "Null" => Note::TO_NULL,
                    "Du client" => Note::TO_CLIENT,
                    "De l'assureur" => Note::TO_ASSUREUR,
                    "De l'intermédiaire" => Note::TO_PARTENAIRE,
                    "De l'autorité fiscale" => Note::TO_AUTORITE_FISCALE,
                ]
            ])
            ->add('nom', TextType::class, [
                'label' => "Nom",
                'attr' => [
                    'placeholder' => "Nom",
                ],
            ])
            ->add('description', TextType::class, [
                'label' => "Description",
                'attr' => [
                    'placeholder' => "Description",
                ],
            ])

            ->add('paiements', CollectionType::class, [
                'label' => "Paiements",
                'help' => "Les paiements relatives à cette notes.",
                'entry_type' => PaiementType::class,
                'by_reference' => false,
                'allow_add' => true,
                'allow_delete' => true,
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
                    'data-form-collection-entites-view-field-value' => "description",
                ],
            ])
        ;
    }

    private function buildPageB(FormBuilderInterface $builder, array $options): void
    {
        //Construction selon la destinationde la note
        switch ($options['addressedTo']) {
            case Note::TO_ASSUREUR:
                $this->helpArticle = "Les articles sont les tranches depuis lesquelles les commissions seront extraites.";
                if ($options['type'] == Note::TYPE_NOTE_DE_DEBIT) {
                    $this->helpAssureur = "L'assureur à qui vous désirez addreser cette note de débit pour la collecte de vos commissions.";
                } else {
                    $this->helpAssureur = "L'assureur à qui vous désirez addreser cette note de crédit pour remboursement quelconque.";
                }
                $builder->add('assureur', AssureurAutocompleteField::class, [
                    'label' => "Assureur",
                    'help' => $this->helpAssureur,
                    'class' => Assureur::class,
                    'required' => false,
                    'choice_label' => 'nom',
                ]);
                break;

            case Note::TO_CLIENT:
                $this->helpArticle = "Les articles sont les tranches depuis lesquelles les commissions seront extraites.";
                if ($options['type'] == Note::TYPE_NOTE_DE_DEBIT) {
                    $this->helpClient = "Le client à qui vous désirez addreser cette note de débit pour la collecte de vos commissions.";
                } else {
                    $this->helpClient = "Le client à qui vous désirez addreser cette note de crédit pour remboursement quelconque.";
                }
                $builder->add('client', ClientAutocompleteField::class, [
                    'label' => "Client",
                    'help' => $this->helpClient,
                    'class' => Client::class,
                    'required' => false,
                    'choice_label' => 'nom',
                ]);
                break;

            case Note::TO_PARTENAIRE:
                if ($options['type'] == Note::TYPE_NOTE_DE_DEBIT) {
                    $this->helppartenaire = "L'intermédiaire à qui vous désirez addreser cette note de débit pour la collecte de vos commissions.";
                } else {
                    $this->helpArticle = "Les articles sont les tranches depuis lesquelles les retrocommissions seront extraites.";
                    $this->helppartenaire = "L'intermédiaire à qui vous désirez addreser cette note de crédit pour retrocéssion quelconque.";
                }
                $builder->add('partenaire', PartenaireAutocompleteField::class, [
                    'label' => "Intermédiaire ou partenaire",
                    'help' => $this->helppartenaire,
                    'class' => Partenaire::class,
                    'required' => false,
                    'choice_label' => 'nom',
                ]);
                break;

            case Note::TO_AUTORITE_FISCALE:
                if ($options['type'] == Note::TYPE_NOTE_DE_DEBIT) {
                    $this->helpautorite = "L'autorité foscale à qui vous désirez addreser cette note de débit.";
                } else {
                    $this->helpArticle = "Les articles sont les tranches depuis lesquelles les taxes seront extraites.";
                    $this->helpautorite = "L'autorité à qui vous désirez addreser cette note de crédit pour retrocéssion des taxes.";
                }
                $builder->add('autoritefiscale', AutoriteFiscaleAutocompleteField::class, [
                    'label' => "Autorité fiscale",
                    'help' => $this->helpautorite,
                    'class' => AutoriteFiscale::class,
                    'required' => false,
                    'choice_label' => 'abreviation',
                ]);
                break;

            default:
                # code...
                break;
        }
        //Construction selon le type
        if ($options['type'] == Note::TYPE_NOTE_DE_DEBIT) {
            $builder->add('comptes', CompteBancaireAutocompleteField::class, [
                'label' => "Comptes bancaires",
                'help' => "Comptes bancaires auxquels vous désirez vous faire payés.",
                'attr' => [
                    'placeholder' => "Séléctionner le compte",
                ],
                'class' => CompteBancaire::class,
                'required' => false,
                'multiple' => true,
                'choice_label' => 'intitule',
            ]);
        }
        $builder
            ->add('articles', CollectionType::class, [
                'label' => "Articles",
                'help' => $this->helpArticle,
                'entry_type' => ArticleType::class,
                'by_reference' => false,
                'allow_add' => true,
                'allow_delete' => true,
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
            ]);
    }
}
