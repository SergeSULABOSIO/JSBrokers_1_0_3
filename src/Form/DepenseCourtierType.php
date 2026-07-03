<?php

namespace App\Form;

use App\Entity\ChargeCourtier;
use App\Entity\Depense;
use App\Entity\DepenseCourtier;
use App\Entity\Entreprise;
use App\Entity\Fournisseur;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Formulaire de saisie/édition d'une dépense du courtier, rattachée à une charge
 * du workspace. Le choix de charge est SCOPÉ à l'entreprise active (connectedTo),
 * comme les champs d'autocomplétion du workspace — pas de fuite inter-entreprises.
 */
class DepenseCourtierType extends AbstractType
{
    public function __construct(
        private Security $security,
    ) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('charge', EntityType::class, [
                'class'         => ChargeCourtier::class,
                'label'         => 'Type de charge',
                'choice_label'  => fn (ChargeCourtier $c) => (string) $c,
                'query_builder' => function (EntityRepository $er): QueryBuilder {
                    /** @var Utilisateur $user */
                    $user = $this->security->getUser();
                    /** @var Entreprise|null $entreprise */
                    $entreprise = $user?->getConnectedTo();

                    return $er->createQueryBuilder('c')
                        ->where('c.entreprise = :eseId')
                        ->andWhere('c.actif = true')
                        ->setParameter('eseId', $entreprise?->getId() ?? -1)
                        ->orderBy('c.libelle', 'ASC');
                },
                'placeholder'   => 'Choisir une charge…',
            ])
            ->add('dateDepense', DateType::class, [
                'label'  => 'Date de la dépense',
                'widget' => 'single_text',
            ])
            ->add('montant', NumberType::class, [
                'label' => 'Montant (TTC)',
                'scale' => 2,
                'attr'  => ['placeholder' => 'Ex. 250.00'],
            ])
            ->add('tauxTva', NumberType::class, [
                'label'    => 'TVA déductible (%)',
                'scale'    => 2,
                'required' => false,
                'help'     => 'Taux de TVA récupérable sur cette dépense. 0 si la TVA n\'est pas déductible (montant comptabilisé en charge TTC).',
                'attr'     => ['placeholder' => 'Ex. 16'],
            ])
            ->add('fournisseur', EntityType::class, [
                'class'         => Fournisseur::class,
                'label'         => 'Fournisseur enregistré',
                'required'      => false,
                'choice_label'  => fn (Fournisseur $f) => (string) $f,
                'query_builder' => function (EntityRepository $er): QueryBuilder {
                    /** @var Utilisateur $user */
                    $user = $this->security->getUser();
                    /** @var Entreprise|null $entreprise */
                    $entreprise = $user?->getConnectedTo();

                    return $er->createQueryBuilder('f')
                        ->where('f.entreprise = :eseId')
                        ->andWhere('f.actif = true')
                        ->setParameter('eseId', $entreprise?->getId() ?? -1)
                        ->orderBy('f.nom', 'ASC');
                },
                'placeholder'   => 'Aucun (bénéficiaire occasionnel)…',
                'help'          => 'Opérateur économique du référentiel Fournisseurs. Laissez vide et utilisez le champ ci-dessous pour un bénéficiaire occasionnel (personne physique…).',
            ])
            ->add('beneficiaire', TextType::class, [
                'label'    => 'Bénéficiaire occasionnel (texte libre)',
                'required' => false,
                'attr'     => ['placeholder' => 'Ex. remboursement à un particulier…'],
            ])
            ->add('reference', TextType::class, [
                'label'    => 'Référence de la pièce (facture / reçu)',
                'required' => false,
                'attr'     => ['placeholder' => 'Ex. FACT-2026-014'],
            ])
            ->add('moyenPaiement', ChoiceType::class, [
                'label'   => 'Moyen de paiement',
                'choices' => array_flip(Depense::MOYENS_PAIEMENT),
            ])
            ->add('statut', ChoiceType::class, [
                'label'   => 'Statut',
                'choices' => array_flip(Depense::STATUTS),
                'help'    => 'Seules les dépenses « payées » décaissent la trésorerie ; « annulée » exclut du résultat.',
            ])
            ->add('description', TextareaType::class, [
                'label'    => 'Description (optionnel)',
                'required' => false,
                'attr'     => ['rows' => 2, 'placeholder' => 'Détail de la dépense…'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => DepenseCourtier::class,
            'csrf_protection' => false,
            'allow_extra_fields' => true,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}
