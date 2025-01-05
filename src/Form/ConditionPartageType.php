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
                   "Ne pas appliquer le seuil" => ConditionPartage::FORMULE_ASSIETTE_INFERIEURE_AU_SEUIL,
                ],
            ])
            ->add('seuil', NumberType::class, [
                'label' => "Seuil applicable",
                'help' => "Le seuil à appliquer dans la condition de partage.",
                'currency' => "USD",
                'grouping' => true,
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
                   "Cette condition exclue les risques figurante sur cette liste" => ConditionPartage::CRITERE_EXCLURE_TOUS_CES_RISQUES,
                   "Cette condition inclue les risques figurante sur cette liste" => ConditionPartage::CRITERE_EXCLURE_TOUS_CES_RISQUES,
                ],
            ])
            
            ->add('produits', ClientAutocompleteField::class, [
                'required' => false,
                'label' => "Risques concernés",
                'class' => Risque::class,
                'choice_label' => "nomComplet",
                'multiple' => true,
                'expanded' => false,
                'by_reference' => false,
                'autocomplete' => true,
                'attr' => [
                    'placeholder' => "Risques concernés",
                    'class' => "form-control p-2 fs-6",
                ],
            ])
            ->add('unite', ChoiceType::class, [
                'label' => "Unité de mésure",
                'help' => "Par quelle mésure appliquer cette condition?",
                'expanded' => true,
                'choices'  => [
                   "Par client et par couverture" => ConditionPartage::UNITE_PAR_CLIENT_ET_PAR_COUVERTURES,
                   "Par client uniquement" => ConditionPartage::UNITE_PAR_CLIENT,
                   "Par couverture uniquement" => ConditionPartage::UNITE_PAR_COUVERTURE,
                ],
            ])
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
