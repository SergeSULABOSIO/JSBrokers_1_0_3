<?php

namespace App\Form;

use App\Services\FormListenerFactory;
use Symfony\Component\Form\AbstractType;
use App\Entity\OffreIndemnisationSinistre;
use App\Services\ServiceMonnaies;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use App\Form\DocumentType;
use App\Form\TacheType;
use App\Form\PaiementType; // Ne pas oublier d'importer le nouveau FormType


class OffreIndemnisationSinistreType extends AbstractType
{
    public function __construct(
        private FormListenerFactory $ecouteurFormulaire,
        private ServiceMonnaies $serviceMonnaies,
        private TranslatorInterface $translatorInterface
    ) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', TextType::class, [
                'label' => "Nom",
                'required' => true,
                'attr' => [
                    'placeholder' => "Nom",
                ],
            ])
            ->add('beneficiaire', TextType::class, [
                'help' => "Nom du bénéficiaire ou le client lui-même.",
                'label' => "Bénéficiaire",
                'required' => true,
                'attr' => [
                    'placeholder' => "Bénéficiaire",
                ],
            ])
            ->add('franchiseAppliquee', MoneyType::class, [
                'label' => "Franchise appliquée",
                'currency' => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                'grouping' => true,
                'attr' => [
                    'placeholder' => "Franchise",
                ],
            ])
            ->add('montantPayable', MoneyType::class, [
                'label' => "Montant payable / Compensation",
                'help' => "Solde payable après application de la franchise au coût / valeure totale de reparation.",
                'currency' => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                'grouping' => true,
                'attr' => [
                    'placeholder' => "Montant payable",
                ],
            ])
            ->add('referenceBancaire', TextType::class, [
                'help' => "Référence bancaire du bénéficiaire.",
                'label' => "Référence bancaire",
                'required' => true,
                'attr' => [
                    'placeholder' => "Référence bancaire",
                ],
            ])
            ->add('documents', CollectionType::class, [
                'label' => 'Documents justificatifs',
                'help' => 'Scans, photos, PDF, etc.',
                'entry_type' => DocumentType::class,
                'by_reference' => false,
                'allow_add' => true,
                'allow_delete' => true,
                'entry_options' => ['label' => false],
                'mapped' => false,
            ])
            ->add('taches', CollectionType::class, [
                'label' => 'Tâches associées',
                'help' => 'Actions de suivi pour cette offre.',
                'entry_type' => TacheType::class,
                'by_reference' => false,
                'allow_add' => true,
                'allow_delete' => true,
                'entry_options' => ['label' => false],
                'mapped' => false,
            ])
            ->add('paiements', CollectionType::class, [
                'label' => 'Paiements effectués',
                'help' => 'Liste des versements liés à cette offre.',
                'entry_type' => PaiementType::class,
                'by_reference' => false,
                'allow_add' => true,
                'allow_delete' => true,
                'entry_options' => ['label' => false],
                'mapped' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => OffreIndemnisationSinistre::class,
            'csrf_protection' => false,
            'allow_extra_fields' => true,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}
