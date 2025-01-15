<?php

namespace App\Form;

use App\Entity\Note;
use App\Entity\Article;
use App\Entity\Tranche;
use App\Services\FormListenerFactory;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\PercentType;

class ArticleType extends AbstractType
{
    public function __construct(
        private FormListenerFactory $ecouteurFormulaire,
        private TranslatorInterface $translatorInterface
    ) {}
    
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', TextType::class, [
                'label' => "Nom",
                'attr' => [
                    'placeholder' => "Nom",
                ],
            ])
            ->add('tranche', TrancheAutocompleteField::class, [
                'label' => "Tranche",
                'class' => Tranche::class,
                'required' => false,
                // 'choice_label' => 'nom',
            ])
            ->add('pourcentage', PercentType::class, [
                'label' => "Portion concernée de la tranche",
                'help' => "100% si vous désirez s'appliquer sur toute cette tranche, sinon merci de préciser la portion en pourcentage.",
                'required' => true,
                'scale' => 3,
                'attr' => [
                    'placeholder' => "Portion",
                ],
            ])
            // ->add('note', EntityType::class, [
            //     'class' => Note::class,
            //     'choice_label' => 'id',
            // ])
            //Le bouton suivant
            ->add('Enregistrer', SubmitType::class, [
                'label' => "Enregistrer",
                'attr' => [
                    'class' => "btn btn-secondary",
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Article::class,
        ]);
    }
}
