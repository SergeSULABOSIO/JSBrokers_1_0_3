<?php

namespace App\Form;

use App\Constantes\Constante;
use App\Entity\CompteBancaire;
use App\Entity\Paiement;
use App\Entity\FactureCommission;
use App\Services\FormListenerFactory;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\AbstractType;
use App\Entity\OffreIndemnisationSinistre;
use App\Services\ServiceMonnaies;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;

class PaiementType extends AbstractType
{
    public function __construct(
        private FormListenerFactory $ecouteurFormulaire,
        private TranslatorInterface $translatorInterface,
        private ServiceMonnaies $serviceMonnaies,
        private Constante $constante,
    ) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('montant', MoneyType::class, [
                'label' => "Montant en cours de paiement",
                'currency' => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                'required' => false,
                'grouping' => true,
                'attr' => [
                    'placeholder' => "Montant",
                ],
            ])
            ->add('reference', TextType::class, [
                'label' => "Référence du paiement",
                'required' => false,
                'attr' => [
                    'placeholder' => "Référence",
                ],
            ])
            ->add('description', TextareaType::class, [
                'label' => "Description",
                'attr' => [
                    'placeholder' => "Description",
                ],
            ])
            ->add('paidAt', DateTimeType::class, [
                'label' => "Date de paiement",
                'widget' => 'single_text',
            ])
            ->add('CompteBancaire', EntityType::class, [
                'label' => "Compte bancaire",
                'required' => true,
                'class' => CompteBancaire::class,
                'choice_label' => 'nom',
            ])
            ->add('preuves', CollectionType::class, [
                'label' => 'Preuves de paiement',
                'help' => 'Documents justificatifs (avis de débit, etc.).',
                'entry_type' => DocumentType::class,
                'by_reference' => false,
                'allow_add' => true,
                'allow_delete' => true,
                'entry_options' => ['label' => false],
                'mapped' => false, // On continue avec notre logique API par élément
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Paiement::class,
            'csrf_protection' => false,
            'allow_extra_fields' => true,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}
