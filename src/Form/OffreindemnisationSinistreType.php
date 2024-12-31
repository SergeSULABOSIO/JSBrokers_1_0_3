<?php

namespace App\Form;

use App\Entity\NotificationSinistre;
use App\Services\FormListenerFactory;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\AbstractType;
use App\Entity\OffreIndemnisationSinistre;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;

class OffreindemnisationSinistreType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('beneficiaire')
            ->add('franchiseAppliquee')
            ->add('montantPayable')
            // ->add('updatedAt', null, [
            //     'widget' => 'single_text',
            // ])
            // ->add('createdAt', null, [
            //     'widget' => 'single_text',
            // ])
            ->add('referenceBancaire')
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
