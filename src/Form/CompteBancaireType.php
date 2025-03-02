<?php

namespace App\Form;


use App\Entity\CompteBancaire;
use App\Services\FormListenerFactory;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;

class CompteBancaireType extends AbstractType
{
    public function __construct(
        private FormListenerFactory $ecouteurFormulaire,
        private TranslatorInterface $translatorInterface
    ) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', TextType::class, [
                'label' => "Nom du compte",
                'attr' => [
                    'placeholder' => "Nom du compte",
                ],
            ])
            ->add('intitule', TextType::class, [
                'label' => "Intitulé du compte",
                'attr' => [
                    'placeholder' => "Intitulé du compte ici",
                ],
            ])
            ->add('numero', TextType::class, [
                'label' => "Numéro du compte",
                'attr' => [
                    'placeholder' => "Numéro du compte ici",
                ],
            ])
            ->add('banque', TextType::class, [
                'label' => "Nom de la Banque",
                'attr' => [
                    'placeholder' => "Nom de la banque",
                ],
            ])
            ->add('codeSwift', TextType::class, [
                'label' => "Code Swift",
                'attr' => [
                    'placeholder' => "Code Swift",
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
                    'data-form-collection-entites-add-label-value' => $this->translatorInterface->trans("commom_add"), //'Ajouter',
                    'data-form-collection-entites-delete-label-value' => $this->translatorInterface->trans("commom_delete"),
                    'data-form-collection-entites-edit-label-value' => $this->translatorInterface->trans("commom_edit"),
                    'data-form-collection-entites-close-label-value' => $this->translatorInterface->trans("commom_close"),
                    'data-form-collection-entites-new-element-label-value' => $this->translatorInterface->trans("commom_new_element"),
                    'data-form-collection-entites-view-field-value' => "nom",
                ],
            ])
            // ->add('entreprise', EntityType::class, [
            //     'class' => Entreprise::class,
            //     'choice_label' => 'id',
            // ])
            //Le bouton d'enregistrement / soumission
            ->add('enregistrer', SubmitType::class, [
                'label' => "Enregistrer",
                'attr' => [
                    'class' => "btn btn-secondary",
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CompteBancaire::class,
        ]);
    }
}
