<?php

namespace App\Form;

use App\Constantes\Constantes;
use App\DTO\DemandeContactDTO;
use App\DTO\LangueDTO;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;

class LangueType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('language', ChoiceType::class, [
                'label' => false,
                'expanded' => false,
                'choices'  => Constantes::TAB_LANGUES,
                'row_attr' => [
                    'class' => "input-group",
                ],
            ])
            
            //Le bouton d'enregistrement / soumission
            ->add('traduire', SubmitType::class, [
                'label' => "language.translate",
                'attr' => [
                    'class' => "btn btn-outline-secondary",
                ],
                'row_attr' => [
                    'class' => "input-group",
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => LangueDTO::class,
        ]);
    }
}
