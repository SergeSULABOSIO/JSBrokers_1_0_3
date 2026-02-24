<?php

namespace App\Form;

use App\Entity\Assureur;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;

class AssureurType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', TextType::class, [
                'label' => "Nom",
                'attr' => [
                    'placeholder' => "Nom",
                ],
            ])
            ->add('telephone', TextType::class, [
                'label' => "Téléphone",
                'required' => false,
                'attr' => [
                    'placeholder' => "Téléphone",
                ],
            ])
            ->add('numimpot', TextType::class, [
                'label' => "Nunméro Impôt",
                'attr' => [
                    'placeholder' => "NIF",
                ],
            ])
            ->add('rccm', TextType::class, [
                'label' => "Nunméro RCCM",
                'attr' => [
                    'placeholder' => "RCCM",
                ],
            ])
            ->add('idnat', TextType::class, [
                'label' => "Id. nationale",
                'attr' => [
                    'placeholder' => "Idnat",
                ],
            ])
            ->add('email', EmailType::class, [
                'label' => "Email",
                'attr' => [
                    'placeholder' => "Email",
                ],
            ])
            ->add('url', UrlType::class, [
                'label' => "Site Internet",
                'attr' => [
                    'placeholder' => "Site Internet",
                ],
            ])
            ->add('adressePhysique', TextType::class, [
                'label' => "Adresse Physique",
                'attr' => [
                    'placeholder' => "Adresse Physique",
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Assureur::class,
            'csrf_protection' => false,
            'allow_extra_fields' => true,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}
