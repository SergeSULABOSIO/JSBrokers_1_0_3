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
        $dataNom['data'] = "Droits d'accèss dans le module Finance";
        $dataMonnaie['data'] = [Invite::ACCESS_LECTURE];
        $dataCompteBancaire['data'] = [Invite::ACCESS_LECTURE];
        $dataTaxe['data'] = [Invite::ACCESS_LECTURE];

        if ($parent_object != null) {
            /** @var RolesEnFinance|[] $tabRolesFin */
            $tabRolesFin = $parent_object->getRolesEnFinance();
            if (count($tabRolesFin) != 0) {
                $dataNom['data'] = $tabRolesFin[0]->getNom();
                $dataMonnaie['data'] = $tabRolesFin[0]->getAccessMonnaie();
                $dataCompteBancaire['data'] = $tabRolesFin[0]->getAccessCompteBancaire();
                $dataTaxe['data'] = $tabRolesFin[0]->getAccessTaxe();
                dd($dataNom);
            }
        }

        // dd($dataNom);

        $builder
            ->add('nom', TextType::class, [
                'label' => "Nom du rôle",
                'data' => "Droits d'accèss dans le module Finance",
                'disabled' => true,
                'required' => false,
                'attr' => [
                    'placeholder' => "Nom",
                ],
            ])
            ->add('accessMonnaie', ChoiceType::class, [
                // 'data' => [Invite::ACCESS_LECTURE],
                'label' => "Droit d'accès sur les monnaies",
                'help' => "Ce que peut faire l'invité dans les monnaies",
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
                // 'data' => [Invite::ACCESS_LECTURE],
                'label' => "Droit d'accès sur les comptes bancaires",
                'help' => "Ce que peut faire l'invité dans les comptes bancaires",
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
                // 'data' => [Invite::ACCESS_LECTURE],
                // $dataTaxe,
                'label' => "Droit d'accès sur les taxes",
                'help' => "Ce que peut faire l'invité dans les taxes",
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
