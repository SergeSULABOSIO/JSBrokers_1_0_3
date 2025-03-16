<?php

namespace App\Form;

use App\Entity\CompteBancaire;
use App\Entity\Paiement;
use App\Entity\FactureCommission;
use App\Services\FormListenerFactory;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\AbstractType;
use App\Entity\OffreIndemnisationSinistre;
use App\Services\ServiceMonnaies;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;

class PaiementType extends AbstractType
{
    public function __construct(
        private FormListenerFactory $ecouteurFormulaire,
        private TranslatorInterface $translatorInterface,
        private ServiceMonnaies $serviceMonnaies,
    ) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if ($options['note'] != null) {
            $builder
                ->add('referenceNote', TextType::class, [
                    'label' => "Référence de la note",
                    'disabled' => true,
                    'mapped' => false,
                    'attr' => [
                        'placeholder' => "Référence",
                    ],
                ])
                ->add('montantPayable', MoneyType::class, [
                    'label' => "Montant payable",
                    'currency' => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    'required' => false,
                    'disabled' => true,
                    'mapped' => false,
                    'grouping' => true,
                    'attr' => [
                        'placeholder' => "Montant",
                    ],
                ])
                ->add('montantPaye', MoneyType::class, [
                    'label' => "Montant payé",
                    'disabled' => true,
                    'currency' => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    'required' => false,
                    'mapped' => false,
                    'grouping' => true,
                    'attr' => [
                        'placeholder' => "Montant",
                    ],
                ])
                ->add('montantSolde', MoneyType::class, [
                    'label' => "Solde restant dû",
                    'disabled' => true,
                    'currency' => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    'required' => false,
                    'mapped' => false,
                    'grouping' => true,
                    'attr' => [
                        'placeholder' => "Montant",
                    ],
                ]);
        }


        $builder
            ->add('description', TextType::class, [
                'label' => "Description",
                'attr' => [
                    'placeholder' => "Description",
                ],
            ])
            ->add('montant', MoneyType::class, [
                'label' => "Montant en cours de paiement",
                'currency' => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                'required' => false,
                'grouping' => true,
                'attr' => [
                    'placeholder' => "Montant",
                ],
            ])
            ->add('reference', TextType::class, [
                'label' => "Référence",
                'attr' => [
                    'placeholder' => "Référence",
                ],
            ])
            ->add('paidAt', DateTimeType::class, [
                'label' => "Date de paiement",
                'widget' => 'single_text',
            ])
            ->add('CompteBancaire', EntityType::class, [
                'label' => "Compte bancaire",
                'required' => true,
                'class' => CompteBancaire::class,
                'choice_label' => 'nom',
            ])
            ->add('preuves', CollectionType::class, [
                'label' => "Documents ou preuve de paiement",
                'help' => "Preuve de paiement ou tout autre document.",
                'entry_type' => DocumentType::class,
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
            // ->add('createdAt', null, [
            //     'widget' => 'single_text',
            // ])
            // ->add('updatedAt', null, [
            //     'widget' => 'single_text',
            // ])
            // ->add('factureCommission', EntityType::class, [
            //     'class' => FactureCommission::class,
            //     'choice_label' => 'id',
            // ])
            // ->add('offreIndemnisationSinistre', EntityType::class, [
            //     'class' => OffreIndemnisationSinistre::class,
            //     'choice_label' => 'id',
            // ])
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
            'data_class' => Paiement::class,
            'note' => null,
        ]);
    }
}
