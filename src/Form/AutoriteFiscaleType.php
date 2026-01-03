<?php

namespace App\Form;

use App\Entity\Taxe;
use App\Entity\AutoriteFiscale;
use App\Services\FormListenerFactory;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;

class AutoriteFiscaleType extends AbstractType
{
    public function __construct(
        private FormListenerFactory $ecouteurFormulaire,
        private TranslatorInterface $translatorInterface
    ) {}
    
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', TextType::class, [
                'label' => "Nom complet de l'autorité",
                'attr' => [
                    'placeholder' => "Nom",
                ],
            ])
            ->add('abreviation', TextType::class, [
                'label' => "Abréviation (sigle)",
                'attr' => [
                    'placeholder' => "Abréviation",
                ],
            ])
            ->add('taxe', EntityType::class, [
                'class' => Taxe::class,
                // Le choice_label est défini sur 'code' pour un affichage plus pertinent,
                // en se basant sur la configuration du CanvasBuilder.
                'choice_label' => 'code',
                'placeholder' => "Sélectionnez la taxe associée",
                'label' => "Taxe associée",
                // Le query_builder filtre les taxes pour ne montrer que celles de l'entreprise courante.
                'query_builder' => $this->ecouteurFormulaire->setFiltreEntreprise(),
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AutoriteFiscale::class,
        ]);
    }
}
