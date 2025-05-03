<?php

namespace App\Form;

use App\Entity\Revenu;
use App\Entity\Cotation;
use App\Entity\TypeRevenu;
use App\Entity\RevenuPourCourtier;
use App\Services\FormListenerFactory;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\PercentType;

class RevenuPourCourtierType extends AbstractType
{
    public function __construct(
        private FormListenerFactory $ecouteurFormulaire,
        private TranslatorInterface $translatorInterface
    ) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('typerevenu', EntityType::class, [
                'label' => "Type de revenu",
                'class' => TypeRevenu::class,
                'choice_label' => 'nom',
            ])
            ->add('nom', TextType::class, [
                'label' => "Nom",
                'attr' => [
                    'placeholder' => "Nom",
                ],
            ])
            ->add('tauxExceptionel', PercentType::class, [
                'label' => "Taux exceptionnel",
                'required' => false,
                'scale' => 3,
                'attr' => [
                    'placeholder' => "Taux",
                ],
            ])
            ->add('montantFlatExceptionel', MoneyType::class, [
                'label' => "Montant fixe (exceptionnel)",
                'required' => false,
                'currency' => "USD",
                'grouping' => true,
                'attr' => [
                    'placeholder' => "Montant fixe",
                ],
            ])
            ->add('enregistrer', SubmitType::class, [
                'label' => "Enregistrer",
                'attr' => [
                    'class' => "btn btn-secondary",
                ],
            ])
            // ->addEventListener(FormEvents::POST_SUBMIT, $this->ecouteurFormulaire->setUtilisateur())
            ->addEventListener(FormEvents::POST_SUBMIT, $this->ecouteurFormulaire->timeStamps())

        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => RevenuPourCourtier::class,
            'parent_object' => null, // l'objet parent
        ]);
    }
}
