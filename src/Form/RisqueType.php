<?php

namespace App\Form;

use App\Entity\Risque;
use App\Services\FormListenerFactory;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\PercentType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;

class RisqueType extends AbstractType
{
    public function __construct(
        private FormListenerFactory $ecouteurFormulaire,
        private TranslatorInterface $translatorInterface
    ) {}

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
                'attr' => [
                    'placeholder' => "Code",
                ],
            ])
            ->add('description', TextareaType::class, [
                'label' => "Description",
                'required' => false,
                'attr' => [
                    'placeholder' => "Description détaillée",
                    'rows' => 3,
                ],
            ])
            ->add('pourcentageCommissionSpecifiqueHT', PercentType::class, [
                'label' => "Taux de commission",
                'required' => false,
                'scale' => 2,
                'help' => "Taux spécifique pour ce risque.",
                'attr' => [
                    'placeholder' => "Taux",
                ],
            ])
            ->add('branche', ChoiceType::class, [
                'label' => "Branche",
                'help' => "Branche d'activité (IARD ou Vie).",
                'expanded' => true,
                'required' => true,
                'choices'  => [
                    "IARD (Non-Vie)" => Risque::BRANCHE_IARD_OU_NON_VIE,
                    "Vie" => Risque::BRANCHE_VIE,
                ],
            ])
            ->add('imposable', ChoiceType::class, [
                'label' => "Imposable ?",
                'help' => "Appliquer les taxes sur ce risque ?",
                'expanded' => true,
                'required' => true,
                'choices'  => [
                    "Oui" => true,
                    "Non" => false,
                ],
            ])
            ->addEventListener(FormEvents::POST_SUBMIT, $this->ecouteurFormulaire->timeStamps())
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
