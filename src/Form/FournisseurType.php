<?php

namespace App\Form;

use App\Entity\Fournisseur;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Formulaire de saisie/édition d'un fournisseur professionnel du cabinet
 * (référentiel achats / services généraux), avec son dossier documentaire
 * (contrats, agréments, preuves de partenariat…).
 */
class FournisseurType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', TextType::class, [
                'label' => 'Nom / raison sociale',
                'attr'  => ['placeholder' => 'Ex. Global Broadband Services'],
            ])
            ->add('personneContact', TextType::class, [
                'label'    => 'Personne de contact',
                'required' => false,
                'attr'     => ['placeholder' => 'Ex. Jean Kabila'],
            ])
            ->add('telephone', TextType::class, [
                'label'    => 'Téléphone',
                'required' => false,
                'attr'     => ['placeholder' => 'Ex. +243 999 000 000'],
            ])
            ->add('email', EmailType::class, [
                'label'    => 'E-mail',
                'required' => false,
                'attr'     => ['placeholder' => 'Ex. contact@fournisseur.com'],
            ])
            ->add('adresse', TextType::class, [
                'label'    => 'Adresse',
                'required' => false,
                'attr'     => ['placeholder' => 'Ex. 12 av. du Commerce, Gombe'],
            ])
            ->add('rccm', TextType::class, [
                'label'    => 'RCCM (registre du commerce)',
                'required' => false,
                'attr'     => ['placeholder' => 'Ex. CD/KNG/RCCM/23-B-01234'],
            ])
            ->add('numimpot', TextType::class, [
                'label'    => 'Numéro d\'imposition (NIF)',
                'required' => false,
                'attr'     => ['placeholder' => 'Ex. A1234567B'],
            ])
            ->add('description', TextareaType::class, [
                'label'    => 'Description (optionnel)',
                'required' => false,
                'attr'     => ['rows' => 2, 'placeholder' => 'Nature des biens / services fournis…'],
            ])
            ->add('actif', CheckboxType::class, [
                'label'    => 'Fournisseur actif (proposé à la saisie des dépenses)',
                'required' => false,
            ])
            ->add('documents', CollectionType::class, [
                'label' => 'Dossier fournisseur',
                'help' => 'Pièces justificatives : contrat, agrément, preuve de partenariat…',
                'entry_type' => DocumentType::class,
                'by_reference' => false,
                'allow_add' => true,
                'required' => false,
                'allow_delete' => true,
                'entry_options' => ['label' => false],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Fournisseur::class,
            'csrf_protection' => false,
            'allow_extra_fields' => true,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}
