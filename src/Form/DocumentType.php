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
                'label' => "nom",
                'attr' => [
                    'placeholder' => "nom",
                ],
            ])
            ->add('classeur', EntityType::class, [
                'label' => "Classeur",
                'class' => Classeur::class,
                'required' => false,
                'choice_label' => 'nom',
            ])
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
            'parent_object' => null, // l'objet parent
        ]);
    }
}
