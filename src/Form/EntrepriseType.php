<?php

namespace App\Form;

use App\Entity\Entreprise;
use App\Services\FormListenerFactory;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;

class EntrepriseType extends AbstractType
{
    public function __construct(
        private FormListenerFactory $ecouteurFormulaire
    ) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', TextType::class, [
                'label' => "entreprise_form_label_nom",
                'attr' => [
                    'placeholder' => "entreprise_form_label_nom_placeholder",
                ],
            ])
            ->add('adresse', TextType::class, [
                'label' => "entreprise_form_label_location",
                'attr' => [
                    'placeholder' => "entreprise_form_label_location_placeholder",
                ],
            ])
            ->add('licence', TextType::class, [
                'label' => "entreprise_form_label_license",
                'attr' => [
                    'placeholder' => "entreprise_form_label_license_placeholder",
                ],
            ])
            ->add('telephone', TextType::class, [
                'label' => "entreprise_form_label_phone_number",
                'attr' => [
                    'placeholder' => "entreprise_form_label_phone_number_placeholder",
                ],
            ])
            ->add('rccm', TextType::class, [
                'label' => "entreprise_form_label_trade_register_number",
                'attr' => [
                    'placeholder' => "entreprise_form_label_trade_register_placeholder",
                ],
            ])
            ->add('idnat', TextType::class, [
                'label' => "Numéro d'identification nationale",
                'attr' => [
                    'placeholder' => "IDNAT",
                ],
            ])
            ->add('numimpot', TextType::class, [
                'label' => "Num d'impôt (NIF)",
                'attr' => [
                    'placeholder' => "NIF",
                ],
            ])
            ->add('thumbnailFile', FileType::class, [
                'label' => "Photo de profile",
                'required' => false,
            ])
            ->add('monnaies', CollectionType::class, [
                'label' => "Liste des monnaies",
                'entry_type' => MonnaieType::class,
                'by_reference' => false,
                'allow_add' => true,
                'allow_delete' => true,
                'entry_options' => [
                    'label' => false,
                ],
                'attr' => [
                    'data-controller' => 'form-collection-monnaies',
                    'data-form-collection-monnaies-add-label-value' => 'Ajouter Une Autre Monnaie',
                    'data-form-collection-monnaies-delete-label-value' => 'Supprimer',
                ],
            ])
            //Le bouton d'enregistrement / soumission
            ->add('enregistrer', SubmitType::class, [
                'label' => "Enregistrer"
            ])
            ->addEventListener(FormEvents::POST_SUBMIT, $this->ecouteurFormulaire->setUtilisateur())
            ->addEventListener(FormEvents::POST_SUBMIT, $this->ecouteurFormulaire->timeStamps())
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Entreprise::class,
        ]);
    }
}
