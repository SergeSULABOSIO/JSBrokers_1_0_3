<?php

namespace App\Form;

use App\Entity\Bordereau;
use App\Services\ServiceMonnaies;
use App\Services\FormListenerFactory;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;

class BordereauType extends AbstractType
{
    public function __construct(
        private FormListenerFactory $ecouteurFormulaire,
        private TranslatorInterface $translatorInterface,
        private ServiceMonnaies $serviceMonnaies
    ) {}
    
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('type', ChoiceType::class, [
                'label' => "Type",
                'required' => true,
                'expanded' => true,
                'label_html' => true,
                'choices'  => [
                    "Bordereau de production" => Bordereau::TYPE_BOREDERAU_PRODUCTION,
                ],
                'choice_label' => function ($choice, $key, $value) {
                    if ($choice === Bordereau::TYPE_BOREDERAU_PRODUCTION) {
                        return '<div><strong>Bordereau de production</strong><div class="text-muted small">Récapitulatif des primes émises, renouvellements et avenants.</div></div>';
                    }
                    return '<div><strong>' . $key . '</strong></div>';
                },
            ])
            ->add('nom', TextType::class, [
                'label' => "Nom",
                'attr' => [
                    'placeholder' => "Nom",
                ],
            ])
            ->add('assureur', AssureurAutocompleteField::class, [
                // Ligne 3: Assureur
                'label' => "Assureur",
                'placeholder' => "Sélectionnez un assureur",
            ])
            ->add('reference', TextType::class, [
                'label' => "Référence",
                'attr' => [
                    'placeholder' => "Référence",
                ],
            ])
            // Ligne 4: Référence du bordereau (8/12) et Date de réception (4/12)
            ->add('receivedAt', DateTimeType::class, [
                'label' => "Date de réception",
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'html5' => true,
            ])
            // Ligne 5: Statut (HTML enrichi)
            ->add('statut', ChoiceType::class, [
                'label' => 'Statut',
                'expanded' => true,
                'required' => true,
                'label_html' => true,
                'choices' => [
                    'À vérifier' => Bordereau::STATUT_A_VERIFIER,
                    'Contesté' => Bordereau::STATUT_CONTESTE,
                    'Validé' => Bordereau::STATUT_VALIDE,
                    'Payé' => Bordereau::STATUT_PAYE,
                    'Partiellement Payé' => Bordereau::STATUT_PARTIELLEMENT_PAYE,
                    'Annulé' => Bordereau::STATUT_ANNULE,
                ],
                'choice_label' => function ($choice, $key, $value) {
                    $descriptions = [
                        Bordereau::STATUT_A_VERIFIER => 'Reçu, en attente de vérification par le courtier.',
                        Bordereau::STATUT_CONTESTE => 'Le courtier a signalé une anomalie.',
                        Bordereau::STATUT_VALIDE => 'Vérifié et conforme, en attente de paiement.',
                        Bordereau::STATUT_PAYE => 'L\'assureur a payé la totalité de la commission.',
                        Bordereau::STATUT_PARTIELLEMENT_PAYE => 'Un paiement partiel de la commission a été reçu.',
                        Bordereau::STATUT_ANNULE => 'Le bordereau a été annulé.',
                    ];
                    return '<div><strong>' . $key . '</strong><div class="text-muted small">' . ($descriptions[$value] ?? '') . '</div></div>';
                },
            ])
            // Ligne 6: Période début (6/12) et Période fin (6/12)
            ->add('periodeDebut', DateTimeType::class, [
                'label' => "Période Début",
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
            ->add('periodeFin', DateTimeType::class, [
                'label' => "Période Fin",
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
            ->add('documents', CollectionType::class, [
                'label' => "Documents",
                'entry_type' => DocumentType::class,
                'by_reference' => false,
                'allow_add' => true,
                'allow_delete' => true,
                'entry_options' => [
                    'label' => false,
                ],
                // La configuration du widget est maintenant gérée par le BordereauFormCanvasProvider.
                'mapped' => false,
            ])
            ->add('operations', CollectionType::class, [
                'label' => "Opérations concernées par ce bordereau",
                'entry_type' => OperationType::class,
                'by_reference' => false,
                'allow_add' => true,
                'allow_delete' => true,
                'entry_options' => [
                    'label' => false,
                ],
                // La configuration du widget est maintenant gérée par le BordereauFormCanvasProvider.
                'mapped' => false,
            ])
            // ->addEventListener(FormEvents::POST_SUBMIT, $this->ecouteurFormulaire->setUtilisateur())
            ->addEventListener(FormEvents::POST_SUBMIT, $this->ecouteurFormulaire->updateTimestamp())
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Bordereau::class,
            'csrf_protection' => false,
            'allow_extra_fields' => true,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}
