<?php

namespace App\Form;

use App\Entity\Invite;
use App\Entity\RolesEnSinistre;
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

class RolesEnSinistreType extends AbstractType
{
    public function __construct(
        private FormListenerFactory $ecouteurFormulaire,
        private TranslatorInterface $translatorInterface,
        private ServiceMonnaies $serviceMonnaies,
        private Security $security
    ) {}
    
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Invite $parent_object */
        $parent_object = $options['parent_object'];
        // dd($invite);
        $dataNom = "Droits d'accèss dans le module Sinistre";
        $dataTypePiece = [Invite::ACCESS_LECTURE];
        $dataNotification = [Invite::ACCESS_LECTURE];
        $dataReglement = [Invite::ACCESS_LECTURE];
        $prefixeHelp = "Ce que peut faire l'invité";
        // dd($parent_object);

        if ($parent_object != null) {
            if ($parent_object->getId() != null) {
                /** @var RolesEnSinistre|[] $tabRolesSin */
                $tabRolesSin = $parent_object->getRolesEnSinistre();
                // dd($parent_object);
                if (count($tabRolesSin) != 0) {
                    // dd($parent_object);
                    $dataNom = $tabRolesSin[0]->getNom();
                    $dataTypePiece = $tabRolesSin[0]->getAccessTypePiece();
                    $dataNotification = $tabRolesSin[0]->getAccessNotification();
                    $dataReglement = $tabRolesSin[0]->getAccessReglement();
                }
                $prefixeHelp = "Ce que peut faire " . $parent_object->getNom();
            }
        }

        $builder
            ->add('nom', TextType::class, [
                'data' => $dataNom,
                'label' => "Nom du rôle",
                'required' => false,
                'attr' => [
                    'readonly' => true,
                    'placeholder' => "Nom",
                ],
            ])
            ->add('accessTypePiece', ChoiceType::class, [
                'data' => $dataTypePiece,
                'label' => "Droit d'accès sur les types des pièces",
                'help' => $prefixeHelp . " dans les types des pièces",
                'multiple' => true,
                'expanded' => true,
                'required' => true,
                'choices'  => [
                    "Lecture" => Invite::ACCESS_LECTURE,
                    "Ecriture" => Invite::ACCESS_ECRITURE,
                    "Modification" => Invite::ACCESS_MODIFICATION,
                    "Suppression" => Invite::ACCESS_SUPPRESSION,
                ],
            ])
            ->add('accessNotification', ChoiceType::class, [
                'data' => $dataNotification,
                'label' => "Droit d'accès sur les notification ou déclarations des sinistres",
                'help' => $prefixeHelp . " dans les notifications des sinistres",
                'multiple' => true,
                'expanded' => true,
                'required' => true,
                'choices'  => [
                    "Lecture" => Invite::ACCESS_LECTURE,
                    "Ecriture" => Invite::ACCESS_ECRITURE,
                    "Modification" => Invite::ACCESS_MODIFICATION,
                    "Suppression" => Invite::ACCESS_SUPPRESSION,
                ],
            ])
            ->add('accessReglement', ChoiceType::class, [
                'data' => $dataReglement,
                'label' => "Droit d'accès sur les règlements des sinistres",
                'help' => $prefixeHelp . " dans les règlements des sinistres",
                'multiple' => true,
                'expanded' => true,
                'required' => true,
                'choices'  => [
                    "Lecture" => Invite::ACCESS_LECTURE,
                    "Ecriture" => Invite::ACCESS_ECRITURE,
                    "Modification" => Invite::ACCESS_MODIFICATION,
                    "Suppression" => Invite::ACCESS_SUPPRESSION,
                ],
            ])
            // ->add('invite', EntityType::class, [
            //     'class' => Invite::class,
            //     'choice_label' => 'id',
            // ])
            ->add('enregistrer', SubmitType::class, [
                'label' => "Enregistrer",
                'attr' => [
                    'class' => "btn btn-secondary",
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => RolesEnSinistre::class,
            'parent_object' => null, // l'objet parent
        ]);
    }
}
