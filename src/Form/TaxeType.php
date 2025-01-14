<?php

namespace App\Form;

use App\Entity\Taxe;
use App\Services\FormListenerFactory;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\PercentType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;

class TaxeType extends AbstractType
{

    public function __construct(
        private FormListenerFactory $ecouteurFormulaire,
        private TranslatorInterface $translatorInterface
    ) {}
    
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('code', TextType::class, [
                'label' => "Code de la taxe",
                'required' => false,
                'attr' => [
                    'placeholder' => "Code",
                ],
            ])
            ->add('description', TextareaType::class, [
                'label' => "Description",
                'required' => false,
                'attr' => [
                    'placeholder' => "Description ici",
                ],
            ])
            // ->add('organisation', TextType::class, [
            //     'label' => "Organisation",
            //     'attr' => [
            //         'placeholder' => "Organisation",
            //     ],
            // ])
            
            ->add('tauxIARD', PercentType::class, [
                'label' => "Taux (IARD)",
                'required' => false,
                'attr' => [
                    'placeholder' => "Taux",
                ],
            ])
            ->add('tauxVIE', PercentType::class, [
                'label' => "Taux (VIE)",
                'required' => false,
                'attr' => [
                    'placeholder' => "Taux",
                ],
            ])
            ->add('redevable', ChoiceType::class, [
                'label' => "Qui sont-ils redevables à cette taxe?",
                'expanded' => true,
                'required' => false,
                'choices'  => [
                    "Le client et le courtier" => Taxe::REDEVABLE_COURTIER_ET_CLIENT,
                    "Le courtier" => Taxe::REDEVABLE_COURTIER,
                    "Le client" => Taxe::REDEVABLE_CLIENT,
                ],
            ])
            ->add('autoriteFiscales', CollectionType::class, [
                'label' => "Autorités fiscales",
                'help' => "Autorité fiscale concernée par cette taxe",
                'entry_type' => AutoriteFiscaleType::class,
                'by_reference' => false,
                'allow_add' => true,
                'required' => false,
                'allow_delete' => true,
                'entry_options' => [
                    'label' => false,
                ],
                'attr' => [
                    'data-controller' => 'form-collection-entites',
                    'data-form-collection-entites-add-label-value' => $this->translatorInterface->trans("commom_add"), //'Ajouter',
                    'data-form-collection-entites-delete-label-value' => $this->translatorInterface->trans("commom_delete"),
                    'data-form-collection-entites-edit-label-value' => $this->translatorInterface->trans("commom_edit"),
                    'data-form-collection-entites-close-label-value' => $this->translatorInterface->trans("commom_close"),
                    'data-form-collection-entites-new-element-label-value' => $this->translatorInterface->trans("commom_new_element"),
                    'data-form-collection-entites-view-field-value' => "abreviation",
                ],
            ])
            //Le bouton d'enregistrement / soumission
            ->add('enregistrer', SubmitType::class, [
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
            'data_class' => Taxe::class,
        ]);
    }
}
