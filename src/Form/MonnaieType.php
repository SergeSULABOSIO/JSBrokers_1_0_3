<?php

namespace App\Form;

use Symfony\Component\Form\Extension\Core\Type\CollectionType;

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
            // ⚠ UN TAUX DE CHANGE SE LIT DANS DEUX SENS, ET LE CHAMP NE DISAIT PAS LEQUEL.
            // « Taux USD » et « Taux de change » laissaient le choix entre « combien de
            // francs pour un dollar » (2 800) et « combien de dollars pour un franc »
            // (0,00036). Devant cette question muette, un cabinet a saisi 0,04 : son
            // capital de 4 000 000 CDF est devenu 100 000 000 USD dans les états
            // comptables, et personne n'a pu relier ce chiffre au champ qui l'avait
            // produit. La question se pose donc en toutes lettres, avec son exemple.
            //
            // ⚠ ET DEUX DÉCIMALES, PAS QUATRE. La colonne est un DECIMAL(10,2) : `scale`
            // à 4 promettait une précision que la base ne garde pas, et une saisie de
            // 0,0004 y était arrondie à zéro — la conversion se désarmant alors en
            // silence, sans le moindre message.
            ->add('tauxusd', NumberType::class, [
                'label' => "Combien vaut 1 USD dans cette monnaie ?",
                'help' => "Le nombre d'unités de cette monnaie qu'il faut pour faire 1 dollar américain. "
                    . "Pour le dollar lui-même : 1. Pour le franc congolais : environ 2 800.",
                'scale' => 2,
                'attr' => [
                    'placeholder' => "Ex. 2800 pour le franc congolais",
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
