<?php

namespace App\Form;

use App\Entity\Invite;
use App\Entity\Entreprise;
use App\Services\FormListenerFactory;
// use Doctrine\DBAL\Types\DateTimeType;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\AbstractType;
use App\DTO\CriteresRechercheDashBordDTO;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Polyfill\Intl\Icu\IntlDateFormatter;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;

class RechercheDashBordType extends AbstractType
{

    public function __construct(
        private FormListenerFactory $ecouteurFormulaire,
        private Security $security
    ) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {

        $builder
            ->add('entreprises', EntrepriseAutocompleteField::class, [
                'required' => false,
                'label' => false,
                'class' => Entreprise::class,
                'choice_label' => "nom",
                'multiple' => true,
                'expanded' => false,
                'by_reference' => false,
                'autocomplete' => true,
                'attr' => [
                    'placeholder' => "company_dashboard_search_form_clients_placeholder",
                    'class' => "form-control p-2 fs-6",
                ],
                //une requêt pour filtrer les elements de la liste d'options
                'query_builder' => $this->ecouteurFormulaire->setFiltreUtilisateur(),
            ])

            ->add('assureurs', EntrepriseAutocompleteField::class, [
                'required' => false,
                'label' => false,
                'class' => Entreprise::class,
                'choice_label' => "nom",
                'multiple' => true,
                'expanded' => false,
                'by_reference' => false,
                'autocomplete' => true,
                'attr' => [
                    'placeholder' => "company_dashboard_search_form_assureurs_placeholder",
                    'class' => "form-control",
                ],
                //une requêt pour filtrer les elements de la liste d'options
                'query_builder' => $this->ecouteurFormulaire->setFiltreUtilisateur(),
            ])

            ->add('produits', EntrepriseAutocompleteField::class, [
                'required' => false,
                'label' => false,
                'class' => Entreprise::class,
                'choice_label' => "nom",
                'multiple' => true,
                'expanded' => false,
                'by_reference' => false,
                'autocomplete' => true,
                'attr' => [
                    'placeholder' => "company_dashboard_search_form_produits_placeholder",
                    'class' => "form-control",
                ],
                //une requêt pour filtrer les elements de la liste d'options
                'query_builder' => $this->ecouteurFormulaire->setFiltreUtilisateur(),
            ])

            ->add('partenaires', EntrepriseAutocompleteField::class, [
                'required' => false,
                'label' => false,
                'class' => Entreprise::class,
                'choice_label' => "nom",
                'multiple' => true,
                'expanded' => false,
                'by_reference' => false,
                'autocomplete' => true,
                'attr' => [
                    'placeholder' => "company_dashboard_search_form_partenaires_placeholder",
                    'class' => "form-control",
                ],
                //une requêt pour filtrer les elements de la liste d'options
                'query_builder' => $this->ecouteurFormulaire->setFiltreUtilisateur(),
            ])

            ->add('dateDebut', DateTimeType::class, [
                'label' => false,
            ])

            ->add('dateFin', DateTimeType::class, [
                'label' => false,
            ])

            //Le bouton d'enregistrement / soumission
            ->add('chercher', SubmitType::class, [
                'label' => "company_dashboard_search_button_filter",
                'attr' => [
                    'class' => "btn btn-secondary p-2 fs-6",
                ],
            ])
            // ->addEventListener(FormEvents::POST_SUBMIT, $this->ecouteurFormulaire->setUtilisateur())
            // ->addEventListener(FormEvents::POST_SUBMIT, $this->ecouteurFormulaire->timeStamps())
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CriteresRechercheDashBordDTO::class,
        ]);
    }
}
