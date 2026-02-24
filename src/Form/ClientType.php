<?php

namespace App\Form;

use App\Entity\Client;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;

class ClientType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('civilite', ChoiceType::class, [
                'label' => "Civilité",
                'choices' => [
                    'Monsieur' => Client::CIVILITE_Mr,
                    'Madame' => Client::CIVILITE_Mme,
                    'Entreprise' => Client::CIVILITE_ENTREPRISE,
                    'ASBL' => Client::CIVILITE_ASBL,
                ],
                'expanded' => false,
                'required' => false,
            ])
            ->add('nom', TextType::class, [
                'label' => "Nom",
                'attr' => ['placeholder' => "Nom du client"]
            ])
            ->add('email', EmailType::class, [
                'label' => "Email",
                'required' => false,
                'attr' => ['placeholder' => "adresse@email.com"]
            ])
            ->add('telephone', TextType::class, [
                'label' => "Téléphone",
                'required' => false,
                'attr' => ['placeholder' => "+123456789"]
            ])
            ->add('adresse', TextType::class, [
                'label' => "Adresse",
                'required' => false,
                'attr' => ['placeholder' => "Adresse physique"]
            ])
            ->add('numimpot', TextType::class, [
                'label' => "N° Impôt",
                'required' => false,
                'attr' => ['placeholder' => "Numéro d'identification fiscale"]
            ])
            ->add('rccm', TextType::class, [
                'label' => "RCCM",
                'required' => false,
                'attr' => ['placeholder' => "Registre de Commerce"]
            ])
            ->add('idnat', TextType::class, [
                'label' => "ID.NAT",
                'required' => false,
                'attr' => ['placeholder' => "Numéro d'identification nationale"]
            ])
            ->add('groupe', GroupeAutocompleteField::class, [
                'label' => 'Groupe',
                'placeholder' => 'Sélectionner un groupe',
                'required' => false,
            ])
            ->add('exonere', ChoiceType::class, [
                'label' => "Exonéré de taxes ?",
                'expanded' => true,
                'required' => true,
                'label_html' => true,
                'choices'  => [
                    'Non' => false,
                    'Oui' => true,
                ],
                'choice_label' => function ($choice, $key, $value) {
                    if ($choice === true) {
                        return '<div><strong>Oui</strong><div class="text-muted small">Le client est exonéré de taxes.</div></div>';
                    }
                    return '<div><strong>Non</strong><div class="text-muted small">Les taxes seront appliquées.</div></div>';
                },
            ])
            ->add('contacts', CollectionType::class, [
                'entry_type' => ContactType::class,
                'by_reference' => false,
                'allow_add' => true,
                'allow_delete' => true,
                'label' => 'Contacts',
                'entry_options' => ['label' => false],
                'mapped' => false,
            ])
            ->add('partenaires', PartenaireAutocompleteField::class, [
                'label' => "Partenaires",
                'placeholder' => "Chercher un partenaire",
                'required' => false,
                'multiple' => true,
                'by_reference' => false,
            ])
            ->add('documents', CollectionType::class, [
                'entry_type' => DocumentType::class,
                'by_reference' => false,
                'allow_add' => true,
                'allow_delete' => true,
                'label' => 'Documents',
                'entry_options' => ['label' => false],
                'mapped' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Client::class,
            'csrf_protection' => false,
            'allow_extra_fields' => true,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}