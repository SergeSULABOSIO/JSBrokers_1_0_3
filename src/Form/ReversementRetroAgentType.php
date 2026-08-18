<?php

namespace App\Form;

use App\Entity\Avenant;
use App\Entity\CompteBancaire;
use App\Entity\Invite;
use App\Entity\ReversementRetroAgent;
use App\Services\ServiceMonnaies;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Reversement d'une rétrocommission à un AGENT INTERNE, au titre d'UNE affaire.
 *
 * ── POURQUOI CE FORMULAIRE EXISTE ALORS QUE LA SAISIE PASSE PAR UN PICKER ───────────
 * À l'écran, le geste courant est le PICKER de reversement : il liste les affaires
 * exigibles et en règle plusieurs d'un coup. Ce FormType, lui, est ce que le moteur de
 * mutation de l'assistant EXIGE — son dry-run construit le formulaire de l'entité pour
 * valider les champs d'une opération avant de rien écrire. Sans lui,
 * signaler_reversement_retro_agent serait refusé par le moteur.
 *
 * Il sert donc deux maîtres, et c'est voulu : une entité mutable par Ket doit avoir son
 * FormType, sinon la parité lecture/écriture est un vœu.
 *
 * ── PAS DE COMPTE BANCAIRE OBLIGATOIRE ──────────────────────────────────────────────
 * Nul = espèces. Le service comptable en dérive le compte de trésorerie (521 si un compte
 * est renseigné, 571 sinon) — même convention que l'entité Paiement, à ne pas dédoubler.
 */
class ReversementRetroAgentType extends AbstractType
{
    public function __construct(
        private ServiceMonnaies $serviceMonnaies,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var ReversementRetroAgent|null $reversement */
        $reversement = $builder->getData();
        $isCreationMode = !$reversement || null === $reversement->getId();

        if ($isCreationMode && $reversement && !$reversement->getReference()) {
            $reversement->setReference('RETRO-' . (new \DateTime())->format('dmY-His'));
        }

        $builder
            ->add('agent', InviteAutocompleteField::class, [
                'label' => 'Agent bénéficiaire',
                'help' => "L'agent à qui le cabinet reverse une part de sa commission. "
                    . "Il n'est pas nécessairement le gestionnaire de l'affaire.",
                'class' => Invite::class,
                'required' => true,
            ])
            ->add('avenant', AvenantAutocompleteField::class, [
                'label' => 'Affaire réglée',
                'help' => "La police au titre de laquelle ce versement est fait. "
                    . "Le solde se suit affaire par affaire.",
                'class' => Avenant::class,
                'required' => true,
            ])
            ->add('montant', MoneyType::class, [
                'label' => 'Montant reversé',
                'currency' => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                'required' => true,
                'grouping' => true,
                'help' => 'Versements partiels possibles.',
                'attr' => ['placeholder' => 'Montant'],
            ])
            ->add('paidAt', DateTimeType::class, [
                'label' => 'Date du versement',
                'widget' => 'single_text',
                'data' => $isCreationMode ? new \DateTimeImmutable() : $reversement?->getPaidAt(),
            ])
            ->add('reference', TextType::class, [
                'required' => false,
                'label' => 'Référence',
                'help' => 'Référence du virement.',
                'attr' => ['placeholder' => 'Générée automatiquement'],
            ])
            ->add('lotReference', TextType::class, [
                'required' => false,
                'label' => 'Référence de lot',
                'help' => "Renseignée quand un même virement couvre plusieurs affaires : les "
                    . "lignes du lot ne produisent alors qu'UNE écriture comptable.",
                'attr' => ['placeholder' => 'Aucune (versement isolé)'],
            ])
            ->add('compteBancaire', CompteBancaireAutocompleteField::class, [
                'label' => 'Compte débité',
                'help' => 'Laisser vide pour un règlement en espèces (comptabilisé en caisse).',
                'class' => CompteBancaire::class,
                'required' => false,
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'required' => false,
                'attr' => ['placeholder' => 'Précision sur ce versement'],
            ])
            ->add('documents', CollectionType::class, [
                'label' => 'Preuves du versement',
                'help' => 'Bordereau de virement, reçu signé…',
                'entry_type' => DocumentType::class,
                'by_reference' => false,
                'allow_add' => true,
                'allow_delete' => true,
                'required' => false,
                'entry_options' => ['label' => false],
                // Logique API par élément : même pattern que les onze autres collections
                // de documents du projet.
                'mapped' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ReversementRetroAgent::class,
            'csrf_protection' => false,
            'allow_extra_fields' => true,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}
