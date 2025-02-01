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
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\PercentType;

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
            ->add('formule', ChoiceType::class, [
                'label' => "Formule",
                'expanded' => true,
                'choices'  => [
                   "Lorsque l'assiette est au moins égale au seuil" => ConditionPartage::FORMULE_ASSIETTE_AU_MOINS_EGALE_AU_SEUIL,
                   "Lorsque l'assiette est inférieure au seuil" => ConditionPartage::FORMULE_ASSIETTE_INFERIEURE_AU_SEUIL,
                   "Ne pas appliquer le seuil" => ConditionPartage::FORMULE_NE_SAPPLIQUE_PAS_SEUIL,
                ],
            ])
            ->add('seuil', MoneyType::class, [
                'label' => "Seuil applicable",
                'help' => "Le seuil à appliquer dans la condition de partage.",
                'currency' => "USD",
                'grouping' => true,
                'required' => false,
                'attr' => [
                    'placeholder' => "Seuil",
                ],
            ])
            ->add('taux', PercentType::class, [
                'label' => "Taux applicable",
                'help' => "Ce pourcentage ne s'appliquera que sur les commissions hors taxes (l'assiette partageable).",
                'required' => true,
                'scale' => 3,
                'attr' => [
                    'placeholder' => "Taux",
                ],
            ])
            ->add('critereRisque', ChoiceType::class, [
                'label' => "Critère sur le risque",
                'help' => "Comment s'applique cette condition par rapport au risque.",
                'expanded' => true,
                'choices'  => [
                   "On ne partage pas quand il s'agit de risques ciblés" => ConditionPartage::CRITERE_EXCLURE_TOUS_CES_RISQUES,
                   "On ne partage que quand il s'agit de risques ciblés" => ConditionPartage::CRITERE_INCLURE_TOUS_CES_RISQUES,
                   "Il n'y a pas de risques ciblés" => ConditionPartage::CRITERE_PAS_RISQUES_CIBLES,
                ],
            ])
            
            ->add('produits', RisqueAutocompleteField::class, [
                'required' => false,
                'label' => "Risques ciblés",
                'help' => "Il s'agit ici des risques concernés ou pas par le partage avec le partenaire.",
                'class' => Risque::class,
                'choice_label' => "nomComplet",
                'multiple' => true,
                'expanded' => false,
                'by_reference' => false,
                'autocomplete' => true,
                'attr' => [
                    'placeholder' => "Risques concernés",
                ],
            ])
            // ->add('unite', ChoiceType::class, [
            //     'label' => "Unité de mésure",
            //     'help' => "Par quelle mésure appliquer cette condition?",
            //     'expanded' => true,
            //     'choices'  => [
            //        "Par client et par couverture" => ConditionPartage::UNITE_PAR_CLIENT_ET_PAR_COUVERTURES,
            //        "Par client uniquement" => ConditionPartage::UNITE_PAR_CLIENT,
            //        "Par couverture uniquement" => ConditionPartage::UNITE_PAR_COUVERTURE,
            //     ],
            // ])
            // ->add('partenaire', EntityType::class, [
            //     'class' => Partenaire::class,
            //     'choice_label' => 'id',
            // ])
            //Le bouton d'enregistrement / soumission
            ->add('enregistrer', SubmitType::class, [
                'label' => "Enregistrer",
                'attr' => [
                    'class' => "btn btn-secondary",
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
        ]);
    }
}
