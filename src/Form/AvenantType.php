<?php

namespace App\Form;

use App\Entity\Avenant;
use App\Services\FormListenerFactory;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;

class AvenantType extends AbstractType
{
    public function __construct(
        private FormListenerFactory $ecouteurFormulaire,
        private TranslatorInterface $translatorInterface
    ) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('referencePolice', TextType::class, [
                'required' => true,
                'label' => "Référence de la police",
                'attr' => [
                    'placeholder' => "Référence de la police",
                ],
            ])
            ->add('description', TextType::class, [
                'required' => false,
                'label' => "Description",
                'attr' => [
                    'placeholder' => "Description",
                ],
            ])
            ->add('type', ChoiceType::class, [
                'label' => "Type d'Avenant",
                'expanded' => true,
                'choices'  => [
                    "SOUSCRIPTION"      => Avenant::AVENANT_SOUSCRIPTION,
                    "INCORPORATION"     => Avenant::AVENANT_INCORPORATION,
                    "PROROGATION"       => Avenant::AVENANT_PROROGATION,
                    "ANNULATION"        => Avenant::AVENANT_ANNULATION,
                    "RENOUVELLEMENT"    => Avenant::AVENANT_RENOUVELLEMENT,
                    "RESILIATION"       => Avenant::AVENANT_RESILIATION,
                ],
            ])
            ->add('startingAt', DateTimeType::class, [
                'label' => "Date début",
                'widget' => 'single_text',
            ])
            ->add('endingAt', DateTimeType::class, [
                'label' => "Echéance",
                'widget' => 'single_text',
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
            // ->add('createdAt', null, [
            //     'widget' => 'single_text',
            // ])
            // ->add('updatedAt', null, [
            //     'widget' => 'single_text',
            // ])

            // ->add('invite', EntityType::class, [
            //     'class' => Invite::class,
            //     'choice_label' => 'id',
            // ])
            // ->add('cotation', EntityType::class, [
            //     'class' => Cotation::class,
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
            'data_class' => Avenant::class,
        ]);
    }
}
