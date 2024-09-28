<?php

namespace App\Form;

use App\Entity\Entreprise;
use DateTimeImmutable;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Event\PostSubmitEvent;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;

class EntrepriseType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', TextType::class, [
                'label' => "Nom complet",
                'attr' => [
                    'placeholder' => "Nom Complet de l'entreprise",
                ],
            ])
            ->add('adresse', TextType::class, [
                'label' => "Adresse physique",
                'attr' => [
                    'placeholder' => "Adresse complète",
                ],
            ])
            ->add('licence', TextType::class, [
                'label' => "Numéro d'agrément",
                'attr' => [
                    'placeholder' => "Numéro d'agrément",
                ],
            ])
            ->add('telephone', TextType::class, [
                'label' => "Numéro de téléphone",
                'attr' => [
                    'placeholder' => "Numéro de téléphone",
                ],
            ])
            ->add('rccm', TextType::class, [
                'label' => "Numéro de registre de commerce",
                'attr' => [
                    'placeholder' => "RCCM",
                ],
            ])
            ->add('idnat', TextType::class, [
                'label' => "Numéro d'identification nationale",
                'attr' => [
                    'placeholder' => "IDNAT",
                ],
            ])
            ->add('numimpot', TextType::class, [
                'label' => "Num d'impôt (NIF)",
                'attr' => [
                    'placeholder' => "NIF",
                ],
            ])
            //Le bouton d'enregistrement / soumission
            ->add('enregistrer', SubmitType::class, [
                'label' => "Enregistrer"
            ])
            ->addEventListener(FormEvents::POST_SUBMIT, $this->onPostSubmitActions(...))
        ;
    }

    public function onPostSubmitActions(PostSubmitEvent $event)
    {
        /** @var Entreprise */
        $entreprise = $event->getData();
        $entreprise->setUpdatedAt(new DateTimeImmutable('now'));
        if($entreprise->getId() == null){
            $entreprise->setCreatedAt(new DateTimeImmutable('now'));
        }
        // dd($event);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Entreprise::class,
        ]);
    }
}
