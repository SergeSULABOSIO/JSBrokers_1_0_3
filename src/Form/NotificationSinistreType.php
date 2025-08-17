<?php

namespace App\Form;

use App\Entity\Client;
use App\Entity\Risque;
use App\Entity\Assureur;
use App\Services\ServiceMonnaies;
use App\Entity\NotificationSinistre;
use App\Services\FormListenerFactory;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;



class NotificationSinistreType extends AbstractType
{
    public function __construct(
        private FormListenerFactory $ecouteurFormulaire,
        private TranslatorInterface $translatorInterface,
        private ServiceMonnaies $serviceMonnaies,
    ) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('assureur', AssureurAutocompleteField::class, [
                'class' => Assureur::class,
                'label' => "Assureur concerné",
                'placeholder' => "Taper pour chercher l'assureur...",
                'required' => true,
                'choice_label' => 'nom',
                'searchable_fields' => ['nom', 'email'],
            ])
            ->add('risque', RisqueAutocompleteField::class, [
                'class' => Risque::class,
                'label' => "Couverture d'assurance concernée",
                'placeholder' => 'Taper pour chercher un risque...',
                'required' => true,
                'choice_label' => 'nomComplet',
                'searchable_fields' => ['nomComplet', 'code'],
            ])
            ->add('assure', ClientAutocompleteField::class, [
                'class' => Client::class,
                'label' => "Client concernée",
                'placeholder' => 'Taper pour chercher le client...',
                'required' => true,
                'choice_label' => 'nom',
                'searchable_fields' => ['nom', 'code'],
            ])
            ->add('referencePolice', TextType::class, [
                'help' => "Vous devez fournir la référence de la police d'assurance",
                'label' => "Référence de la police",
                'required' => true,
                'attr' => [
                    'placeholder' => "Réf. Police",
                ],
            ])
            ->add('referenceSinistre', TextType::class, [
                'label' => "Référence du sinistre",
                'help' => "Si vous n'avez pas encore de numéro sinistre, veuillez sauter ce champ.",
                'required' => false,
                'attr' => [
                    'placeholder' => "Réf. Sinistre",
                ],
            ])
            ->add('descriptionDeFait', TextareaType::class, [
                'label' => "Description des faits",
                'required' => true,
                'attr' => [
                    'class' => 'editeur-riche',
                    'placeholder' => "Description",
                ],
            ])
            ->add('descriptionVictimes', TextareaType::class, [
                'label' => "Description ou détails sur les victimes",
                'required' => true,
                'attr' => [
                    'class' => 'editeur-riche',
                    'placeholder' => "Victimes",
                ],
            ])
            ->add('notifiedAt', DateType::class, [
                'label' => "Date de la notification",
                'required' => true,
                'widget' => 'single_text',
            ])
            ->add('occuredAt', DateType::class, [
                'label' => "Date de la survénance",
                'required' => true,
                'widget' => 'single_text',
            ])
            ->add('lieu', TextType::class, [
                'label' => "Lieu de survénance",
                'attr' => [
                    'placeholder' => "Lieu",
                ],
            ])
            ->add('dommage', MoneyType::class, [
                'label' => "Valeur de la perte",
                'help' => "Il s'agit d'une estimation chiffrée du coût de reparation des dégats causés et/ou subis lors de l'évènement survenu.",
                'currency' => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                'required' => false,
                'grouping' => true,
                'attr' => [
                    'placeholder' => "Dommage",
                ],
            ])
            ->add('evaluationChiffree', MoneyType::class, [
                'label' => "Evaluation chiffrée",
                'help' => "Il s'agit d'une confirmation chiffré du dommage après évaluation.",
                'currency' => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                'grouping' => true,
                'required' => false,
                'attr' => [
                    'placeholder' => "Evaluation ciffrée",
                ],
            ])
            
            // ->add('contacts', CollectionType::class, [
            //     'label' => "Liste des personnes clées, à contacter pour tout ce qui concerne cette reclamation.",
            //     'entry_type' => ContactType::class,
            //     'by_reference' => false,
            //     'allow_add' => true,
            //     'allow_delete' => true,
            //     'entry_options' => [
            //         'label' => false,
            //     ],
            // ])
            ->add('contacts', CollectionType::class, [
                'label' => "Liste des contacts", // Sera surchargé par le widget mais bon à garder
                'help' => "Personnes clées, à contacter pour tout ce qui concerne cette réclamation.",
                'entry_type' => ContactType::class,
                'by_reference' => false,
                'allow_add' => true,
                'allow_delete' => true,
                'entry_options' => ['label' => false],
                // On indique que ce champ ne doit pas être mappé directement
                // car on le gère entièrement en AJAX.
                'mapped' => false,
            ])

        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => NotificationSinistre::class,
            // AJOUT 1: Désactive la protection CSRF pour ce formulaire API
            'csrf_protection' => false,

            // AJOUT 2: Autorise les champs non définis dans le form (comme 'id') à être envoyés
            'allow_extra_fields' => true,
        ]);
    }

    /**
     * AJOUTEZ CETTE MÉTHODE
     * * En retournant une chaîne vide, on dit à Symfony de ne pas
     * préfixer les champs du formulaire. Le formulaire n'aura pas de nom racine.
     */
    public function getBlockPrefix(): string
    {
        return '';
    }
}
