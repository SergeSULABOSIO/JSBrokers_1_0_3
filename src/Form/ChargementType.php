<?php

namespace App\Form;

use App\Entity\Chargement;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;

class ChargementType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', TextType::class, [
                'label' => "Nom",
                'attr' => [
                    'placeholder' => "Nom",
                ],
            ])
            ->add('description', TextareaType::class, [
                'label' => "Description",
                'attr' => [
                    'placeholder' => "Description",
                ],
            ])
            ->add('fonction', ChoiceType::class, [
                'label' => "Type de chargement",
                'expanded' => true,
                'required' => true,
                'label_html' => true,
                'choices'  => [
                    "Prime nette" => Chargement::FONCTION_PRIME_NETTE,
                    "Fronting" => Chargement::FONCTION_FRONTING,
                    "Frais accessoires" => Chargement::FONCTION_FRAIS_ADMIN,
                    "Taxe" => Chargement::FONCTION_TAXE,
                ],
                'choice_label' => function ($choice, $key, $value) {
                    $desc = match ($choice) {
                        Chargement::FONCTION_PRIME_NETTE => "La part de la prime destinée à couvrir le risque pur.",
                        Chargement::FONCTION_FRONTING => "Frais liés aux opérations de fronting.",
                        Chargement::FONCTION_FRAIS_ADMIN => "Frais de gestion, accessoires ou de police.",
                        Chargement::FONCTION_TAXE => "Taxes applicables (TVA, ARCA, etc.).",
                        default => ""
                    };
                    return '<div><strong>' . $key . '</strong><div class="text-muted small">' . $desc . '</div></div>';
                },
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Chargement::class,
            'csrf_protection' => false,
            'allow_extra_fields' => true,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}
