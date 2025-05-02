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
        $dataNom = "Droits d'accès dans le module Finance";
        $dataMonnaie = [Invite::ACCESS_LECTURE];
        $dataCompteBancaire = [Invite::ACCESS_LECTURE];
        $dataTaxe = [Invite::ACCESS_LECTURE];
        $dataTypeRevenu = [Invite::ACCESS_LECTURE];
        $dataTranche = [Invite::ACCESS_LECTURE];
        $dataTypeChargement = [Invite::ACCESS_LECTURE];
        $dataNote = [Invite::ACCESS_LECTURE];
        $dataPaiement = [Invite::ACCESS_LECTURE];
        $dataBordereau = [Invite::ACCESS_LECTURE];
        $dataRevenu = [Invite::ACCESS_LECTURE];
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
                $dataTypeRevenu = $tabRolesFin[0]->getAccessTypeRevenu();
                $dataTranche = $tabRolesFin[0]->getAccessTranche();
                $dataTypeChargement = $tabRolesFin[0]->getAccessTypeChargement();
                $dataNote = $tabRolesFin[0]->getAccessNote();
                $dataPaiement = $tabRolesFin[0]->getAccessPaiement();
                $dataBordereau = $tabRolesFin[0]->getAccessBordereau();
                $dataRevenu = $tabRolesFin[0]->getAccessRevenu();
            }
            $prefixeHelp = "Ce que peut faire " . $parent_object->getNom();
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
            ->add('accessTypeRevenu', ChoiceType::class, [
                'data' => $dataTypeRevenu,
                'label' => "Droit d'accès sur les types de revenu",
                'help' => $prefixeHelp . " dans les types de revenu",
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
            ->add('accessRevenu', ChoiceType::class, [
                'data' => $dataRevenu,
                'label' => "Droit d'accès sur les revenus",
                'help' => $prefixeHelp . " dans les revenus",
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
            ->add('accessTranche', ChoiceType::class, [
                'data' => $dataTranche,
                'label' => "Droit d'accès sur les tranches",
                'help' => $prefixeHelp . " dans les tranches",
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
            ->add('accessTypeChargement', ChoiceType::class, [
                'data' => $dataTypeChargement,
                'label' => "Droit d'accès sur les types de chargement",
                'help' => $prefixeHelp . " dans les types de chargement",
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
            ->add('accessNote', ChoiceType::class, [
                'data' => $dataNote,
                'label' => "Droit d'accès sur les notes",
                'help' => $prefixeHelp . " dans les notes",
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
            ->add('accessPaiement', ChoiceType::class, [
                'data' => $dataPaiement,
                'label' => "Droit d'accès sur les paiements",
                'help' => $prefixeHelp . " dans les paiements",
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
            ->add('accessBordereau', ChoiceType::class, [
                'data' => $dataBordereau,
                'label' => "Droit d'accès sur les bordereaux",
                'help' => $prefixeHelp . " dans les bordereaux",
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
