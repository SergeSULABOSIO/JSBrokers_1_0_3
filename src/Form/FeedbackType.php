<?php

namespace App\Form;

use App\Entity\Tache;
use App\Entity\Invite;
use App\Entity\Feedback;
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

class FeedbackType extends AbstractType
{

    public function __construct(
        private FormListenerFactory $ecouteurFormulaire,
        private Security $security
    ) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('type', ChoiceType::class, [
                'label' => "feedback_form_type",
                'expanded' => true,
                'choices'  => [
                    "Rencontre physique" => Feedback::TYPE_PHYSICAL_MEETING,
                    "Appel" => Feedback::TYPE_CALL,
                    "E-mail" => Feedback::TYPE_EMAIL,
                    "SMS" => Feedback::TYPE_SMS,
                    "Non défini" => Feedback::TYPE_UNDEFINED,
                ],
            ])
            ->add('description', TextType::class, [
                'label' => "Description",
                'attr' => [
                    'placeholder' => "Description",
                ],
            ])
            ->add('nextAction', TextType::class, [
                'required' => false,
                'label' => "Prochaine Action",
                'attr' => [
                    'placeholder' => "Description",
                ],
            ])
            ->add('nextActionAt', DateTimeType::class, [
                'required' => false,
                'widget' => 'single_text',
            ])
            // ->add('createdAt', DateTimeType::class, [
            //     'widget' => 'single_text',
            // ])
            // ->add('updatedAt', DateTimeType::class, [
            //     'widget' => 'single_text',
            // ])
            // ->add('invite', EntityType::class, [
            //     'label' => "Invité",
            //     'class' => Invite::class,
            //     'choice_label' => 'id',
            // ])
            // ->add('tache', EntityType::class, [
            //     'label' => "Tâche",
            //     'class' => Tache::class,
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
            'data_class' => Feedback::class,
        ]);
    }
}
