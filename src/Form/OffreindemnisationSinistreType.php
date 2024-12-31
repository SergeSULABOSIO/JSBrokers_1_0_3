<?php

namespace App\Form;

use App\Entity\NotificationSinistre;
use App\Entity\OffreIndemnisationSinistre;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class OffreindemnisationSinistreType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('beneficiaire')
            ->add('franchiseAppliquee')
            ->add('montantPayable')
            ->add('updatedAt', null, [
                'widget' => 'single_text',
            ])
            ->add('createdAt', null, [
                'widget' => 'single_text',
            ])
            ->add('referenceBancaire')
            ->add('notification', EntityType::class, [
                'class' => NotificationSinistre::class,
                'choice_label' => 'id',
            ])
            ->add('notificationSinistre', EntityType::class, [
                'class' => NotificationSinistre::class,
                'choice_label' => 'id',
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
