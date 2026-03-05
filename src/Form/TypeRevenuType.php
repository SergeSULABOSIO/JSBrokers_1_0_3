<?php

namespace App\Form;

use App\Entity\Chargement;
use App\Entity\TypeRevenu;
use App\Services\ServiceMonnaies;
use App\Services\FormListenerFactory;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\PercentType;

class TypeRevenuType extends AbstractType
{
    public function __construct(
        private FormListenerFactory $ecouteurFormulaire,
        private TranslatorInterface $translatorInterface,
        private ServiceMonnaies $serviceMonnaies
    ) {}
    
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', TextType::class, [
                'label' => "Nom",
                'required' => false,
                'attr' => [
                    'placeholder' => "Nom ici",
                ],
            ])
            ->add('modeCalcul', ChoiceType::class, [
                'label' => "Mode de calcul",
                'choices'  => [
                    "Pourcentage d'un chargement" => TypeRevenu::MODE_CALCUL_POURCENTAGE_CHARGEMENT,
                    "Un montant fixe à préciser" => TypeRevenu::MODE_CALCUL_MONTANT_FLAT,
                ],
                'expanded' => true,
                'required' => false,
                'label_html' => true,
                'choice_label' => function ($choice, $key, $value) {
                    $labels = [
                        TypeRevenu::MODE_CALCUL_POURCENTAGE_CHARGEMENT => '<div><strong>Pourcentage d\'un chargement</strong><div class="text-muted small">Le revenu est un pourcentage d\'un composant de la prime (ex: prime nette).</div></div>',
                        TypeRevenu::MODE_CALCUL_MONTANT_FLAT => '<div><strong>Montant fixe</strong><div class="text-muted small">Le revenu est un montant forfaitaire prédéfini.</div></div>',
                    ];
                    return $labels[$value] ?? '<div><strong>' . $key . '</strong></div>';
                },
            ])
            ->add('typeChargement', EntityType::class, [
                'label' => "Chargement cible",
                'required' => true,
                'class' => Chargement::class,
                'choice_label' => 'nom',
                'help' => 'Le composant de la prime (ou chargement) sur base duquel ce revenu sera calculé automatiquement.',
            ])
            ->add('appliquerPourcentageDuRisque', ChoiceType::class, [
                'required' => false,
                'label' => "Privilégier le taux de commission configuré pour le risque concerné?",
                'expanded' => true,
                'choices'  => [
                    "Oui, s'il existe et qu'il est différent du pourcentage de ce revenu." => true,
                    "Non, pas du tout." => false,
                ],
                'label_html' => true,
                'choice_label' => function ($choice, $key, $value) {
                    if ($choice === true) {
                        return '<div><strong>Oui</strong><div class="text-muted small">En cas de différence, le taux spécifique au risque sera appliqué en priorité.</div></div>';
                    }
                    return '<div><strong>Non</strong><div class="text-muted small">Le taux de ce type de revenu sera toujours appliqué.</div></div>';
                },
            ])
            ->add('pourcentage', PercentType::class, [
                'label' => "Pourcentage",
                'required' => false,
                'help' => "On applique un %",
                'scale' => 3,
                'attr' => [
                    'placeholder' => "Pourcentage",
                ],
            ])
            ->add('montantflat', MoneyType::class, [
                'label' => "Montant fixe",
                'help' => "On considère un montant fixe",
                'currency' => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                'grouping' => true,
                'required' => false,
                'attr' => [
                    'placeholder' => "Montant fixe",
                ],
            ])
            ->add('shared', ChoiceType::class, [
                'label' => "Est-il partageable avec un partenaire?",
                'expanded' => true,
                'required' => false,
                'choices'  => [
                    "Oui, si celui-ci existe." => true,
                    "Non, pas du tout." => false,
                ],
                'label_html' => true,
                'choice_label' => function ($choice, $key, $value) {
                    if ($choice === true) {
                        return '<div><strong>Oui</strong><div class="text-muted small">Ce revenu peut être inclus dans le calcul de la rétro-commission d\'un partenaire.</div></div>';
                    }
                    return '<div><strong>Non</strong><div class="text-muted small">Ce revenu est exclusif au courtier.</div></div>';
                },
            ])
            ->add('multipayments', ChoiceType::class, [
                'label' => "Son paiement peut-il être échélonné?",
                'expanded' => true,
                'required' => false,
                'choices'  => [
                    "Oui, il peut être payé en plusieurs tranches." => true,
                    "Non, pas du tout." => false,
                ],
                'label_html' => true,
                'choice_label' => function ($choice, $key, $value) {
                    if ($choice === true) {
                        return '<div><strong>Oui</strong><div class="text-muted small">Permet de facturer ce revenu en plusieurs fois.</div></div>';
                    }
                    return '<div><strong>Non</strong><div class="text-muted small">Ce revenu doit être facturé en une seule fois.</div></div>';
                },
            ])
            ->add('redevable', ChoiceType::class, [
                'label' => "Qui est débiteur?",
                'expanded' => true,
                'required' => false,
                'choices'  => [
                    "L'assureur" => TypeRevenu::REDEVABLE_ASSUREUR,
                    "Le client" => TypeRevenu::REDEVABLE_CLIENT,
                    "Le partenaire" => TypeRevenu::REDEVABLE_PARTENAIRE,
                    "Le réassureur" => TypeRevenu::REDEVABLE_REASSURER,
                ],
                'label_html' => true,
                'choice_label' => function ($choice, $key, $value) {
                    $descriptions = [
                        TypeRevenu::REDEVABLE_ASSUREUR => "La commission est due par l'assureur.",
                        TypeRevenu::REDEVABLE_CLIENT => "Les honoraires sont dus par le client.",
                        TypeRevenu::REDEVABLE_PARTENAIRE => "Le revenu est dû par le partenaire.",
                        TypeRevenu::REDEVABLE_REASSURER => "Le revenu est dû par le réassureur."
                    ];
                    return '<div><strong>' . $key . '</strong><div class="text-muted small">' . ($descriptions[$value] ?? '') . '</div></div>';
                },
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => TypeRevenu::class,
            'csrf_protection' => false,
            'allow_extra_fields' => true,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}
