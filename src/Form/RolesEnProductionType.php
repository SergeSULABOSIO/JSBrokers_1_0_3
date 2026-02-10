<?php

namespace App\Form;

use App\Entity\Invite;
use App\Entity\RolesEnProduction;
use App\Services\ServiceMonnaies;
use App\Services\FormListenerFactory;
use Symfony\Component\Form\AbstractType;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;

class RolesEnProductionType extends AbstractType
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
            ->add('nom', TextType::class, [
                'label' => "Nom du rôle",
                'required' => false,
                'attr' => [
                    'placeholder' => "Nom",
                ],
            ])
            ->add('accessGroupe', ChoiceType::class, [
                'label' => "Droit d'accès sur les groupes des clients",
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'choices'  => [
                    "Lecture" => Invite::ACCESS_LECTURE,
                    "Ecriture" => Invite::ACCESS_ECRITURE,
                    "Modification" => Invite::ACCESS_MODIFICATION,
                    "Suppression" => Invite::ACCESS_SUPPRESSION,
                ],
            ])
            ->add('accessClient', ChoiceType::class, [
                'label' => "Droit d'accès sur les clients",
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'choices'  => [
                    "Lecture" => Invite::ACCESS_LECTURE,
                    "Ecriture" => Invite::ACCESS_ECRITURE,
                    "Modification" => Invite::ACCESS_MODIFICATION,
                    "Suppression" => Invite::ACCESS_SUPPRESSION,
                ],
            ])
            ->add('accessAssureur', ChoiceType::class, [
                'label' => "Droit d'accès sur les assureurs",
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'choices'  => [
                    "Lecture" => Invite::ACCESS_LECTURE,
                    "Ecriture" => Invite::ACCESS_ECRITURE,
                    "Modification" => Invite::ACCESS_MODIFICATION,
                    "Suppression" => Invite::ACCESS_SUPPRESSION,
                ],
            ])
            ->add('accessContact', ChoiceType::class, [
                'label' => "Droit d'accès sur les contacts",
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'choices'  => [
                    "Lecture" => Invite::ACCESS_LECTURE,
                    "Ecriture" => Invite::ACCESS_ECRITURE,
                    "Modification" => Invite::ACCESS_MODIFICATION,
                    "Suppression" => Invite::ACCESS_SUPPRESSION,
                ],
            ])
            ->add('accessRisque', ChoiceType::class, [
                'label' => "Droit d'accès sur les risques",
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'choices'  => [
                    "Lecture" => Invite::ACCESS_LECTURE,
                    "Ecriture" => Invite::ACCESS_ECRITURE,
                    "Modification" => Invite::ACCESS_MODIFICATION,
                    "Suppression" => Invite::ACCESS_SUPPRESSION,
                ],
            ])
            ->add('accessAvenant', ChoiceType::class, [
                'label' => "Droit d'accès sur les avenants",
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'choices'  => [
                    "Lecture" => Invite::ACCESS_LECTURE,
                    "Ecriture" => Invite::ACCESS_ECRITURE,
                    "Modification" => Invite::ACCESS_MODIFICATION,
                    "Suppression" => Invite::ACCESS_SUPPRESSION,
                ],
            ])
            ->add('accessPartenaire', ChoiceType::class, [
                'label' => "Droit d'accès sur les intermédiaires",
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'choices'  => [
                    "Lecture" => Invite::ACCESS_LECTURE,
                    "Ecriture" => Invite::ACCESS_ECRITURE,
                    "Modification" => Invite::ACCESS_MODIFICATION,
                    "Suppression" => Invite::ACCESS_SUPPRESSION,
                ],
            ])
            ->add('accessCotation', ChoiceType::class, [
                'label' => "Droit d'accès sur les propositions",
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'choices'  => [
                    "Lecture" => Invite::ACCESS_LECTURE,
                    "Ecriture" => Invite::ACCESS_ECRITURE,
                    "Modification" => Invite::ACCESS_MODIFICATION,
                    "Suppression" => Invite::ACCESS_SUPPRESSION,
                ],
            ])
            ->add('invite', InviteAutocompleteField::class, [
                'label' => "Collaborateur",
                'required' => true,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => RolesEnProduction::class,
            'csrf_protection' => false,
            'allow_extra_fields' => true,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}
