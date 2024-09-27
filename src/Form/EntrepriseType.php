<?php

namespace App\Form;

use App\Entity\Entreprise;
use DateTimeImmutable;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Event\PreSubmitEvent;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
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
            ->add('secteur', NumberType::class, [
                'label' => "Secteur D'activité",
            ])
            //Le bouton d'enregistrement / soumission
            ->add('enregistrer', SubmitType::class, [
                'label' => "Enregistrer"
            ])
            ->addEventListener(FormEvents::PRE_SUBMIT, $this->onPreSubmitActions(...))
        ;
    }

    public function onPreSubmitActions(PreSubmitEvent $event)
    {
        $data = $event->getData();
        $data['updatedAt'] = new DateTimeImmutable("now");
        $event->setData($data);
        // dd($data);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Entreprise::class,
        ]);
    }
}
