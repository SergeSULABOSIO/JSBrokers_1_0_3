<?php

namespace App\Form;

use App\Entity\Invite;
use DateTimeImmutable;
use App\Entity\Entreprise;
use App\Entity\Utilisateur;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\EntityRepository;
use App\Services\FormListenerFactory;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\AbstractType;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\Event\PostSubmitEvent;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;

class InviteType extends AbstractType
{

    public function __construct(
        private FormListenerFactory $ecouteurFormulaire,
        private Security $security
    ) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {


        $builder
            ->add('email', EmailType::class, [
                'label' => "invite_form_email",
                'empty_data' => '',
                'required' => true,
                'attr' => [
                    'placeholder' => "invite_form_email_placeholder",
                ],
            ])
            ->add('entreprises', EntrepriseAutocompleteField::class, [
                'label' => "invite_form_company",
                'class' => Entreprise::class,
                'choice_label' => "nom",
                'multiple' => true,
                'expanded' => false,
                'by_reference' => false,
                'autocomplete' => true,
                'attr' => [
                    'placeholder' => "invite_form_company_placeholder",
                ],
                //une requêt pour filtrer les elements de la liste d'options
                'query_builder' => $this->ecouteurFormulaire->setFiltreUtilisateur(),
            ])

            //Le bouton d'enregistrement / soumission
            ->add('enregistrer', SubmitType::class, [
                'label' => "invite_form_send_invite",
                'attr' => [
                    'class' => "btn btn-secondary",
                ],
            ])
            ->addEventListener(FormEvents::POST_SUBMIT, $this->ecouteurFormulaire->setUtilisateur())
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
