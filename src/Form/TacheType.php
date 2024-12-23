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

class TacheType extends AbstractType
{

    public function __construct(
        private FormListenerFactory $ecouteurFormulaire,
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
            ->add('invite', EntityType::class, [
                'label' => "Invité",
                'class' => Invite::class,
                'choice_label' => 'id',
            ])
            ->add('executor', EntityType::class, [
                'label' => "Executeur",
                'class' => Invite::class,
                'choice_label' => 'id',
            ])
            ->add('piste', EntityType::class, [
                'label' => "Piste",
                'class' => Piste::class,
                'choice_label' => 'id',
            ])
            ->add('cotation', EntityType::class, [
                'label' => "Cotation",
                'class' => Cotation::class,
                'choice_label' => 'id',
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
