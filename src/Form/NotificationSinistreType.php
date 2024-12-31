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
use Doctrine\DBAL\Types\DateTimeType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\DateType;

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
            ->add('referenceSinistre', TextType::class, [
                'label' => "Référence du sinistre",
                'help' => "Si vous n'avez pas encore de numéro sinistre, veuillez sauter ce champ.",
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
            // ->add('createdAt', null, [
            //     'widget' => 'single_text',
            // ])
            // ->add('updatedAt', null, [
            //     'widget' => 'single_text',
            // ])
            ->add('dommage', MoneyType::class, [
                'label' => "Valeur de la perte",
                'help' => "Il s'agit d'une estimation chiffrée du coût de reparation des dégats causés et/ou subis lors de l'évènement survenu.",
                'currency' => "USD",
                'grouping' => true,
                'attr' => [
                    'placeholder' => "Dommage",
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
            // ->add('invite', EntityType::class, [
            //     'class' => Invite::class,
            //     'choice_label' => 'id',
            // ])
            ->add('contacts', CollectionType::class, [
                'label' => "Contacts",
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
                    'data-form-collection-entites-add-label-value' => $this->translatorInterface->trans("commom_add"), //'Ajouter',
                    'data-form-collection-entites-delete-label-value' => $this->translatorInterface->trans("commom_delete"),
                    'data-form-collection-entites-edit-label-value' => $this->translatorInterface->trans("commom_edit"),
                    'data-form-collection-entites-close-label-value' => $this->translatorInterface->trans("commom_close"),
                    'data-form-collection-entites-new-element-label-value' => $this->translatorInterface->trans("commom_new_element"),
                    'data-form-collection-entites-view-field-value' => "nom",
                ],
            ])
            ->add('pieces', CollectionType::class, [
                'label' => "Pièces ou documents",
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
                    'data-form-collection-entites-add-label-value' => $this->translatorInterface->trans("commom_add"), //'Ajouter',
                    'data-form-collection-entites-delete-label-value' => $this->translatorInterface->trans("commom_delete"),
                    'data-form-collection-entites-edit-label-value' => $this->translatorInterface->trans("commom_edit"),
                    'data-form-collection-entites-close-label-value' => $this->translatorInterface->trans("commom_close"),
                    'data-form-collection-entites-new-element-label-value' => $this->translatorInterface->trans("commom_new_element"),
                    'data-form-collection-entites-view-field-value' => "description",
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
