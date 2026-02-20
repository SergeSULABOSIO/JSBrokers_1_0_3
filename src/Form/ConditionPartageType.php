<?php

namespace App\Form;

use App\Entity\Risque;
use App\Entity\ConditionPartage;
use App\Services\FormListenerFactory;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\PercentType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;

class ConditionPartageType extends AbstractType
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
            ->add('uniteMesure', ChoiceType::class, [
                'label' => "Unité de mésure",
                'help' => "L'unité de mésure représente l'indicateur où le seuil s'applique.",
                'expanded' => true,
                'choices'  => [
                   "La somme des commissions pures du risque" => ConditionPartage::UNITE_SOMME_COMMISSION_PURE_RISQUE,
                   "La somme des commissions pures du client" => ConditionPartage::UNITE_SOMME_COMMISSION_PURE_CLIENT,
                   "La somme des commissions pures du parténaire" => ConditionPartage::UNITE_SOMME_COMMISSION_PURE_PARTENAIRE,
                ],
            ])
            ->add('formule', ChoiceType::class, [
                'label' => "Formule",
                'expanded' => true,
                'choices'  => [
                   "Lorsque l'unité de mésure est au moins égale au seuil" => ConditionPartage::FORMULE_ASSIETTE_AU_MOINS_EGALE_AU_SEUIL,
                   "Lorsque l'unité de mésure est inférieure au seuil" => ConditionPartage::FORMULE_ASSIETTE_INFERIEURE_AU_SEUIL,
                   "Ne pas appliquer le seuil" => ConditionPartage::FORMULE_NE_SAPPLIQUE_PAS_SEUIL,
                ],
            ])
            ->add('seuil', NumberType::class, [
                'label' => "Seuil applicable",
                'help' => "Le seuil à appliquer dans la condition de partage.",
                'required' => false,
                'attr' => [
                    'placeholder' => "Seuil",
                ],
            ])
            ->add('taux', PercentType::class, [
                'label' => "Taux applicable",
                'help' => "Ce pourcentage ne s'appliquera que sur les commissions hors taxes (l'assiette partageable).",
                'required' => false,
                'scale' => 3,
                'attr' => [
                    'placeholder' => "Taux",
                ],
            ])
            ->add('critereRisque', ChoiceType::class, [
                'label' => "Critère sur le risque",
                'help' => "Comment s'applique cette condition par rapport au risque.",
                'required' => false,
                'expanded' => true,
                'choices'  => [
                   "On ne partage pas quand il s'agit de risques ciblés" => ConditionPartage::CRITERE_EXCLURE_TOUS_CES_RISQUES,
                   "On ne partage que quand il s'agit de risques ciblés" => ConditionPartage::CRITERE_INCLURE_TOUS_CES_RISQUES,
                   "Il n'y a pas de risques ciblés" => ConditionPartage::CRITERE_PAS_RISQUES_CIBLES,
                ],
            ])
            
            ->add('produits', CollectionType::class, [
                'label' => "Risques ciblés",
                'entry_type' => RisqueType::class,
                'by_reference' => false,
                'allow_add' => true,
                'allow_delete' => true,
                'mapped' => false,
                'entry_options' => [
                    'label' => false,
                ],
            ])
            // ->addEventListener(FormEvents::POST_SUBMIT, $this->ecouteurFormulaire->setUtilisateur())
            // ->addEventListener(FormEvents::POST_SUBMIT, $this->ecouteurFormulaire->timeStamps())
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ConditionPartage::class,
            'csrf_protection' => false,
            'allow_extra_fields' => true,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}
