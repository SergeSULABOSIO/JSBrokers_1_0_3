<?php

namespace App\Form;

use App\Entity\Partenaire;
use App\Services\FormListenerFactory;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PercentType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;

class PartenaireType extends AbstractType
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
            ->add('adressePhysique', TextType::class, [
                'label' => "Adresse Physique",
                'required' => false,
                'attr' => [
                    'placeholder' => "Adresse",
                ],
            ])
            ->add('telephone', TextType::class, [
                'label' => "Téléphone",
                'required' => false,
                'attr' => [
                    'placeholder' => "Téléphone",
                ],
            ])
            ->add('email', EmailType::class, [
                'label' => "Email",
                'required' => false,
                'attr' => [
                    'placeholder' => "Email",
                ],
            ])
            ->add('numimpot', TextType::class, [
                'label' => "Nunméro Impôt (Nif)",
                'attr' => [
                    'placeholder' => "NIF",
                ],
            ])
            ->add('rccm', TextType::class, [
                'label' => "Nunméro RCCM (Rccm)",
                'attr' => [
                    'placeholder' => "RCCM",
                ],
            ])
            ->add('idnat', TextType::class, [
                'label' => "Nunméro d'Id. nationale (Idnat)",
                'attr' => [
                    'placeholder' => "Idnat",
                ],
            ])
            ->add('part', PercentType::class, [
                'label' => "Part du partenaire",
                'help' => "Ce pourcentage ne s'appliquera que sur les commissions hors taxes (l'assiette partageable).",
                'required' => true,
                'scale' => 3,
                'attr' => [
                    'placeholder' => "Part",
                ],
            ])
            ->add('conditionPartages', CollectionType::class, [
                'label' => "Conditions spéciales de partage",
                'entry_type' => ConditionPartageType::class,
                'by_reference' => false,
                'allow_add' => true,
                'allow_delete' => true,
                'required' => false,
                'entry_options' => [
                    'label' => false,
                ],
                'mapped' => false,
            ])
            ->add('clients', ClientAutocompleteField::class, [
               'label' => "Clients",
               'placeholder' => "Chercher un client",
               'required' => false,
               'multiple' => true,
               'by_reference' => false,
            ])
            ->add('documents', CollectionType::class, [
                'label' => "Documents",
                'entry_type' => DocumentType::class,
                'by_reference' => false,
                'allow_add' => true,
                'required' => false,
                'allow_delete' => true,
                'entry_options' => [
                    'label' => false,
                ],
                'mapped' => false,
            ])
           ->addEventListener(FormEvents::POST_SUBMIT, $this->ecouteurFormulaire->timeStamps())
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Partenaire::class,
            'csrf_protection' => false,
            'allow_extra_fields' => true,
        ]);
    }

    /**
     * En retournant une chaîne vide, on dit à Symfony de ne pas
     * préfixer les champs du formulaire. Le formulaire n'aura pas de nom racine.
     * Cela est essentiel pour les formulaires soumis via API/AJAX qui envoient
     * des données "plates" plutôt que des données imbriquées sous le nom du formulaire.
     *
     * @return string
     */
    public function getBlockPrefix(): string
    {
        return '';
    }
}
