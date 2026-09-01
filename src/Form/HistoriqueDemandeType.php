<?php

namespace App\Form;

use App\Entity\HistoriqueDemande;
use App\Services\Search\CongeStatutScope;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Formulaire d'une ligne d'historique de demande de congé.
 *
 * Comme MouvementCongeType, il n'a pas d'écran : il existe pour que le moteur d'écriture
 * partagé sache valider la ligne qu'un plan de l'assistant fait naître. La demande, elle,
 * n'est pas un champ du formulaire — elle est posée par le plan comme relation parente.
 */
class HistoriqueDemandeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('statutAvant', ChoiceType::class, [
                'label'    => 'Statut avant',
                'choices'  => array_flip(CongeStatutScope::VALEURS),
                'required' => false,
            ])
            ->add('statutApres', ChoiceType::class, [
                'label'   => 'Statut après',
                'choices' => array_flip(CongeStatutScope::VALEURS),
            ])
            ->add('commentaire', TextareaType::class, [
                'label'    => 'Commentaire',
                'required' => false,
                'attr'     => ['rows' => 2],
            ])
            ->add('autoApprouvee', CheckboxType::class, [
                'label'    => 'Auto-approuvée',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => HistoriqueDemande::class,
            'csrf_protection'    => false,
            'allow_extra_fields' => true,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}
