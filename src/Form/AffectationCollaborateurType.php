<?php

namespace App\Form;

use App\Entity\Utilisateur;
use App\Enum\Departement;
use App\Enum\FonctionCollaborateur;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Affectation d'un collaborateur à un département et à une fonction.
 * Réservé au super-admin (cf. DepartementController). Champs mappés sur les enums.
 */
class AffectationCollaborateurType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('departement', EnumType::class, [
                'class'        => Departement::class,
                'label'        => 'Département',
                'choice_label' => fn (Departement $d): string => $d->label(),
                'placeholder'  => 'Sélectionner un département…',
                'required'     => false,
                'attr'         => ['data-icon' => 'action:role'],
            ])
            ->add('fonction', EnumType::class, [
                'class'        => FonctionCollaborateur::class,
                'label'        => 'Fonction',
                'choice_label' => fn (FonctionCollaborateur $f): string => $f->label() . ' — ' . $f->niveauLabel(),
                'placeholder'  => 'Sélectionner une fonction…',
                'required'     => false,
                'attr'         => ['data-icon' => 'role'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Utilisateur::class]);
    }
}
