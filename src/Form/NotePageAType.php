<?php

namespace App\Form;

use App\Entity\Note;
use App\DTO\NotePageADTO;
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

class NotePageAType extends AbstractType
{
    public function __construct(
        private FormListenerFactory $ecouteurFormulaire,
        private TranslatorInterface $translatorInterface
    ) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('reference', TextType::class, [
                'required' => false,
                'label' => "Référence",
                'attr' => [
                    'placeholder' => "Référence",
                ],
            ])
            ->add('nom', TextType::class, [
                'label' => "Nom",
                'attr' => [
                    'placeholder' => "Nom",
                ],
            ])
            ->add('type', ChoiceType::class, [
                'label' => "Type de la note",
                'expanded' => true,
                'choices'  => [
                    "Null" => Note::TYPE_NULL,
                    "Note de débit" => Note::TYPE_NOTE_DE_DEBIT,
                    "Note de crédit" => Note::TYPE_NOTE_DE_CREDIT,
                ]
            ])
            ->add('addressedTo', ChoiceType::class, [
                'label' => "Type de la note",
                'expanded' => true,
                'choices'  => [
                    "Null" => Note::TO_NULL,
                    "A l'attention du client" => Note::TO_CLIENT,
                    "A l'attention de l'assureur" => Note::TO_ASSUREUR,
                    "A l'attention de l'intermédiaire" => Note::TO_PARTENAIRE,
                ]
            ])
            ->add('description', TextType::class, [
                'label' => "Description",
                'attr' => [
                    'placeholder' => "Description",
                ],
            ])
            ->add('comptes', CompteBancaireAutocompleteField::class, [
                'label' => "Comptes bancaires",
                'attr' => [
                    'placeholder' => "Séléctionner le compte",
                ],
                'class' => CompteBancaire::class,
                'required' => false,
                'multiple' => true,
                'choice_label' => 'intitule',
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
            //Le bouton suivant
            ->add('Suivant', SubmitType::class, [
                'label' => "Suivant",
                'attr' => [
                    'class' => "btn btn-secondary",
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => NotePageADTO::class,
        ]);
    }
}
