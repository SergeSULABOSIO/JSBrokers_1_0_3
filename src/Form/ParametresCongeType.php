<?php

namespace App\Form;

use App\Entity\ParametresConge;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Réglages du module de congés.
 *
 * CHAQUE CONTRÔLE PORTE SA PROPRE DÉSACTIVATION, et son libellé le dit : un cabinet qui
 * ne veut pas d'un contrôle doit pouvoir l'éteindre franchement, sans quoi il apprendra
 * à le contourner — et un contournement appris ne se désapprend pas.
 */
class ParametresCongeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('delaiPreavisJours', IntegerType::class, [
                'label' => 'Délai de préavis (jours ouvrables)',
                'help'  => "Délai minimal entre la soumission et le premier jour d'absence. 0 désactive le contrôle. Un valideur peut toujours passer outre.",
            ])
            ->add('maxAbsentsSimultanes', IntegerType::class, [
                'label'    => "Absents simultanés maximum par équipe",
                'required' => false,
                'help'     => "Compte les collaborateurs partageant le même responsable. Laisser vide désactive le contrôle. Un valideur peut passer outre.",
            ])
            ->add('relanceApresJours', IntegerType::class, [
                'label' => 'Relancer les valideurs après (jours ouvrables)',
                'help'  => "Une demande sans décision passé ce délai déclenche un rappel. 0 désactive les relances.",
            ])
            ->add('dotationAnnuelle', NumberType::class, [
                'label' => 'Dotation annuelle (jours)',
                'scale' => 1,
                'help'  => "Droits crédités à un nouveau collaborateur, au prorata de ses mois de présence.",
            ])
            ->add('seuilAlerteReport', NumberType::class, [
                'label' => "Alerte de report (multiple de la dotation)",
                'scale' => 2,
                'help'  => "Le report est sans limite de durée : au-delà de ce multiple, le propriétaire est averti. Un solde qui s'accumule indéfiniment est une dette que personne ne regarde.",
            ])
            ->add('periodesBlocage', CollectionType::class, [
                'label'         => 'Périodes de blocage',
                'help'          => "Clôture d'exercice, campagne de renouvellement : aucun congé ne peut y être posé sans l'accord d'un valideur.",
                'entry_type'    => PeriodeBlocageType::class,
                'by_reference'  => false,
                'allow_add'     => true,
                'required'      => false,
                'allow_delete'  => true,
                'entry_options' => ['label' => false],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => ParametresConge::class,
            'csrf_protection'    => false,
            'allow_extra_fields' => true,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}
