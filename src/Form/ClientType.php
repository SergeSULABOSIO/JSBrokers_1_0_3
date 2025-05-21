<?php

namespace App\Form;

use App\Entity\Client;
use App\Entity\Groupe;
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
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;

class ClientType extends AbstractType
{

    public function __construct(
        private FormListenerFactory $ecouteurFormulaire,
        private TranslatorInterface $translatorInterface
    ) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('civilite', ChoiceType::class, [
                'label' => "Status",
                'required' => true,
                'expanded' => false,
                'choices'  => [
                    "Mr." => Client::CIVILITE_Mr,
                    "Mme." => Client::CIVILITE_Mme,
                    "Sté." => Client::CIVILITE_ENTREPRISE,
                    "Asbl." => Client::CIVILITE_ASBL,
                ],
            ])
            ->add('nom', TextType::class, [
                'label' => "Nom",
                'attr' => [
                    'placeholder' => "Nom",
                ],
            ])
            ->add('groupe', EntityType::class, [
                // 'help' => "Le groupe ou la famille ou encore conglomérat auquel apprtient cette entité.",
                'label' => "Groupe / Catégorie",
                'autocomplete' => true,
                'class' => Groupe::class,
                'required' => false,
                'choice_label' => 'nom',
            ])
            ->add('adresse', TextType::class, [
                'label' => "Adresse physique",
                'required' => false,
                'attr' => [
                    'placeholder' => "Adresse physique",
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
            ->add('exonere', ChoiceType::class, [
                'label' => "Le client est-il exonéré des taxes?",
                'help' => "Oui, si le client n'est pas sensé payer de taxe tels que des ONGs.",
                'expanded' => false,
                'choices'  => [
                    "Oui" => true,
                    "Non" => false,
                ],
            ])
            ->add('numimpot', TextType::class, [
                'label' => "Nunméro Impôt (Nif)",
                'required' => false,
                'attr' => [
                    'placeholder' => "NIF",
                ],
            ])
            ->add('rccm', TextType::class, [
                'label' => "Nunméro RCCM (Rccm)",
                'required' => false,
                'attr' => [
                    'placeholder' => "RCCM",
                ],
            ])
            ->add('idnat', TextType::class, [
                'label' => "Nunméro d'Id. nationale (Idnat)",
                'required' => false,
                'attr' => [
                    'placeholder' => "Idnat",
                ],
            ])
            ->add('partenaires', PartenaireAutocompleteField::class, [
                'required' => false,
                'label' => "Partenaires",
                'class' => Partenaire::class,
                'choice_label' => "nom",
                'help' => "Les rétrocommissions seront prélévées dans l'ensemble du portfeuil de ce client puis partagées aves les partenaires figurant sur cette liste.",
                'multiple' => true,
                'expanded' => false,
                'by_reference' => false,
                'autocomplete' => true,
                'attr' => [
                    'placeholder' => "Les intermédiaires",
                ],
            ])
            ->add('contacts', CollectionType::class, [
                'label' => "entreprise_form_label_contacts",
                'entry_type' => ContactType::class,
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
                        'icone' => "contact",
                        'dossieractions' => 0,  //1=On doit chercher l'icone "role" dans le dossier ICONES/ACTIONS, sinon on la chercher dans le dossier racine càd le dossier ICONES (le dossier racime)
                        'tailleMax' => 10,
                    ]),
                ],
            ])
            ->add('documents', CollectionType::class, [
                'label' => "entreprise_form_label_documents",
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
                        'tailleMax' => 10,
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
            'data_class' => Client::class,
            'parent_object' => null, // l'objet parent
        ]);
    }
}
