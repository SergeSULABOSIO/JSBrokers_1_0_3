<?php

namespace App\Form;

use App\Services\FormListenerFactory;
use Symfony\Component\Form\AbstractType;
use App\Entity\OffreIndemnisationSinistre;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;

class OffreIndemnisationSinistreType extends AbstractType
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
                'required' => true,
                'attr' => [
                    'placeholder' => "Nom",
                ],
            ])
            ->add('beneficiaire', TextType::class, [
                'help' => "Nom du bénéficiaire ou le client lui-même.",
                'label' => "Bénéficiaire",
                'required' => true,
                'attr' => [
                    'placeholder' => "Bénéficiaire",
                ],
            ])
            ->add('franchiseAppliquee', MoneyType::class, [
                'label' => "Franchise appliquée",
                'currency' => "USD",
                'grouping' => true,
                'attr' => [
                    'placeholder' => "Franchise",
                ],
            ])
            ->add('montantPayable', MoneyType::class, [
                'label' => "Montant payable",
                'help' => "Solde payable après application de la franchise au coût / valeure totale de reparation.",
                'currency' => "USD",
                'grouping' => true,
                'attr' => [
                    'placeholder' => "Montant payable",
                ],
            ])
            // ->add('updatedAt', null, [
            //     'widget' => 'single_text',
            // ])
            // ->add('createdAt', null, [
            //     'widget' => 'single_text',
            // ])
            ->add('referenceBancaire', TextType::class, [
                'help' => "Référence bancaire du bénéficiaire.",
                'label' => "Référence bancaire",
                'required' => true,
                'attr' => [
                    'placeholder' => "Référence bancaire",
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
            ->add('paiements', CollectionType::class, [
                'label' => "Paiements",
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
            // ->add('notification', EntityType::class, [
            //     'class' => NotificationSinistre::class,
            //     'choice_label' => 'id',
            // ])
            // ->add('notificationSinistre', EntityType::class, [
            //     'class' => NotificationSinistre::class,
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
            'data_class' => OffreIndemnisationSinistre::class,
        ]);
    }
}
