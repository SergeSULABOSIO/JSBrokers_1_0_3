<?php

namespace App\Form;

use Symfony\Component\Form\Extension\Core\Type\CollectionType;

use App\Entity\Risque;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\PercentType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;

class RisqueType extends AbstractType
{
    public function __construct() {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nomComplet', TextType::class, [
                'label' => "Nom Complet",
                'attr' => [
                    'placeholder' => "Nom",
                ],
            ])
            ->add('code', TextType::class, [
                'label' => "Code",
                'help' => "Vous pouvez saisir plusieurs abréviations séparées par une virgule (,) ou un point-virgule (;).",
                'attr' => [
                    'placeholder' => "Code",
                ],
            ])
            ->add('description', TextareaType::class, [
                'label' => "Description",
                'required' => false,
                'attr' => [
                    'placeholder' => "Description détaillée",
                    'rows' => 10,
                ],
            ])
            ->add('pourcentageCommissionSpecifiqueHT', PercentType::class, [
                'label' => "Taux de commission",
                'required' => false,
                // Stockage en POINTS (16 = 16 %), pas en fraction : convention unique
                // de l'app. Calculs via Risque::getFraction().
                'type' => 'integer',
                'scale' => 2,
                // 'help' => "Taux spécifique.",
                'attr' => [
                    'placeholder' => "Taux",
                ],
            ])
            ->add('branche', ChoiceType::class, [
                'label' => "Branche",
                'expanded' => true,
                'required' => true,
                'label_html' => true,
                'choices'  => [
                    "IARD" => Risque::BRANCHE_IARD_OU_NON_VIE,
                    "VIE" => Risque::BRANCHE_VIE,
                ],
                'choice_label' => function ($choice, $key, $value) {
                    if ($choice === Risque::BRANCHE_IARD_OU_NON_VIE) {
                        return '<div><strong>IARD (Non-Vie)</strong><div class="text-muted small">Assurances de dommages (Incendie, Accidents, Risques Divers).</div></div>';
                    }
                    return '<div><strong>Vie</strong><div class="text-muted small">Assurances de personnes (Vie, Décès, Capitalisation).</div></div>';
                },
            ])
            ->add('imposable', ChoiceType::class, [
                'label' => "Imposable ?",
                'expanded' => true,
                'required' => true,
                'label_html' => true,
                'choices'  => [
                    "Oui" => true,
                    "Non" => false,
                ],
                'choice_label' => function ($choice, $key, $value) {
                    if ($choice === true) {
                        return '<div><strong>Oui</strong><div class="text-muted small">Les taxes seront calculées.</div></div>';
                    }
                    return '<div><strong>Non</strong><div class="text-muted small">Aucune taxe ne sera appliquée.</div></div>';
                },
            ])
            // PIÈCES JOINTES de cette fiche. `mapped: false` comme les onze autres
            // collections de documents du projet : chaque pièce est créée, modifiée et
            // supprimée par son propre dialogue, via l'API de Document — le formulaire
            // parent ne fait que porter le widget.
            ->add('documents', CollectionType::class, [
                'label' => "Documents",
                'entry_type' => DocumentType::class,
                'by_reference' => false,
                'allow_add' => true,
                'allow_delete' => true,
                'required' => false,
                'entry_options' => ['label' => false],
                'mapped' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Risque::class,
            'csrf_protection' => false,
            'allow_extra_fields' => true,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}
