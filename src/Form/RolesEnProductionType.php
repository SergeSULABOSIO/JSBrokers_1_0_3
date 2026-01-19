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
        /** @var Invite $parent_object */
        $parent_object = $options['parent_object'];
        // dd($invite);
        $dataNom = "Droits d'accès dans le module Production";
        $dataGroupe = [Invite::ACCESS_LECTURE];
        $dataClient = [Invite::ACCESS_LECTURE];
        $dataAssureur = [Invite::ACCESS_LECTURE];
        $dataContact = [Invite::ACCESS_LECTURE];
        $dataRisque = [Invite::ACCESS_LECTURE];
        $dataAvenant = [Invite::ACCESS_LECTURE];
        $dataPartenaire = [Invite::ACCESS_LECTURE];
        $dataCotation = [Invite::ACCESS_LECTURE];
        $prefixeHelp = "Ce que peut faire l'invité";
        // dd($parent_object);

        if ($parent_object != null) {
            if ($parent_object->getId() != null) {
                /** @var RolesEnProduction|[] $tabRolesProd */
                $tabRolesProd = $parent_object->getRolesEnProduction();
                // dd($parent_object);
                if (count($tabRolesProd) != 0) {
                    // dd($parent_object);
                    $dataNom = $tabRolesProd[0]->getNom();
                    $dataGroupe = $tabRolesProd[0]->getAccessGroupe();
                    $dataClient = $tabRolesProd[0]->getAccessClient();
                    $dataAssureur = $tabRolesProd[0]->getAccessAssureur();
                    $dataContact = $tabRolesProd[0]->getAccessContact();
                    $dataRisque = $tabRolesProd[0]->getAccessRisque();
                    $dataPartenaire = $tabRolesProd[0]->getAccessPartenaire();
                    $dataCotation = $tabRolesProd[0]->getAccessCotation();
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
            ->add('accessGroupe', ChoiceType::class, [
                'data' => $dataGroupe,
                'label' => "Droit d'accès sur les groupes des clients",
                'help' => $prefixeHelp . " dans les groupes des clients",
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
            ->add('accessClient', ChoiceType::class, [
                'data' => $dataClient,
                'label' => "Droit d'accès sur les clients",
                'help' => $prefixeHelp . " dans les clients",
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
            ->add('accessAssureur', ChoiceType::class, [
                'data' => $dataAssureur,
                'label' => "Droit d'accès sur les assureurs",
                'help' => $prefixeHelp . " dans les assureurs",
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
            ->add('accessContact', ChoiceType::class, [
                'data' => $dataContact,
                'label' => "Droit d'accès sur les contacts",
                'help' => $prefixeHelp . " dans les contacts",
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
            ->add('accessRisque', ChoiceType::class, [
                'data' => $dataRisque,
                'label' => "Droit d'accès sur les risques",
                'help' => $prefixeHelp . " dans les risques",
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
            ->add('accessAvenant', ChoiceType::class, [
                'data' => $dataAvenant,
                'label' => "Droit d'accès sur les avenants",
                'help' => $prefixeHelp . " dans les avenants",
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
            ->add('accessPartenaire', ChoiceType::class, [
                'data' => $dataPartenaire,
                'label' => "Droit d'accès sur les intermédiaires",
                'help' => $prefixeHelp . " dans les intermédiairess",
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
            ->add('accessCotation', ChoiceType::class, [
                'data' => $dataCotation,
                'label' => "Droit d'accès sur les propositions",
                'help' => $prefixeHelp . " dans les propositions",
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
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => RolesEnProduction::class,
            'parent_object' => null, // l'objet parent
            'csrf_protection' => false,
            'allow_extra_fields' => true,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}
