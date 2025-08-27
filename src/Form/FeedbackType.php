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
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;

class FeedbackType extends AbstractType
{

    public function __construct(
        private FormListenerFactory $ecouteurFormulaire,
        private Security $security
    ) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {

        $builder
            ->add('description', TextareaType::class, [
                'label' => "Description",
                'attr' => [
                    'rows' => 4, // Hauteur du champ de texte
                    'placeholder' => 'Saisissez votre commentaire ici...',
                ],
            ])
            ->add('type', ChoiceType::class, [
                'label' => "Moyen de contact",
                'expanded' => false,
                'choices'  => [
                    "Rencontre physique" => Feedback::TYPE_PHYSICAL_MEETING,
                    "Appel" => Feedback::TYPE_CALL,
                    "E-mail" => Feedback::TYPE_EMAIL,
                    "SMS" => Feedback::TYPE_SMS,
                    "Non défini" => Feedback::TYPE_UNDEFINED,
                ],
            ])
            ->add('hasNextAction', ChoiceType::class, [
                'label' => "Y a-t-il une prochaine action?",
                'help' => "Action consécutive au compte-rendu courant et qui devra être exécutée par la suite.",
                'expanded' => false,
                'required' => true,
                'choices'  => [
                    "Non" => false,
                    "Oui" => true,
                ],
            ])
            ->add('nextAction', TextareaType::class, [
                'required' => false,
                'label' => "Prochaine Action",
                'attr' => [
                    'placeholder' => "Description",
                ],
            ])
            ->add('nextActionAt', DateTimeType::class, [
                'label' => "Date de la prochaine action",
                'help' => "Date en laquelle la prochaine action devra être exécutée.",
                'required' => false,
                'widget' => 'single_text',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Feedback::class,
            'csrf_protection' => false,
            'allow_extra_fields' => true,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}
