<?php

namespace App\Form;

use App\Entity\Piste;
use App\Entity\Tache;
use App\Entity\Client;
use App\Entity\Invite;
use App\Entity\Avenant;
use App\Entity\Classeur;
use App\Entity\Cotation;
use App\Entity\Document;
use App\Entity\Feedback;
use App\Entity\Paiement;
use App\Entity\Bordereau;
use App\Entity\Entreprise;
use App\Entity\Partenaire;
use App\Entity\PieceSinistre;
use App\Entity\CompteBancaire;
use App\Services\FormListenerFactory;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\AbstractType;
use App\Entity\OffreIndemnisationSinistre;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;

class DocumentType extends AbstractType
{
    public function __construct(
        private FormListenerFactory $ecouteurFormulaire,
        private TranslatorInterface $translatorInterface
    ) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', TextType::class, [
                'label' => "document_form_label_nom",
                'attr' => [
                    'placeholder' => "document_form_label_nom_placeholder",
                ],
            ])
            ->add('classeur', EntityType::class, [
                'class' => Classeur::class,
                'choice_label' => 'nom',
            ])
            // ->add('createdAt', null, [
            //     'widget' => 'single_text',
            // ])
            // ->add('updatedAt', null, [
            //     'widget' => 'single_text',
            // ])
            // ->add('invite', EntityType::class, [
            //     'class' => Invite::class,
            //     'choice_label' => 'id',
            // ])

            // ->add('pieceSinistre', EntityType::class, [
            //     'class' => PieceSinistre::class,
            //     'choice_label' => 'id',
            // ])
            // ->add('offreIndemnisationSinistre', EntityType::class, [
            //     'class' => OffreIndemnisationSinistre::class,
            //     'choice_label' => 'id',
            // ])
            // ->add('paiement', EntityType::class, [
            //     'class' => Paiement::class,
            //     'choice_label' => 'id',
            // ])
            // ->add('cotation', EntityType::class, [
            //     'class' => Cotation::class,
            //     'choice_label' => 'id',
            // ])
            // ->add('avenant', EntityType::class, [
            //     'class' => Avenant::class,
            //     'choice_label' => 'id',
            // ])
            // ->add('tache', EntityType::class, [
            //     'class' => Tache::class,
            //     'choice_label' => 'id',
            // ])
            // ->add('feedback', EntityType::class, [
            //     'class' => Feedback::class,
            //     'choice_label' => 'id',
            // ])
            // ->add('client', EntityType::class, [
            //     'class' => Client::class,
            //     'choice_label' => 'id',
            // ])
            // ->add('bordereau', EntityType::class, [
            //     'class' => Bordereau::class,
            //     'choice_label' => 'id',
            // ])
            // ->add('compteBancaire', EntityType::class, [
            //     'class' => CompteBancaire::class,
            //     'choice_label' => 'id',
            // ])
            // ->add('entreprise', EntityType::class, [
            //     'class' => Entreprise::class,
            //     'choice_label' => 'id',
            // ])
            // ->add('piste', EntityType::class, [
            //     'class' => Piste::class,
            //     'choice_label' => 'id',
            // ])
            // ->add('partenaire', EntityType::class, [
            //     'class' => Partenaire::class,
            //     'choice_label' => 'id',
            // ])
            //Le bouton d'enregistrement / soumission
            ->add('enregistrer', SubmitType::class, [
                'label' => "Enregistrer",
                'attr' => [
                    'class' => "btn btn-secondary",
                ],
            ])
            // ->addE.ventListener(FormEvents::POST_SUBMIT, $this->ecouteurFormulaire->setUtilisateur())
            ->addEventListener(FormEvents::POST_SUBMIT, $this->ecouteurFormulaire->timeStamps())
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Document::class,
        ]);
    }
}
