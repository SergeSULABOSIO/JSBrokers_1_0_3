<?php

namespace App\Form;

use App\Constantes\Constante;
use App\Entity\CompteBancaire;
use App\Entity\Paiement;
use App\Entity\FactureCommission;
use App\Services\FormListenerFactory;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\AbstractType;
use App\Entity\OffreIndemnisationSinistre;
use App\Services\ServiceMonnaies;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;

class PaiementType extends AbstractType
{
    public function __construct(
        private FormListenerFactory $ecouteurFormulaire,
        private TranslatorInterface $translatorInterface,
        private ServiceMonnaies $serviceMonnaies,
        private Constante $constante,
    ) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $refNote = "";
        $montPayable = 0;
        $montPaye = 0;
        $montSolde = 0;
        if (isset($options['data'])) {
            /** @var Paiement $objetPaiement */
            $objetPaiement = $options['data'];
            if ($objetPaiement != null) {
                $note = $objetPaiement->getNote();
                if ($note != null) {
                    $refNote = $note->getReference();
                    $montPayable = $this->constante->Note_getMontant_payable($note);
                    $montPaye = $this->constante->Note_getMontant_paye($note);
                    $montSolde = $this->constante->Note_getMontant_solde($note);
                }
            }
        }

        // dd($objetPaiement);
        $builder
            ->add('referenceNote', TextType::class, [
                'label' => "Note ou Facture",
                'data' => $refNote,
                'mapped' => false,
                'attr' => [
                    'readonly' => true,
                    'placeholder' => "Référence",
                ],
            ])
            ->add('montantPayable', MoneyType::class, [
                'label' => "Montant payable",
                'currency' => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                'data' => $montPayable,
                'required' => false,
                'mapped' => false,
                'grouping' => true,
                'attr' => [
                    'readonly' => true,
                    'placeholder' => "Montant",
                ],
            ])
            ->add('montantPaye', MoneyType::class, [
                'label' => "Montant payé",
                'currency' => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                'data' => $montPaye,
                'required' => false,
                'mapped' => false,
                'grouping' => true,
                'attr' => [
                    'readonly' => true,
                    'placeholder' => "Montant",
                ],
            ])
            ->add('montantSolde', MoneyType::class, [
                'label' => "Solde restant dû",
                'currency' => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                'required' => false,
                'data' => $montSolde,
                'mapped' => false,
                'grouping' => true,
                'attr' => [
                    'placeholder' => "Montant",
                    'readonly' => true,
                ],
            ]);


        $builder
            ->add('description', TextareaType::class, [
                'label' => "Description",
                'attr' => [
                    'placeholder' => "Description",
                ],
            ])
            ->add('montant', MoneyType::class, [
                'label' => "Montant en cours de paiement",
                'currency' => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                'required' => false,
                'grouping' => true,
                'attr' => [
                    'placeholder' => "Montant",
                ],
            ])
            ->add('reference', TextType::class, [
                'label' => "Référence",
                'attr' => [
                    'placeholder' => "Référence",
                ],
            ])
            ->add('paidAt', DateTimeType::class, [
                'label' => "Date de paiement",
                'widget' => 'single_text',
            ])
            ->add('CompteBancaire', EntityType::class, [
                'label' => "Compte bancaire",
                'required' => true,
                'class' => CompteBancaire::class,
                'choice_label' => 'nom',
            ])
            ->add('preuves', CollectionType::class, [
                'label' => "Documents ou preuve de paiement",
                'help' => "Preuve de paiement ou tout autre document.",
                'entry_type' => DocumentType::class,
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
                        'icone' => "document",
                        'dossieractions' => 0,  //1=On doit chercher l'icone "role" dans le dossier ICONES/ACTIONS, sinon on la chercher dans le dossier racine càd le dossier ICONES (le dossier racime)
                        'tailleMax' => 5,
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
            // ->addEventListener(FormEvents::POST_SUBMIT, $this->ecouteurFormulaire->setUtilisateur())
            ->addEventListener(FormEvents::POST_SUBMIT, $this->ecouteurFormulaire->timeStamps())
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Paiement::class,
            'note' => null,
            'parent_object' => null, // l'objet parent
        ]);
    }
}
