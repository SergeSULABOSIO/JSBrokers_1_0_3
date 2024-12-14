<?php

namespace App\Form;

use App\Entity\Invite;
use App\Entity\Entreprise;
use App\Services\FormListenerFactory;
// use Doctrine\DBAL\Types\DateTimeType;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\AbstractType;
use App\DTO\CriteresRechercheDashBordDTO;
use App\Entity\Assureur;
use App\Entity\Client;
use App\Entity\Partenaire;
use App\Entity\Risque;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Polyfill\Intl\Icu\IntlDateFormatter;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;

class RechercheDashBordType extends AbstractType
{

    private ?Entreprise $entreprise = null;
    
    public function __construct(
        private FormListenerFactory $ecouteurFormulaire,
        private Security $security
    ) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {

        $builder
            ->add('clients', ClientAutocompleteField::class, [
                'required' => false,
                'label' => false,
                'class' => Client::class,
                'choice_label' => "nom",
                'multiple' => true,
                'expanded' => false,
                'by_reference' => false,
                'autocomplete' => true,
                'attr' => [
                    'placeholder' => "company_dashboard_search_form_clients_placeholder",
                    'class' => "form-control p-2 fs-6",
                ],
            ])

            ->add('assureurs', AssureurAutocompleteField::class, [
                'required' => false,
                'label' => false,
                'class' => Assureur::class,
                'choice_label' => "nom",
                'multiple' => true,
                'expanded' => false,
                'by_reference' => false,
                'autocomplete' => true,
                'attr' => [
                    'placeholder' => "company_dashboard_search_form_assureurs_placeholder",
                    'class' => "form-control",
                ],
            ])

            ->add('produits', RisqueAutocompleteField::class, [
                'required' => false,
                'label' => false,
                'class' => Risque::class,
                'choice_label' => "nomComplet",
                'multiple' => true,
                'expanded' => false,
                'by_reference' => false,
                'autocomplete' => true,
                'attr' => [
                    'placeholder' => "company_dashboard_search_form_produits_placeholder",
                    'class' => "form-control",
                ],
            ])

            ->add('partenaires', PartenaireAutocompleteField::class, [
                'required' => false,
                'label' => false,
                'class' => Partenaire::class,
                'choice_label' => "nom",
                'multiple' => true,
                'expanded' => false,
                'by_reference' => false,
                'autocomplete' => true,
                'attr' => [
                    'placeholder' => "company_dashboard_search_form_partenaires_placeholder",
                    'class' => "form-control",
                ],
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

    /**
     * Get the value of entreprise
     */ 
    public function getEntreprise()
    {
        return $this->entreprise;
    }

    /**
     * Set the value of entreprise
     *
     * @return  self
     */ 
    public function setEntreprise($entreprise)
    {
        $this->entreprise = $entreprise;

        return $this;
    }
}
