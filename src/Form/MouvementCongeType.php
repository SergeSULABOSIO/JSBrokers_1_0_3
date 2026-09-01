<?php

namespace App\Form;

use App\Entity\MouvementConge;
use App\Entity\TypeAbsence;
use App\Services\FormListenerFactory;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Formulaire d'un mouvement de compteur de congés.
 *
 * IL N'A PAS D'ÉCRAN. Il existe parce que le moteur d'écriture partagé — celui des
 * contrôleurs comme celui de l'assistant — valide toute opération par le FormType réel
 * de l'entité : sans lui, une ligne de journal produite par un plan de Ket serait
 * silencieusement vide.
 *
 * Un mouvement reste IMMUABLE : ce formulaire sert à en créer, jamais à en corriger. Une
 * erreur se répare par un mouvement inverse motivé.
 */
class MouvementCongeType extends AbstractType
{
    public function __construct(
        private readonly FormListenerFactory $ecouteurFormulaire,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('agent', InviteAutocompleteField::class, [
                'label' => 'Collaborateur',
            ])
            ->add('exercice', IntegerType::class, [
                'label' => 'Exercice',
            ])
            ->add('typeAbsence', EntityType::class, [
                'class'         => TypeAbsence::class,
                'label'         => "Type d'absence",
                'choice_label'  => fn (TypeAbsence $t) => (string) $t,
                'query_builder' => $this->ecouteurFormulaire->setFiltreEntreprise(),
                'required'      => false,
                'placeholder'   => 'Choisir un type…',
            ])
            ->add('nature', ChoiceType::class, [
                'label'   => 'Nature',
                'choices' => array_flip(MouvementConge::NATURES),
            ])
            ->add('quantite', NumberType::class, [
                'label' => 'Quantité (jours)',
                'scale' => 1,
                'help'  => 'Signée : négative pour une prise, positive pour un crédit.',
            ])
            ->add('commentaire', TextareaType::class, [
                'label'    => 'Motif',
                'required' => false,
                'attr'     => ['rows' => 2],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => MouvementConge::class,
            'csrf_protection'    => false,
            'allow_extra_fields' => true,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}
