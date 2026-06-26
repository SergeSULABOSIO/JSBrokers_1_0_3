<?php

namespace App\Form;

use App\Crm\CrmPipelineService;
use App\Entity\Crm\CrmCampagne;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Formulaire de création d'une campagne marketing, sur le pattern Console (Coupon).
 * Le segment (étapes / couleurs de santé) est porté par des champs NON mappés ;
 * le contrôleur assemble segmentRegles après validation.
 */
class CrmCampagneType extends AbstractType
{
    public function __construct(private CrmPipelineService $pipeline)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $stageChoices = [];
        foreach ($this->pipeline->orderedStages() as $key => $meta) {
            $stageChoices[$meta['label']] = $key;
        }

        $builder
            ->add('nom', TextType::class, [
                'label' => 'Nom de la campagne',
                'attr'  => ['placeholder' => 'Ex. Onboarding juin', 'data-icon' => 'action:premium'],
            ])
            ->add('type', ChoiceType::class, [
                'label'   => 'Type',
                'choices' => array_flip(CrmCampagne::TYPES),
                'attr'    => ['data-icon' => 'tranche'],
            ])
            ->add('objet', TextType::class, [
                'label' => 'Objet de l\'e-mail',
                'attr'  => ['placeholder' => 'Ex. Bienvenue chez JS Brokers', 'data-icon' => 'note'],
            ])
            ->add('message', TextareaType::class, [
                'label' => 'Message (une ligne = un paragraphe)',
                'attr'  => ['rows' => 5, 'placeholder' => 'Corps de l\'e-mail…', 'data-icon' => 'action:description'],
            ])
            ->add('stages', ChoiceType::class, [
                'label'    => 'Étapes du pipeline ciblées (vide = toutes)',
                'choices'  => $stageChoices,
                'multiple' => true,
                'expanded' => true,
                'mapped'   => false,
                'required' => false,
            ])
            ->add('couleurs', ChoiceType::class, [
                'label'    => 'Couleurs de santé ciblées (vide = toutes)',
                'choices'  => [
                    'En bonne santé' => 'vert',
                    'À surveiller'   => 'jaune',
                    'À risque'       => 'orange',
                    'Critique'       => 'rouge',
                ],
                'multiple' => true,
                'expanded' => true,
                'mapped'   => false,
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => CrmCampagne::class]);
    }
}
