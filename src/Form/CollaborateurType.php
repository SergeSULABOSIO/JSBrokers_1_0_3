<?php

namespace App\Form;

use App\Entity\Utilisateur;
use App\Enum\Departement;
use App\Enum\FonctionCollaborateur;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Formulaire de création/édition d'un collaborateur (agent) JS Brokers.
 * Le mot de passe et le rôle super-admin sont non mappés : gérés par le contrôleur.
 */
class CollaborateurType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $isEdit = $options['is_edit'];

        $builder
            ->add('nom', TextType::class, [
                'label' => 'Nom complet',
                'attr'  => ['placeholder' => 'Ex. Jean Dupont', 'data-icon' => 'utilisateur'],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Adresse e-mail',
                'attr'  => ['placeholder' => 'agent@js-brokers.com', 'autocomplete' => 'off', 'data-icon' => 'contact'],
            ])
            ->add('plainPassword', PasswordType::class, [
                'label'       => $isEdit ? 'Nouveau mot de passe (laisser vide pour conserver)' : 'Mot de passe',
                'mapped'      => false,
                'required'    => !$isEdit,
                'attr'        => ['autocomplete' => 'new-password', 'placeholder' => '••••••••', 'data-icon' => 'action:password'],
                'constraints' => $isEdit ? [] : [
                    new NotBlank(message: 'Veuillez définir un mot de passe.'),
                    new Length(min: 6, minMessage: 'Au moins {{ limit }} caractères.'),
                ],
            ]);

        // L'attribution du rôle super-admin n'est proposée qu'aux super-admins.
        if ($options['can_grant_super']) {
            $builder->add('superAdmin', CheckboxType::class, [
                'label'    => 'Super-administrateur (gère la tarification et les collaborateurs)',
                'mapped'   => false,
                'required' => false,
                'data'     => $options['is_super'],
            ]);
        }

        // Affectation (département + fonction) : réservée au super-admin. Les champs
        // sont mappés sur l'entité ; le périmètre d'accès en découle automatiquement.
        if ($options['can_assign']) {
            $builder
                ->add('departement', EnumType::class, [
                    'class'        => Departement::class,
                    'label'        => 'Département',
                    'choice_label' => fn (Departement $d): string => $d->label(),
                    'placeholder'  => 'Non affecté (accès complet)',
                    'required'     => false,
                    'attr'         => ['data-icon' => 'action:role'],
                ])
                ->add('fonction', EnumType::class, [
                    'class'        => FonctionCollaborateur::class,
                    'label'        => 'Fonction',
                    'choice_label' => fn (FonctionCollaborateur $f): string => $f->label() . ' — ' . $f->niveauLabel(),
                    'placeholder'  => 'Non définie',
                    'required'     => false,
                    'attr'         => ['data-icon' => 'role'],
                ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'      => Utilisateur::class,
            'is_edit'         => false,
            'can_grant_super' => false,
            'is_super'        => false,
            'can_assign'      => false,
        ]);
        $resolver->setAllowedTypes('is_edit', 'bool');
        $resolver->setAllowedTypes('can_grant_super', 'bool');
        $resolver->setAllowedTypes('is_super', 'bool');
        $resolver->setAllowedTypes('can_assign', 'bool');
    }
}
