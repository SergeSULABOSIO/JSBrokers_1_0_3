<?php

namespace App\Form;

use App\Entity\Entreprise;
use App\Entity\Partenaire;
use App\Services\FormListenerFactory;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
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
                'attr' => [
                    'data-controller' => 'form-collection-entites',
                    'data-form-collection-entites-data-value' => json_encode([
                        'addLabel' => $this->translatorInterface->trans("commom_add"),
                        'deleteLabel' => $this->translatorInterface->trans("commom_delete"),
                        'icone' => "partenaire",
                        'dossieractions' => 0,  //1=On doit chercher l'icone "role" dans le dossier ICONES/ACTIONS, sinon on la chercher dans le dossier racine càd le dossier ICONES (le dossier racime)
                        'tailleMax' => 1,
                    ]),
                ],
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
                'attr' => [
                    'data-controller' => 'form-collection-entites',
                    'data-form-collection-entites-data-value' => json_encode([
                        'addLabel' => $this->translatorInterface->trans("commom_add"),
                        'deleteLabel' => $this->translatorInterface->trans("commom_delete"),
                        'icone' => "document",
                        'dossieractions' => 0,  //1=On doit chercher l'icone "role" dans le dossier ICONES/ACTIONS, sinon on la chercher dans le dossier racine càd le dossier ICONES (le dossier racime)
                        'tailleMax' => 5,
                    ]),
                ],
            ])
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
