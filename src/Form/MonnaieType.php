<?php

namespace App\Form;

use App\Entity\Monnaie;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;

class MonnaieType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', TextType::class, [
                'label' => "Nom",
                'attr' => [
                    'placeholder' => "Nom de la monnaie",
                ],
            ])
            ->add('code', TextType::class, [
                'label' => "Code",
                'attr' => [
                    'placeholder' => "Code ISO (ex: USD)",
                ],
            ])
            ->add('tauxusd', NumberType::class, [
                'label' => "Taux USD",
                'scale' => 4,
                'attr' => [
                    'placeholder' => "Taux de change",
                ],
            ])
            ->add('fonction', ChoiceType::class, [
                'label' => "Fonction",
                'expanded' => true,
                'required' => true,
                'label_html' => true,
                'choices'  => [
                    "Aucune" => Monnaie::FONCTION_AUCUNE,
                    "Saisie et Affichage" => Monnaie::FONCTION_SAISIE_ET_AFFICHAGE,
                    "Saisie Uniquement" => Monnaie::FONCTION_SAISIE_UNIQUEMENT,
                    "Affichage Uniquement" => Monnaie::FONCTION_AFFICHAGE_UNIQUEMENT,
                ],
                'choice_label' => function ($choice, $key, $value) {
                    return match ($choice) {
                        Monnaie::FONCTION_AUCUNE => '<div><strong>Aucune</strong><div class="text-muted small">Cette monnaie n\'est pas utilisée activement.</div></div>',
                        Monnaie::FONCTION_SAISIE_ET_AFFICHAGE => '<div><strong>Saisie et Affichage</strong><div class="text-muted small">Utilisée pour les transactions et les rapports.</div></div>',
                        Monnaie::FONCTION_SAISIE_UNIQUEMENT => '<div><strong>Saisie Uniquement</strong><div class="text-muted small">Uniquement pour l\'enregistrement des opérations.</div></div>',
                        Monnaie::FONCTION_AFFICHAGE_UNIQUEMENT => '<div><strong>Affichage Uniquement</strong><div class="text-muted small">Uniquement pour la conversion dans les rapports.</div></div>',
                        default => $key,
                    };
                },
            ])
            ->add('locale', ChoiceType::class, [
                'label' => "Monnaie Locale ?",
                'expanded' => true,
                'required' => true,
                'label_html' => true,
                'choices'  => [
                    "Non" => false,
                    "Oui" => true,
                ],
                'choice_label' => function ($choice, $key, $value) {
                    if ($choice === true) {
                        return '<div><strong>Oui</strong><div class="text-muted small">C\'est la devise de référence pour la comptabilité.</div></div>';
                    }
                    return '<div><strong>Non</strong><div class="text-muted small">C\'est une devise étrangère.</div></div>';
                },
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Monnaie::class,
            'csrf_protection' => false,
            'allow_extra_fields' => true,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}
