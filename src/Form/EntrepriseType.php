<?php

namespace App\Form;

use App\Entity\Entreprise;
use App\Services\ServiceMonnaies;
use App\Services\FormListenerFactory;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;

/**
 * Formulaire de création / édition d'une entreprise.
 *
 * IMPORTANT : ce formulaire ne porte QUE les attributs propres à l'entité
 * Entreprise. Les entités liées (clients, risques, taxes, comptes, monnaies,
 * partenaires, invités, groupes…) NE sont PAS des collections de Entreprise —
 * elles sont gérées indépendamment dans l'espace de travail. Les anciens champs
 * de collection référençaient des propriétés inexistantes et provoquaient une
 * erreur 500 au rendu (PropertyAccess) ; ils ont été retirés.
 */
class EntrepriseType extends AbstractType
{
    public function __construct(
        private FormListenerFactory $ecouteurFormulaire,
        private TranslatorInterface $translatorInterface,
        private ServiceMonnaies $serviceMonnaies,
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
            ->add('capitalSociale', MoneyType::class, [
                'label' => "Capital social",
                'currency' => $this->serviceMonnaies->getCodeMonnaieLocale(),
                'grouping' => true,
                'required' => false,
                'attr' => [
                    'placeholder' => "Capital sociale",
                ],
            ])
            ->add('siteweb', UrlType::class, [
                'label' => "Site Web",
                'required' => false,
                'attr' => [
                    'placeholder' => "Site web",
                ],
            ])
            ->add('thumbnailFile', FileType::class, [
                'label' => "entreprise_form_label_profile",
                'required' => false,
            ])
            ->add('enregistrer', SubmitType::class, [
                'label' => "commom_save",
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
