<?php

namespace App\Form;

use App\Entity\Invite;
use DateTimeImmutable;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Event\PostSubmitEvent;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;

class InviteType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label' => "L'addresse mail de l'invité",
                'empty_data' => '',
                'attr' => [
                    'placeholder' => "Email",
                ],
            ])
            // ->add('createdAt', null, [
            //     'widget' => 'single_text',
            // ])
            // ->add('updatedAt', null, [
            //     'widget' => 'single_text',
            // ])
             //Le bouton d'enregistrement / soumission
             ->add('enregistrer', SubmitType::class, [
                'label' => "Envoyer l'invitation"
            ])
            ->addEventListener(FormEvents::POST_SUBMIT, $this->onPostSubmitActions(...))
        ;
    }

    public function onPostSubmitActions(PostSubmitEvent $event)
    {
        /** @var Invite */
        $invite = $event->getData();
        $invite->setUpdatedAt(new DateTimeImmutable('now'));
        if($invite->getId() == null){
            $invite->setCreatedAt(new DateTimeImmutable('now'));
        }
        // dd($event);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Invite::class,
        ]);
    }
}
