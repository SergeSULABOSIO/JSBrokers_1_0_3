<?php

namespace App\Form;

use App\DTO\CriteresRechercheDashBordDTO;
use App\Entity\Invite;
use App\Entity\Entreprise;
use App\Services\FormListenerFactory;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\AbstractType;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;

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
                'label' => false,
                'class' => Entreprise::class,
                'choice_label' => "nom",
                'multiple' => true,
                'expanded' => false,
                'by_reference' => false,
                'autocomplete' => true,
                'attr' => [
                    'placeholder' => "Tapez le nom du client ici.",
                    'class' => "rounded p-2 fs-6",
                ],
                //une requêt pour filtrer les elements de la liste d'options
                'query_builder' => $this->ecouteurFormulaire->setFiltreUtilisateur(),
            ])

            //Le bouton d'enregistrement / soumission
            ->add('chercher', SubmitType::class, [
                'label' => "Filtrer",
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
