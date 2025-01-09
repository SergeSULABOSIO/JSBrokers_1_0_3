<?php

namespace App\Form;

use App\Entity\Assureur;
use App\Entity\Client;
use App\Entity\CompteBancaire;
use App\Entity\Invite;
use App\Entity\Note;
use App\Entity\Partenaire;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class NoteType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom')
            ->add('type')
            ->add('addressedTo')
            ->add('description')
            ->add('invite', EntityType::class, [
                'class' => Invite::class,
                'choice_label' => 'id',
            ])
            ->add('client', EntityType::class, [
                'class' => Client::class,
                'choice_label' => 'id',
            ])
            ->add('partenaire', EntityType::class, [
                'class' => Partenaire::class,
                'choice_label' => 'id',
            ])
            ->add('assureur', EntityType::class, [
                'class' => Assureur::class,
                'choice_label' => 'id',
            ])
            ->add('comptes', EntityType::class, [
                'class' => CompteBancaire::class,
                'choice_label' => 'id',
                'multiple' => true,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Note::class,
        ]);
    }
}
