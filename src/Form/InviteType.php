<?php

namespace App\Form;

use App\Entity\Invite;
use App\Entity\Entreprise;
use App\Entity\RolesEnFinance;
use App\Services\ServiceMonnaies;
use App\Services\FormListenerFactory;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\AbstractType;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;

class InviteType extends AbstractType
{
    public function __construct(
        private FormListenerFactory $ecouteurFormulaire,
        private TranslatorInterface $translatorInterface,
        private ServiceMonnaies $serviceMonnaies,
        private Security $security
    ) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                // 'label' => false,
                'label' => "Email",
                'empty_data' => '',
                'required' => true,
                'attr' => [
                    'placeholder' => "Adresse mail valide de l'invité",
                ],
            ])
            ->add('nom', TextType::class, [
                // 'label' => false,
                'label' => "Nom",
                'empty_data' => '',
                'required' => false,
                'attr' => [
                    'placeholder' => "Nom complet de l'invité",
                ],
            ])
            ->add('assistants', InviteAutocompleteField::class, [
                'label' => "Assistants",
                // 'label' => false,
                'help' => "Liste d'assistants travaillant sous la responsabilité de l'invité actuel.",
                'multiple' => true,
                'expanded' => false,
                'required' => false,
                'by_reference' => false,
                'attr' => [
                    'placeholder' => "Assistants",
                ],
            ])
            ->add('rolesEnFinance', CollectionType::class, [
                'label' => "Droits d'accès dans le module Finances",
                'entry_type' => RolesEnFinanceType::class,
                'by_reference' => false,
                'allow_add' => true,
                'allow_delete' => true,
                'entry_options' => [
                    'label' => false,
                ],
                'mapped' => false,
            ])
            ->add('rolesEnMarketing', CollectionType::class, [
                'label' => "Droits d'accès dans le module Marketing",
                'entry_type' => RolesEnMarketingType::class,
                'by_reference' => false,
                'allow_add' => true,
                'allow_delete' => true,
                'entry_options' => [
                    'label' => false,
                ],
                'mapped' => false,
            ])
            ->add('rolesEnProduction', CollectionType::class, [
                'label' => "Droits d'accès dans le module Production",
                'entry_type' => RolesEnProductionType::class,
                'by_reference' => false,
                'allow_add' => true,
                'allow_delete' => true,
                'entry_options' => [
                    'label' => false,
                ],
                'mapped' => false,
            ])
            ->add('rolesEnSinistre', CollectionType::class, [
                'label' => "Droits d'accès dans le module Sinistre",
                'entry_type' => RolesEnSinistreType::class,
                'by_reference' => false,
                'allow_add' => true,
                'allow_delete' => true,
                'entry_options' => [
                    'label' => false,
                ],
                'mapped' => false,
            ])
            ->add('rolesEnAdministration', CollectionType::class, [
                'label' => "Droits d'accès dans le module Administration",
                'entry_type' => RolesEnAdministrationType::class,
                'by_reference' => false,
                'allow_add' => true,
                'allow_delete' => true,
                'entry_options' => [
                    'label' => false,
                ],
                'mapped' => false,
            ])

            // ->addEventListener(FormEvents::POST_SUBMIT, $this->ecouteurFormulaire->setUtilisateur())
            ->addEventListener(FormEvents::POST_SUBMIT, $this->ecouteurFormulaire->timeStamps())
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Invite::class,
            'csrf_protection' => false,
            'allow_extra_fields' => true,
        ]);
    }

    /**
     * En retournant une chaîne vide, on dit à Symfony de ne pas
     * préfixer les champs du formulaire. Le formulaire n'aura pas de nom racine.
     */
    public function getBlockPrefix(): string
    {
        return '';
    }
}
