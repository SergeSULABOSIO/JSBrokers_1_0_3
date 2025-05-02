<?php

namespace App\Form;

use App\Entity\Invite;
use App\Entity\RolesEnMarketing;
use App\Services\ServiceMonnaies;
use App\Services\FormListenerFactory;
use Symfony\Component\Form\AbstractType;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;

class RolesEnMarketingType extends AbstractType
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
        $dataNom = "Droits d'accès dans le module Marketing";
        $dataPiste = [Invite::ACCESS_LECTURE];
        $dataTache = [Invite::ACCESS_LECTURE];
        $dataFeedback = [Invite::ACCESS_LECTURE];
        $prefixeHelp = "Ce que peut faire l'invité";

        
        if ($parent_object != null) {
            if ($parent_object->getId() != null) {
                /** @var RolesEnMarketing|[] $tabRolesMark */
                $tabRolesMark = $parent_object->getRolesEnMarketing();
                // dd($parent_object);
                if (count($tabRolesMark) != 0) {
                    // dd($parent_object);
                    $dataNom = $tabRolesMark[0]->getNom();
                    $dataPiste = $tabRolesMark[0]->getAccessPiste();
                    $dataTache = $tabRolesMark[0]->getAccessTache();
                    $dataFeedback = $tabRolesMark[0]->getAccessFeedback();
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
            ->add('accessPiste', ChoiceType::class, [
                'data' => $dataPiste,
                'label' => "Droit d'accès sur les pistes",
                'help' => $prefixeHelp . " dans les pistes",
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
            ->add('accessTache', ChoiceType::class, [
                'data' => $dataTache,
                'label' => "Droit d'accès sur les tâches",
                'help' => $prefixeHelp . " dans les tâches",
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
            ->add('accessFeedback', ChoiceType::class, [
                'data' => $dataFeedback,
                'label' => "Droit d'accès sur les comptes-rendus",
                'help' => $prefixeHelp . " dans les comptes-rendus",
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

            //Le bouton d'enregistrement / soumission
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
            'data_class' => RolesEnMarketing::class,
            'parent_object' => null, // l'objet parent
        ]);
    }
}
