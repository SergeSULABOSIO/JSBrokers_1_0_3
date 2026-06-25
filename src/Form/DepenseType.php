<?php

namespace App\Form;

use App\Entity\Charge;
use App\Entity\Depense;
use App\Repository\ChargeRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Formulaire de saisie/édition d'une dépense rattachée à un type de charge.
 */
class DepenseType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('charge', EntityType::class, [
                'class'         => Charge::class,
                'label'         => 'Type de charge',
                'choice_label'  => fn (Charge $c) => (string) $c,
                'query_builder' => fn (ChargeRepository $r) => $r->createQueryBuilder('c')
                    ->andWhere('c.actif = true')
                    ->orderBy('c.libelle', 'ASC'),
                'placeholder'   => 'Choisir une charge…',
                'attr'          => ['data-icon' => 'charge'],
            ])
            ->add('dateDepense', DateType::class, [
                'label'  => 'Date de la dépense',
                'widget' => 'single_text',
                'attr'   => ['data-icon' => 'action:calendar'],
            ])
            ->add('montant', NumberType::class, [
                'label' => 'Montant (TTC)',
                'scale' => 2,
                'attr'  => ['placeholder' => 'Ex. 250.00', 'data-icon' => 'monnaie'],
            ])
            ->add('devise', TextType::class, [
                'label' => 'Devise',
                'attr'  => ['placeholder' => 'USD', 'maxlength' => 3, 'style' => 'text-transform:uppercase;', 'data-icon' => 'monnaie'],
            ])
            ->add('beneficiaire', TextType::class, [
                'label'    => 'Bénéficiaire / fournisseur',
                'required' => false,
                'attr'     => ['placeholder' => 'Ex. Bailleur, hébergeur, prestataire…', 'data-icon' => 'partenaire'],
            ])
            ->add('reference', TextType::class, [
                'label'    => 'Référence de la pièce (facture / reçu)',
                'required' => false,
                'attr'     => ['placeholder' => 'Ex. FACT-2026-014', 'data-icon' => 'document'],
            ])
            ->add('moyenPaiement', ChoiceType::class, [
                'label'   => 'Moyen de paiement',
                'choices' => array_flip(Depense::MOYENS_PAIEMENT),
                'attr'    => ['data-icon' => 'compte-bancaire'],
            ])
            ->add('statut', ChoiceType::class, [
                'label'   => 'Statut',
                'choices' => array_flip(Depense::STATUTS),
                'help'    => 'Seules les dépenses « payées » décaissent la trésorerie ; « annulée » exclut du résultat.',
                'attr'    => ['data-icon' => 'action:filter'],
            ])
            ->add('description', TextareaType::class, [
                'label'    => 'Description (optionnel)',
                'required' => false,
                'attr'     => ['rows' => 2, 'placeholder' => 'Détail de la dépense…', 'data-icon' => 'action:description'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Depense::class]);
    }
}
