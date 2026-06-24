<?php

namespace App\Form;

use App\Entity\Coupon;
use App\Token\ParametresTokenService;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Formulaire de création/édition d'un coupon de réduction sur les tokens.
 */
class CouponType extends AbstractType
{
    public function __construct(private ParametresTokenService $parametres)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // Choix de paquets ciblés : tous, ou un paquet précis du plan courant. La
        // source de vérité est le plan tarifaire en BD (ParametresTokenService) —
        // on propose donc EXACTEMENT les paquets qui existent réellement, avec leur
        // vrai libellé (et non un ucfirst de la clé technique).
        $packChoices = ['Tous les paquets' => null];
        foreach ($this->parametres->packs() as $key => $pack) {
            $packChoices[$pack['label'] ?? ucfirst($key)] = $key;
        }

        $builder
            ->add('code', TextType::class, [
                'label' => 'Code',
                'attr'  => ['placeholder' => 'Ex. PROMO2026', 'style' => 'text-transform:uppercase;', 'data-icon' => 'offre'],
            ])
            ->add('type', ChoiceType::class, [
                'label'   => 'Type de remise',
                'choices' => [
                    'Pourcentage (%)'   => Coupon::TYPE_PERCENT,
                    'Montant fixe (USD)' => Coupon::TYPE_FIXED,
                ],
                'attr'    => ['data-icon' => 'tranche'],
            ])
            ->add('valeur', NumberType::class, [
                'label' => 'Valeur (% si pourcentage, sinon montant en USD)',
                'scale' => 2,
                'attr'  => ['placeholder' => 'Ex. 15', 'data-icon' => 'monnaie'],
            ])
            ->add('dateDebut', DateTimeType::class, [
                'label'  => 'Début de validité',
                'widget' => 'single_text',
                'attr'   => ['placeholder' => 'Date de début', 'data-icon' => 'action:calendar'],
            ])
            ->add('dateFin', DateTimeType::class, [
                'label'  => 'Fin de validité',
                'widget' => 'single_text',
                'attr'   => ['placeholder' => 'Date de fin', 'data-icon' => 'action:calendar'],
            ])
            ->add('usageLimit', IntegerType::class, [
                'label'    => 'Limite d\'utilisations (vide = illimité)',
                'required' => false,
                'attr'     => ['placeholder' => 'Illimité si vide', 'data-icon' => 'action:count'],
            ])
            ->add('packCible', ChoiceType::class, [
                'label'       => 'Paquet ciblé',
                'choices'     => $packChoices,
                'required'    => false,
                'placeholder' => false,
                'attr'        => ['data-icon' => 'offre'],
            ])
            ->add('actif', CheckboxType::class, [
                'label'    => 'Actif',
                'required' => false,
            ])
            ->add('visiblePublic', CheckboxType::class, [
                'label'    => 'Visible sur la vitrine',
                'required' => false,
                'help'     => 'Met en avant ce coupon (badge promo) sur les cartes de tarifs publiques.',
            ]);

        // Référence pendante : si l'on édite un coupon dont le paquet ciblé n'existe
        // plus dans le plan courant, on réinjecte ce paquet dans les choix (libellé
        // « supprimé ») pour ne pas casser le formulaire ni perdre silencieusement la
        // valeur enregistrée.
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) use ($packChoices) {
            $coupon = $event->getData();
            $cible = $coupon instanceof Coupon ? $coupon->getPackCible() : null;
            if ($cible !== null && !in_array($cible, $packChoices, true)) {
                $packChoices[$cible . ' (paquet supprimé)'] = $cible;
                $event->getForm()->add('packCible', ChoiceType::class, [
                    'label'       => 'Paquet ciblé',
                    'choices'     => $packChoices,
                    'required'    => false,
                    'placeholder' => false,
                    'attr'        => ['data-icon' => 'offre'],
                ]);
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Coupon::class]);
    }
}
