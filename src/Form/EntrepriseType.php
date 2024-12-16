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
use Symfony\Contracts\Translation\TranslatorInterface;

class EntrepriseType extends AbstractType
{
    public function __construct(
        private FormListenerFactory $ecouteurFormulaire,
        private TranslatorInterface $translatorInterface
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
                'label' => "entreprise_form_label_fin_id_number",
                'attr' => [
                    'placeholder' => "entreprise_form_label_fin_id_number_placeholder",
                ],
            ])
            ->add('thumbnailFile', FileType::class, [
                'label' => "entreprise_form_label_profile",
                'required' => false,
                
            ])
            //les collections des paramètres de l'entreprise. Paramètres que tous les utlisateurs utilisent
            ->add('monnaies', CollectionType::class, [
                'label' => "entreprise_form_label_currencies",
                'entry_type' => MonnaieType::class,
                'by_reference' => false,
                'allow_add' => true,
                'allow_delete' => true,
                'entry_options' => [
                    'label' => false,
                ],
                'attr' => [
                    'data-controller' => 'form-collection-monnaies',
                    'data-form-collection-monnaies-add-label-value' => $this->translatorInterface->trans("commom_add"),//'Ajouter',
                    'data-form-collection-monnaies-delete-label-value' => $this->translatorInterface->trans("commom_delete"),
                ],
            ])
            //Le bouton d'enregistrement / soumission
            ->add('enregistrer', SubmitType::class, [
                'label' => "entreprise_form_button_save_company",
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
            'data_class' => Entreprise::class,
        ]);
    }
}
