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
        /** @var Invite $parent_object */
        $parent_object = $options['parent_object'];
        // dd($invite);
        $dataNom = "Droits d'accèss dans le module Finance";
        $dataMonnaie = [Invite::ACCESS_LECTURE];
        $dataCompteBancaire = [Invite::ACCESS_LECTURE];
        $dataTaxe = [Invite::ACCESS_LECTURE];
        $prefixeHelp = "Ce que peut faire l'invité";

        if ($parent_object != null && $parent_object->getId() != null) {
            /** @var RolesEnFinance|[] $tabRolesFin */
            $tabRolesFin = $parent_object->getRolesEnFinance();
            if (count($tabRolesFin) != 0) {
                // dd($parent_object);
                $dataNom = $tabRolesFin[0]->getNom();
                $dataMonnaie = $tabRolesFin[0]->getAccessMonnaie();
                $dataCompteBancaire = $tabRolesFin[0]->getAccessCompteBancaire();
                $dataTaxe = $tabRolesFin[0]->getAccessTaxe();
                $prefixeHelp = "Ce que peut faire " . $parent_object->getNom();
            }
        }

        $builder
            ->add('nom', TextType::class, [
                'data' => $dataNom,
                'label' => "Nom du rôle",
                // 'disabled' => true,
                'required' => false,
                'attr' => [
                    'placeholder' => "Nom",
                ],
            ])
            ->add('accessMonnaie', ChoiceType::class, [
                'data' => $dataMonnaie,
                'label' => "Droit d'accès sur les monnaies",
                'help' => $prefixeHelp . " dans les monnaies",
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
            ->add('accessCompteBancaire', ChoiceType::class, [
                'data' => $dataCompteBancaire,
                'label' => "Droit d'accès sur les comptes bancaires",
                'help' => $prefixeHelp . " dans les comptes bancaires",
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
            // ->add('accessTaxe')
            ->add('accessTaxe', ChoiceType::class, [
                'data' => $dataTaxe,
                'label' => "Droit d'accès sur les taxes",
                'help' => $prefixeHelp . " dans les taxes",
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
            'data_class' => RolesEnFinance::class,
            'parent_object' => null, // l'objet parent
        ]);
    }
}
