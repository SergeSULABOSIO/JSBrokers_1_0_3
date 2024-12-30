<?php

namespace App\Form;

use App\Entity\Client;
use App\Entity\Invite;
use App\Entity\Risque;
use App\Entity\NotificationSinistre;
use App\Services\FormListenerFactory;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\AbstractType;
use Doctrine\DBAL\Types\DateTimeImmutableType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;
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
                'label' => "Référence de la police",
                'required' => true,
                'attr' => [
                    'placeholder' => "Réf. Police",
                ],
            ])
            ->add('referenceSinistre', TextType::class, [
                'label' => "Référence du sinistre",
                'required' => false,
                'attr' => [
                    'placeholder' => "Réf. Sinistre",
                ],
            ])
            ->add('descriptionDeFait', TextareaType::class, [
                'label' => "Description des faits",
                'required' => true,
                'attr' => [
                    'placeholder' => "Description",
                ],
            ])
            ->add('occuredAt', DateTimeImmutableType::class, [
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
            // ->add('createdAt', null, [
            //     'widget' => 'single_text',
            // ])
            // ->add('updatedAt', null, [
            //     'widget' => 'single_text',
            // ])
            ->add('dommage', MoneyType::class, [
                'label' => "Valeur de la perte",
                'currency' => "USD",
                'grouping' => true,
                'attr' => [
                    'placeholder' => "Dommage",
                ],
            ])
            ->add('assure', EntityType::class, [
                'class' => Client::class,
                'choice_label' => 'nom',
            ])
            ->add('risque', EntityType::class, [
                'class' => Risque::class,
                'choice_label' => 'nom',
            ])
            // ->add('invite', EntityType::class, [
            //     'class' => Invite::class,
            //     'choice_label' => 'id',
            // ])
            ->add('contacts', CollectionType::class, [
                'label' => "Contacts",
                'entry_type' => ContactType::class,
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
        ]);
    }
}
