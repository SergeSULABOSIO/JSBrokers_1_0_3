<?php

namespace App\Form;

use App\Entity\DemandeConge;
use App\Entity\TypeAbsence;
use App\Services\FormListenerFactory;
use App\Services\Search\CongeStatutScope;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Formulaire de saisie/édition d'une demande de congé.
 *
 * ── CE QUI N'EST PAS DANS CE FORMULAIRE, ET POURQUOI ────────────────────────────────
 * `nbJours` est DÉDUIT, jamais saisi : le décompte vient de CalculateurJoursOuvrables,
 * sinon rien n'empêche d'annoncer deux jours pour une absence de trois semaines.
 *
 * ── CE QUI Y EST, ET POURQUOI ───────────────────────────────────────────────────────
 * `statut`, `commentaireDecision`, `valideur` et `dateDecision` y figurent parce que
 * l'assistant écrit une décision par ce formulaire : le dry-run de Ket valide par le
 * FormType RÉEL, et un champ absent d'ici serait une écriture morte EN SILENCE — le
 * plan passerait, et la colonne « Décidé par » resterait vide sans que rien ne le dise.
 *
 * Ces quatre champs sont MASQUÉS dans le canevas : ils sont soumis, jamais offerts à la
 * saisie. Le valideur et l'horodatage sont posés par le geste réellement accompli — les
 * laisser saisir reviendrait à laisser signer à la place de quelqu'un d'autre. La
 * légitimité de la transition, elle, est vérifiée par DemandeCongeWorkflow, pas par la
 * présence du champ.
 *
 * Le choix du type d'absence n'est PAS restreint aux types actifs : une demande ancienne
 * doit rester éditable même si son type a été désactivé depuis. La règle « seul un type
 * actif peut être choisi pour une NOUVELLE demande » vit dans DemandeCongeValidator, où
 * elle s'applique aussi à l'assistant.
 */
class DemandeCongeType extends AbstractType
{
    public function __construct(
        private readonly FormListenerFactory $ecouteurFormulaire,
        private readonly UrlGeneratorInterface $router,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('agent', InviteAutocompleteField::class, [
                'label' => 'Collaborateur',
                'help'  => 'Le collaborateur qui pose le congé.',
            ])
            ->add('typeAbsence', EntityType::class, [
                'class'         => TypeAbsence::class,
                'label'         => "Type d'absence",
                'choice_label'  => fn (TypeAbsence $t) => (string) $t,
                'query_builder' => $this->ecouteurFormulaire->setFiltreEntreprise(),
                'placeholder'   => 'Choisir un type…',
            ])
            ->add('dateDebut', DateType::class, [
                'label'  => 'Du',
                'widget' => 'single_text',
                'input'  => 'datetime_immutable',
            ])
            ->add('dateFin', DateType::class, [
                'label'  => 'Au',
                'widget' => 'single_text',
                'input'  => 'datetime_immutable',
            ])
            ->add('demiJourneeDebut', CheckboxType::class, [
                'label'    => 'Premier jour : demi-journée',
                'required' => false,
            ])
            ->add('demiJourneeFin', CheckboxType::class, [
                'label'    => 'Dernier jour : demi-journée',
                'required' => false,
            ])
            ->add('motif', TextareaType::class, [
                'label'    => 'Motif',
                'required' => false,
                'attr'     => ['rows' => 2, 'placeholder' => 'Ex. Congé annuel en famille'],
            ])
            ->add('statut', ChoiceType::class, [
                'label'    => 'Statut',
                'choices'  => array_flip(CongeStatutScope::VALEURS),
                'required' => false,
            ])
            ->add('valideur', InviteAutocompleteField::class, [
                'label' => 'Décidé par',
                'required' => false,
            ])
            ->add('dateDecision', DateTimeType::class, [
                'label' => 'Date de décision',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => false,
            ])
            ->add('controlesContournes', TextareaType::class, [
                'label' => 'Contrôles contournés',
                'required' => false,
            ])
            ->add('commentaireDecision', TextareaType::class, [
                'label'    => 'Commentaire de décision',
                'required' => false,
                'attr'     => ['rows' => 2],
            ])
            ->add('documents', CollectionType::class, [
                'label'         => 'Justificatifs',
                'help'          => 'Certificat médical, acte, attestation…',
                'entry_type'    => DocumentType::class,
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
            'data_class'         => DemandeConge::class,
            'csrf_protection'    => false,
            'allow_extra_fields' => true,
            // LA DATE DE FIN SUIT LA DATE DE DÉBUT (contrôleur Stimulus « conge-periode »
            // posé sur le <form>, comme « piste-name-sync »). Déplacer son départ d'une
            // semaine obligeait sinon à recalculer soi-même le retour, week-ends, jours
            // fériés et régime de travail compris — un calcul que personne ne fait de
            // tête. L'URL vient du routeur : la coder ici la ferait diverger le jour où la
            // route bouge.
            'attr' => [
                'data-controller' => 'conge-periode',
                'data-conge-periode-url-value' => $this->router->generate('admin.demandeconge.api.periode_fin'),
            ],
        ]);
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}
