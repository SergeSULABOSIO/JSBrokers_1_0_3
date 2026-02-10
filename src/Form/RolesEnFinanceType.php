<?php

namespace App\Form;

use App\Entity\Invite;
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
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;

class RolesEnFinanceType extends AbstractType
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
            ->add('accessMonnaie', ChoiceType::class, [
                'label' => "Droit d'accès sur les monnaies",
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
            ->add('accessCompteBancaire', ChoiceType::class, [
                'label' => "Droit d'accès sur les comptes bancaires",
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
            // ->add('accessTaxe')
            ->add('accessTaxe', ChoiceType::class, [
                'label' => "Droit d'accès sur les taxes",
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
            ->add('accessTypeRevenu', ChoiceType::class, [
                'label' => "Droit d'accès sur les types de revenu",
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
            ->add('accessRevenu', ChoiceType::class, [
                'label' => "Droit d'accès sur les revenus",
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
            ->add('accessTranche', ChoiceType::class, [
                'label' => "Droit d'accès sur les tranches",
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
            ->add('accessTypeChargement', ChoiceType::class, [
                'label' => "Droit d'accès sur les types de chargement",
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
            ->add('accessNote', ChoiceType::class, [
                'label' => "Droit d'accès sur les notes",
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
            ->add('accessPaiement', ChoiceType::class, [
                'label' => "Droit d'accès sur les paiements",
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
            ->add('accessBordereau', ChoiceType::class, [
                'label' => "Droit d'accès sur les bordereaux",
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
            'data_class' => RolesEnFinance::class,
            'csrf_protection' => false,
            'allow_extra_fields' => true,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}
