<?php

namespace App\Form;

use App\Entity\Invite;
use App\Services\ServiceMonnaies;
use App\Entity\RolesEnAdministration;
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

class RolesEnAdministrationType extends AbstractType
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
            ->add('accessDocument', ChoiceType::class, [
                'label' => "Droit d'accès sur les documents",
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
            ->add('accessClasseur', ChoiceType::class, [
                'label' => "Droit d'accès sur les classeurs",
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
            ->add('accessInvite', ChoiceType::class, [
                'label' => "Droit d'accès sur les invités",
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
            ->add('accessAssistantIa', ChoiceType::class, [
                'label' => "Droit d'accès sur l'assistant IA",
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
            ->add('accessConge', ChoiceType::class, [
                'label' => "Droit d'accès sur les congés",
                'help' => "Le niveau « Modification » fait le VALIDEUR : il permet d'approuver, de refuser et d'annuler, et donne la vue sur les demandes de tout le cabinet. Sans lui, le collaborateur ne voit que les siennes.",
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
            ->add('accessCongeParametre', ChoiceType::class, [
                'label' => "Droit d'accès sur le paramétrage des congés",
                'help' => "Types d'absence et jours fériés. Se confie séparément de la validation.",
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
            'data_class' => RolesEnAdministration::class,
            'csrf_protection' => false,
            'allow_extra_fields' => true,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}
