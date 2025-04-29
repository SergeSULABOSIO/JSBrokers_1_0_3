<?php

namespace App\Form;

use App\Entity\Invite;
use App\Entity\Entreprise;
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
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;

class InviteType extends AbstractType
{
    public function __construct(
        private FormListenerFactory $ecouteurFormulaire,
        private TranslatorInterface $translatorInterface,
        private ServiceMonnaies $serviceMonnaies,
        private Security $security
    ) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Invite|null $invite */
        $invite = null;
        if (isset($options['data'])) {
            $invite = $options['data'];
        }
        // dd($invite);

        $builder
            ->add('email', EmailType::class, [
                // 'label' => false,
                'label' => "Email",
                'empty_data' => '',
                'required' => true,
                'attr' => [
                    'placeholder' => "Adresse mail valide de l'invité",
                ],
            ])
            ->add('nom', TextType::class, [
                // 'label' => false,
                'label' => "Nom",
                'empty_data' => '',
                'required' => false,
                'attr' => [
                    'placeholder' => "Nom complet de l'invité",
                ],
            ])
            ->add('assistants', InviteAutocompleteField::class, [
                'label' => "Assistants",
                // 'label' => false,
                'help' => "Liste d'assistants travaillant sous la responsabilité de l'invité actuel.",
                'multiple' => true,
                'expanded' => false,
                'required' => false,
                'by_reference' => false,
                'attr' => [
                    'placeholder' => "Assistants",
                ],
            ])
            ->add('rolesEnFinance', CollectionType::class, [
                'label' => "Droits d'accès dans le module Finances",
                'entry_type' => RolesEnFinanceType::class,
                'by_reference' => false,
                'allow_add' => true,
                'allow_delete' => true,
                'entry_options' => [
                    'label' => false,
                    'parent_object' => $invite,
                ],
                'attr' => [
                    'data-controller' => 'form-collection-entites',
                    // 'data-form-collection-entites-add-label-value' => $this->translatorInterface->trans("commom_add"), //'Ajouter',
                    // 'data-form-collection-entites-delete-label-value' => $this->translatorInterface->trans("commom_delete"),
                    // 'data-form-collection-entites-edit-label-value' => $this->translatorInterface->trans("commom_edit"),
                    // 'data-form-collection-entites-close-label-value' => $this->translatorInterface->trans("commom_close"),
                    // 'data-form-collection-entites-new-element-label-value' => $this->translatorInterface->trans("commom_new_element"),
                    // 'data-form-collection-entites-view-field-value' => "nom",
                    // 'data-form-collection-entites-icone-value' => "role",
                    // 'data-form-collection-entites-dossieraction-value' => "1",  //1 si oui 0 si non
                    'data-form-collection-entites-data-value' => json_encode([
                        'addLabel' => $this->translatorInterface->trans("commom_add"),
                        'deleteLabel' => $this->translatorInterface->trans("commom_delete"),
                        // 'editLabel' => $this->translatorInterface->trans("commom_edit"),
                        // 'closeLabel' => $this->translatorInterface->trans("commom_close"),
                        // 'newElementLabel' => $this->translatorInterface->trans("commom_new_element"),
                        'icone' => "role",
                        'dossieractions' => 1, //1=On doit chercher l'icone "role" dans le dossier ICONES/ACTIONS, sinon on la chercher dans le dossier racine càd le dossier ICONES (le dossier racime)
                    ]),
                ],
            ])

            // ->add('entreprises', EntrepriseAutocompleteField::class, [
            //     'label' => "invite_form_company",
            //     'class' => Entreprise::class,
            //     'choice_label' => "nom",
            //     'multiple' => true,
            //     'expanded' => false,
            //     'by_reference' => false,
            //     'autocomplete' => true,
            //     'attr' => [
            //         'placeholder' => "invite_form_company_placeholder",
            //     ],
            //     //une requêt pour filtrer les elements de la liste d'options
            //     'query_builder' => $this->ecouteurFormulaire->setFiltreUtilisateur(),
            // ])

            //Le bouton d'enregistrement / soumission
            ->add('enregistrer', SubmitType::class, [
                'label' => "Enregistrer",
                'attr' => [
                    'class' => "btn btn-secondary",
                ],
            ])
            // ->addEventListener(FormEvents::POST_SUBMIT, $this->ecouteurFormulaire->setUtilisateur())
            ->addEventListener(FormEvents::POST_SUBMIT, $this->ecouteurFormulaire->timeStamps())
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Invite::class,
        ]);
    }
}
