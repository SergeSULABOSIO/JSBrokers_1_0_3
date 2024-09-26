<?php

namespace App\Form;

use App\Entity\UtilisateurJSB;
use DateTimeImmutable;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Event\PostSubmitEvent;
use Symfony\Component\Form\Event\PreSetDataEvent;
use Symfony\Component\Form\Event\PreSubmitEvent;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\String\Slugger\AsciiSlugger;

class UtilisateurJSBType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', TextType::class, [
                'label' => "Nom complet"
            ])
            ->add('email', EmailType::class, [
                'label' => "Votre adresse mail"
            ])
            ->add('motDePasse', PasswordType::class, [
                'label' => "Votre mot de Passe"
            ])
            ->add('motDePasseConfirme', PasswordType::class, [
                'label' => "Conformer votre mot de passe"
            ])
            //Le bouton d'enregistrement / soumission
            ->add('enregistrer', SubmitType::class, [
                'label' => "Enregistrer"
            ])
            ->addEventListener(FormEvents::PRE_SUBMIT, $this->onPreSubmitActions(...))
            ->addEventListener(FormEvents::PRE_SET_DATA, $this->onPreSetDataActions(...))
            ->addEventListener(FormEvents::POST_SUBMIT, $this->onPostSubmitActions(...))
        ;
    }

    public function onPreSubmitActions(PreSubmitEvent $event)
    {
        // $data = $event->getData();
        // $slugger = new AsciiSlugger();
        // $data['nom'] = strtolower($slugger->slug($data['nom']));
        // $event->setData($data);
        // dd($data);
        
    }

    public function onPreSetDataActions(PreSetDataEvent $event)
    {
        // /** @var UtilisateurJSB */
        // $user = $event->getData();
        // $user->setNom("SULA BOSIO Serge");
        // $user->setEmail("ssula@gmail.com");
        // $user->setMotDePasse("ssula@gmail.com");
        // $user->setMotDePasseConfirme("ssula@gmail.com");
        // dd($user);
        // $event->setData($user);
    }

    public function onPostSubmitActions(PostSubmitEvent $event)
    {
        // /** @var UtilisateurJSB */
        // $data = $event->getData();
        // $data->setCreatedAt(new DateTimeImmutable("now"));
        // $data->setUpdatedAt(new DateTimeImmutable("now"));
        // dd($data);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => UtilisateurJSB::class,
        ]);
    }
}
