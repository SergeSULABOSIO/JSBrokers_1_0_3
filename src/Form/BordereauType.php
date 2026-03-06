<?php

namespace App\Form;

use App\Entity\Bordereau;
use App\Services\ServiceMonnaies;
use App\Services\FormListenerFactory;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;

class BordereauType extends AbstractType
{
    public function __construct(
        private FormListenerFactory $ecouteurFormulaire,
        private TranslatorInterface $translatorInterface,
        private ServiceMonnaies $serviceMonnaies
    ) {}
    
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('type', ChoiceType::class, [
                'label' => "Type",
                'required' => true,
                'expanded' => true,
                'label_html' => true,
                'choices'  => [
                    "Bordereau de production" => Bordereau::TYPE_BOREDERAU_PRODUCTION,
                ],
                'choice_label' => function ($choice, $key, $value) {
                    if ($choice === Bordereau::TYPE_BOREDERAU_PRODUCTION) {
                        return '<div><strong>Bordereau de production</strong><div class="text-muted small">Récapitulatif des primes émises, renouvellements et avenants.</div></div>';
                    }
                    return '<div><strong>' . $key . '</strong></div>';
                },
            ])
            ->add('nom', TextType::class, [
                'label' => "Nom",
                'attr' => [
                    'placeholder' => "Nom",
                ],
            ])
            ->add('receivedAt', DateTimeType::class, [
                'label' => "Date de réception",
                'widget' => 'single_text',
            ])
            ->add('montantTTC', MoneyType::class, [
                'label' => "Montant",
                'required' => false,
                'currency' => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                'grouping' => true,
                'attr' => [
                    'placeholder' => "Montant",
                ],
            ])
            ->add('assureur', AssureurAutocompleteField::class, [
                'label' => "Assureur",
                'placeholder' => "Sélectionnez un assureur",
            ])
            ->add('documents', CollectionType::class, [
                'label' => "Documents",
                'entry_type' => DocumentType::class,
                'by_reference' => false,
                'allow_add' => true,
                'allow_delete' => true,
                'entry_options' => [
                    'label' => false,
                ],
                'attr' => [
                    'data-controller' => 'form-collection-entites',
                    'data-form-collection-entites-data-value' => json_encode([
                        'addLabel' => $this->translatorInterface->trans("commom_add"),
                        'deleteLabel' => $this->translatorInterface->trans("commom_delete"),
                        'icone' => "document",
                        'dossieractions' => 0,  //1=On doit chercher l'icone "role" dans le dossier ICONES/ACTIONS, sinon on la chercher dans le dossier racine càd le dossier ICONES (le dossier racime)
                        'tailleMax' => 1,
                    ]),
                ],
            ])
            // ->addEventListener(FormEvents::POST_SUBMIT, $this->ecouteurFormulaire->setUtilisateur())
            ->addEventListener(FormEvents::POST_SUBMIT, $this->ecouteurFormulaire->timeStamps())
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Bordereau::class,
            'csrf_protection' => false,
            'allow_extra_fields' => true,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}
