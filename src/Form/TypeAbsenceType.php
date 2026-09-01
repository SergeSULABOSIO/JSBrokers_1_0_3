<?php

namespace App\Form;

use App\Entity\TypeAbsence;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Formulaire de saisie/édition d'un type d'absence du cabinet.
 *
 * « Décompté du solde » est la case qui décide de tout : elle seule fait qu'une demande
 * approuvée touche le compteur. Son libellé le dit en toutes lettres — une maladie
 * décomptée par inadvertance ne se voit qu'au moment où le solde manque.
 */
class TypeAbsenceType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('code', TextType::class, [
                'label' => 'Code',
                'attr'  => ['placeholder' => 'Ex. CA'],
                'help'  => 'Abréviation courte, utilisée dans les listes et le calendrier.',
            ])
            ->add('libelle', TextType::class, [
                'label' => 'Libellé',
                'attr'  => ['placeholder' => 'Ex. Congé annuel'],
            ])
            ->add('decompte', CheckboxType::class, [
                'label'    => 'Décompté du solde de congés',
                'required' => false,
                'help'     => "Décoché, le type est enregistré pour le calendrier et l'historique sans jamais toucher au compteur (maladie, événement familial…).",
            ])
            ->add('justificatifRequis', CheckboxType::class, [
                'label'    => 'Justificatif obligatoire',
                'required' => false,
                'help'     => 'Une pièce jointe sera exigée à la soumission de la demande.',
            ])
            ->add('plafondParDemande', NumberType::class, [
                'label'    => 'Plafond par demande (jours)',
                'scale'    => 1,
                'required' => false,
                'help'     => 'Laisser vide pour ne poser aucun plafond.',
                'attr'     => ['placeholder' => 'Ex. 10'],
            ])
            ->add('autoriseDemiJournee', CheckboxType::class, [
                'label'    => 'Autoriser les demi-journées',
                'required' => false,
            ])
            ->add('couleur', TextType::class, [
                'label'    => 'Couleur au calendrier',
                'required' => false,
                'attr'     => ['placeholder' => 'Ex. #0047AB'],
            ])
            ->add('actif', CheckboxType::class, [
                'label'    => 'Type actif (proposé à la saisie)',
                'required' => false,
                'help'     => "Un type déjà utilisé se désactive, il ne se supprime pas : l'historique doit rester lisible.",
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => TypeAbsence::class,
            'csrf_protection'    => false,
            'allow_extra_fields' => true,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}
