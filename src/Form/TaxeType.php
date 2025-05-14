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
                'expanded' => false,
                'required' => false,
                'choices'  => [
                    "L'assureur" => Taxe::REDEVABLE_ASSUREUR,
                    "Le courtier" => Taxe::REDEVABLE_COURTIER,
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
                    'data-form-collection-entites-data-value' => json_encode([
                        'addLabel' => $this->translatorInterface->trans("commom_add"),
                        'deleteLabel' => $this->translatorInterface->trans("commom_delete"),
                        'icone' => "taxe",
                        'dossieractions' => 0,  //1=On doit chercher l'icone "role" dans le dossier ICONES/ACTIONS, sinon on la chercher dans le dossier racine càd le dossier ICONES (le dossier racime)
                        'tailleMax' => 1,
                    ]),
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
