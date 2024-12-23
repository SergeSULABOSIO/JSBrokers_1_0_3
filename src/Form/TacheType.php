<?php

namespace App\Form;

use App\Entity\Piste;
use App\Entity\Tache;
use App\Entity\Invite;
use App\Entity\Cotation;
use App\Services\FormListenerFactory;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\AbstractType;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Contracts\Translation\TranslatorInterface;

class TacheType extends AbstractType
{

    public function __construct(
        private FormListenerFactory $ecouteurFormulaire,
        private TranslatorInterface $translatorInterface,
        private Security $security
    ) {}
    
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('description', TextType::class, [
                'label' => "Description",
                'attr' => [
                    'placeholder' => "Description",
                ],
            ])
            ->add('toBeEndedAt', DateTimeType::class, [
                'label' => "Echéance",
                'widget' => 'single_text',
            ])
            // ->add('createdAt', DateTimeType::class, [
            //     'label' => "Description",
            //     'widget' => 'single_text',
            // ])
            // ->add('updatedAt', DateTimeType::class, [
            //     'label' => "Description",
            //     'widget' => 'single_text',
            // ])
            ->add('closed', ChoiceType::class, [
                'label' => "La tâche est-elle accomplie?",
                'expanded' => true,
                'choices'  => [
                    "Oui" => true,
                    "Pas encore." => false,
                ],
            ])
            // ->add('invite', EntityType::class, [
            //     'label' => "Invité",
            //     'class' => Invite::class,
            //     'choice_label' => 'id',
            // ])
            ->add('executor', EntityType::class, [
                'label' => "Executeur",
                'class' => Invite::class,
                'choice_label' => 'id',
            ])
            // ->add('piste', EntityType::class, [
            //     'label' => "Piste",
            //     'class' => Piste::class,
            //     'choice_label' => 'id',
            // ])
            // ->add('cotation', EntityType::class, [
            //     'label' => "Cotation",
            //     'class' => Cotation::class,
            //     'choice_label' => 'id',
            // ])
            ->add('feedbacks', CollectionType::class, [
                'label' => "tache_form_label_feedback",
                'entry_type' => FeedbackType::class,
                'by_reference' => false,
                'allow_add' => true,
                'allow_delete' => true,
                'entry_options' => [
                    'label' => false,
                ],
                'attr' => [
                    'data-controller' => 'form-collection-entites',
                    'data-form-collection-entites-add-label-value' => $this->translatorInterface->trans("commom_add"),
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
            ->addEventListener(FormEvents::POST_SUBMIT, $this->ecouteurFormulaire->setUtilisateur())
            ->addEventListener(FormEvents::POST_SUBMIT, $this->ecouteurFormulaire->timeStamps())
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Tache::class,
        ]);
    }
}
