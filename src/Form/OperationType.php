<?php

namespace App\Form;

use App\Entity\Operation;
use App\Services\ServiceMonnaies;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;

class OperationType extends AbstractType
{
    public function __construct(private ServiceMonnaies $serviceMonnaies)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('referencePolice', TextType::class, [
                'label' => 'Réf. Police',
                'attr' => ['placeholder' => 'Référence de la police']
            ])
            ->add('numeroAvenant', TextType::class, [
                'label' => 'N° Avenant',
                'attr' => ['placeholder' => 'Numéro de l\'avenant']
            ])
            ->add('montantHT', MoneyType::class, [
                'label' => 'Montant HT',
                'currency' => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                'grouping' => true,
                'attr' => ['placeholder' => '0.00']
            ])
            ->add('montantTaxe', MoneyType::class, [
                'label' => 'Montant Taxe',
                'currency' => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                'grouping' => true,
                'required' => false,
                'attr' => ['placeholder' => '0.00']
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Operation::class,
            'csrf_protection' => false,
            'allow_extra_fields' => true,
        ]);
    }

    /**
     * En retournant une chaîne vide, on s'assure que les champs du formulaire
     * n'auront pas de préfixe (ex: 'operation[referencePolice]').
     */
    public function getBlockPrefix(): string
    {
        return '';
    }
}