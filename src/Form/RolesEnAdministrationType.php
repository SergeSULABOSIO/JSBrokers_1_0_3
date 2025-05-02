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
        /** @var Invite $parent_object */
        $parent_object = $options['parent_object'];
        // dd($invite);
        $dataNom = "Droits d'accès dans le module Administration";
        $dataDocument = [Invite::ACCESS_LECTURE];
        $dataClasseur = [Invite::ACCESS_LECTURE];
        $dataInvite = [Invite::ACCESS_LECTURE];
        $prefixeHelp = "Ce que peut faire l'invité";
        // dd($parent_object);

        if ($parent_object != null) {
            if ($parent_object->getId() != null) {
                /** @var RolesEnAdministration|[] $tabRolesAdmin */
                $tabRolesAdmin = $parent_object->getRolesEnAdministration();
                // dd($parent_object);
                if (count($tabRolesAdmin) != 0) {
                    // dd($parent_object);
                    $dataNom = $tabRolesAdmin[0]->getNom();
                    $dataDocument = $tabRolesAdmin[0]->getAccessDocument();
                    $dataClasseur = $tabRolesAdmin[0]->getAccessClasseur();
                    $dataInvite = $tabRolesAdmin[0]->getAccessInvite();
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
            ->add('accessDocument', ChoiceType::class, [
                'data' => $dataDocument,
                'label' => "Droit d'accès sur les documents",
                'help' => $prefixeHelp . " dans les documents",
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
            ->add('accessClasseur', ChoiceType::class, [
                'data' => $dataClasseur,
                'label' => "Droit d'accès sur les classeurs",
                'help' => $prefixeHelp . " dans les classeurs",
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
            ->add('accessInvite', ChoiceType::class, [
                'data' => $dataInvite,
                'label' => "Droit d'accès sur les invités",
                'help' => $prefixeHelp . " dans les invités",
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
            'data_class' => RolesEnAdministration::class,
            'parent_object' => null, // l'objet parent
        ]);
    }
}
