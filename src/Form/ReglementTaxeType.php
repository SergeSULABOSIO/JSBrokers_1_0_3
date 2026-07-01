<?php

namespace App\Form;

use App\Entity\ReglementTaxe;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Formulaire d'enregistrement d'un reversement de TVA à l'autorité fiscale.
 */
class ReglementTaxeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $mois = [];
        foreach (['Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'] as $i => $nom) {
            $mois[$nom] = $i + 1;
        }

        $builder
            ->add('autorite', TextType::class, [
                'label' => "Autorité fiscale",
                'attr'  => ['placeholder' => 'Ex. Direction Générale des Impôts (DGI)', 'data-icon' => 'autorite-fiscale'],
            ])
            ->add('annee', IntegerType::class, [
                'label' => 'Année de la période',
                'attr'  => ['placeholder' => 'Ex. 2026', 'data-icon' => 'action:calendar'],
            ])
            ->add('mois', ChoiceType::class, [
                'label'   => 'Mois de la période',
                'choices' => $mois,
                'attr'    => ['data-icon' => 'action:calendar'],
            ])
            ->add('montant', NumberType::class, [
                'label' => 'Montant reversé (USD)',
                'scale' => 2,
                'attr'  => ['placeholder' => 'Ex. 1500.00', 'data-icon' => 'monnaie'],
            ])
            ->add('datePaiement', DateType::class, [
                'label'  => 'Date du paiement',
                'widget' => 'single_text',
                'attr'   => ['data-icon' => 'action:calendar'],
            ])
            ->add('moyenPaiement', ChoiceType::class, [
                'label'   => 'Trésorerie débitée',
                'choices' => ['Banque' => ReglementTaxe::MOYEN_BANQUE, 'Caisse' => ReglementTaxe::MOYEN_CAISSE],
                'attr'    => ['data-icon' => 'compte-bancaire'],
            ])
            ->add('reference', TextType::class, [
                'label'    => 'Référence (facultatif)',
                'required' => false,
                'attr'     => ['placeholder' => 'N° de déclaration / quittance', 'data-icon' => 'document'],
            ])
            ->add('note', TextareaType::class, [
                'label'    => 'Note (facultatif)',
                'required' => false,
                'attr'     => ['rows' => 2, 'data-icon' => 'action:description'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ReglementTaxe::class]);
    }
}
