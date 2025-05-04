<?php

namespace App\Form;

use App\Services\FormListenerFactory;
use Symfony\Component\Form\AbstractType;
use App\Entity\OffreIndemnisationSinistre;
use App\Services\ServiceMonnaies;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;

class OffreIndemnisationSinistreType extends AbstractType
{
    public function __construct(
        private FormListenerFactory $ecouteurFormulaire,
        private ServiceMonnaies $serviceMonnaies,
        private TranslatorInterface $translatorInterface
    ) {}
    
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', TextType::class, [
                'label' => "Nom",
                'required' => true,
                'attr' => [
                    'placeholder' => "Nom",
                ],
            ])
            ->add('beneficiaire', TextType::class, [
                'help' => "Nom du bénéficiaire ou le client lui-même.",
                'label' => "Bénéficiaire",
                'required' => true,
                'attr' => [
                    'placeholder' => "Bénéficiaire",
                ],
            ])
            ->add('franchiseAppliquee', MoneyType::class, [
                'label' => "Franchise appliquée",
                'currency' => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                'grouping' => true,
                'attr' => [
                    'placeholder' => "Franchise",
                ],
            ])
            ->add('montantPayable', MoneyType::class, [
                'label' => "Montant payable / Compensation",
                'help' => "Solde payable après application de la franchise au coût / valeure totale de reparation.",
                'currency' => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                'grouping' => true,
                'attr' => [
                    'placeholder' => "Montant payable",
                ],
            ])
            ->add('referenceBancaire', TextType::class, [
                'help' => "Référence bancaire du bénéficiaire.",
                'label' => "Référence bancaire",
                'required' => true,
                'attr' => [
                    'placeholder' => "Référence bancaire",
                ],
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
                        'tailleMax' => 20,
                    ]),
                ],
            ])
            ->add('paiements', CollectionType::class, [
                'label' => "Paiements",
                'entry_type' => PaiementType::class,
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
                        'icone' => "paiement",
                        'dossieractions' => 0,  //1=On doit chercher l'icone "role" dans le dossier ICONES/ACTIONS, sinon on la chercher dans le dossier racine càd le dossier ICONES (le dossier racime)
                        'tailleMax' => 20,
                    ]),
                ],
            ])
            ->add('taches', CollectionType::class, [
                'label' => "Liste des taches",
                'help' => "Tâches ou actions à exécuter par les utilisateurs dans le cadre de cette notification.",
                'entry_type' => TacheType::class,
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
                        'icone' => "tache",
                        'dossieractions' => 0,  //1=On doit chercher l'icone "role" dans le dossier ICONES/ACTIONS, sinon on la chercher dans le dossier racine càd le dossier ICONES (le dossier racime)
                        'tailleMax' => 20,
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
            'data_class' => OffreIndemnisationSinistre::class,
            'parent_object' => null, // l'objet parent
        ]);
    }
}
