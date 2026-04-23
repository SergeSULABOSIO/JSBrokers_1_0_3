<?php

namespace App\Form;

use App\Entity\Taxe;
use App\Services\FormListenerFactory;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\PercentType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;

class TaxeType extends AbstractType
{

    public function __construct(
        private FormListenerFactory $ecouteurFormulaire,
        private TranslatorInterface $translatorInterface
    ) {}
    
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('code', TextType::class, [
                'label' => "Code de la taxe",
                'required' => false,
                'attr' => [
                    'placeholder' => "Code",
                ],
            ])
            ->add('description', TextareaType::class, [
                'label' => "Description",
                'required' => false,
                'attr' => [
                    'placeholder' => "Description ici",
                ],
            ])
            // ->add('organisation', TextType::class, [
            //     'label' => "Organisation",
            //     'attr' => [
            //         'placeholder' => "Organisation",
            //     ],
            // ])
            
            ->add('tauxIARD', PercentType::class, [
                'label' => "Taux (IARD)",
                'required' => false,
                'scale' => 2,
                'type' => 'integer', // Stocke la valeur telle quelle (ex: 16 pour 16%)
                'attr' => [
                    'placeholder' => "Taux",
                ],
            ])
            ->add('tauxVIE', PercentType::class, [
                'label' => "Taux (VIE)",
                'required' => false,
                'scale' => 2,
                'type' => 'integer', // Stocke la valeur telle quelle (ex: 2 pour 2%)
                'attr' => [
                    'placeholder' => "Taux",
                ],
            ])
            ->add('redevable', ChoiceType::class, [
                'label' => "Qui en est l'assujetti?",
                'expanded' => true,
                'label_html' => true,
                'required' => true,
                'choices'  => [
                    "L'assureur" => Taxe::REDEVABLE_ASSUREUR,
                    "Le courtier" => Taxe::REDEVABLE_COURTIER,
                ],
                'choice_label' => function ($choice, $key, $value) {
                    $desc = match ($choice) {
                        Taxe::REDEVABLE_ASSUREUR => "La taxe est due par la compagnie d'assurance.",
                        Taxe::REDEVABLE_COURTIER => "La taxe est due par le courtier.",
                        default => ""
                    };
                    return '<div><strong>' . $key . '</strong><div class="text-muted small">' . $desc . '</div></div>';
                },
            ])
            ->add('autoriteFiscales', CollectionType::class, [
                'label' => "Autorités fiscales",
                'help' => "Autorité fiscale concernée par cette taxe",
                'entry_type' => AutoriteFiscaleAutocompleteField::class,
                'by_reference' => false,
                'allow_add' => true,
                'required' => false,
                'allow_delete' => true,
                'entry_options' => [
                    'label' => false,
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Taxe::class,
            'csrf_protection' => false,
            'allow_extra_fields' => true,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}
