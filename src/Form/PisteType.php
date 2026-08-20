<?php

namespace App\Form;

use App\Entity\Piste;
use App\Entity\ConditionPartage;
use App\Entity\Partenaire;
use App\Services\FormListenerFactory;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;

class PisteType extends AbstractType
{
    /**
     * Libellés des types d'avenant (valeur → texte). Source unique, utilisée à la fois
     * pour les choix du champ, pour la synchronisation dynamique du préfixe du nom
     * côté client (contrôleur Stimulus « piste-name-sync ») et pour les mouvements de
     * police de l'assistant IA (App\Ai\Mouvement\MouvementAvenant::libelle()).
     */
    public const TYPE_AVENANT_LABELS = [
        Piste::AVENANT_SOUSCRIPTION   => "Souscription",
        Piste::AVENANT_INCORPORATION  => "Incorporation",
        Piste::AVENANT_PROROGATION    => "Prorogation",
        Piste::AVENANT_ANNULATION     => "Annulation",
        Piste::AVENANT_RENOUVELLEMENT => "Renouvellement",
        Piste::AVENANT_RESILIATION    => "Résiliation",
    ];

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
                    'placeholder' => "Nom de la piste",
                ],
            ])
            ->add('client', ClientAutocompleteField::class, [
                'label' => "Client / Assurée ou Prospect",
                'placeholder' => "Sélectionnez un client ou un prospect",
            ])
            ->add('typeAvenant', ChoiceType::class, [
                'label' => "Type d'Avenant",
                // PAS DE DÉFAUT ICI, et c'est délibéré : le type d'avenant est le
                // DISCRIMINANT de la piste — souscription, renouvellement, annulation,
                // résiliation. Le choisir à la place de l'utilisateur classerait une
                // police dans la mauvaise catégorie, en silence. La règle est verrouillée
                // par InventaireChampsValeursTest::testUnDiscriminantExigeNAnnonceAucunDefaut
                // (« un discriminant ne se devine pas »), et le prompt la répète : un champ
                // à liste fermée, obligatoire et sans défaut est un choix qui n'appartient
                // qu'à l'utilisateur — on le DEMANDE.
                'expanded' => true,
                'label_html' => true,
                'required' => true,
                // Choix dérivés de la source unique TYPE_AVENANT_LABELS (label => valeur).
                'choices'  => array_flip(self::TYPE_AVENANT_LABELS),
                'choice_label' => function ($choice, $key, $value) {
                    $desc = match ($choice) {
                        Piste::AVENANT_SOUSCRIPTION => "Création d'une nouvelle police d'assurance.",
                        Piste::AVENANT_INCORPORATION => "Ajout d'une garantie ou d'un assuré.",
                        Piste::AVENANT_PROROGATION => "Prolongation de la durée de la police.",
                        Piste::AVENANT_ANNULATION => "Annulation complète de la police.",
                        Piste::AVENANT_RENOUVELLEMENT => "Renouvellement de la police à son échéance.",
                        Piste::AVENANT_RESILIATION => "Rupture du contrat par l'une des parties.",
                        default => ""
                    };
                    return '<div><strong>' . $key . '</strong><div class="text-muted small">' . $desc . '</div></div>';
                },
            ])
            ->add('renewalCondition', ChoiceType::class, [
                'label' => "Type d'assurance.",
                'help' => "Type d'assurance selon les conditions de renouvellement.",
                'expanded' => true,
                'label_html' => true,
                'required' => true,
                'choices'  => [
                    "A terme renouvellable"           => Piste::RENEWAL_CONDITION_RENEWABLE,
                    "Avec ajustement"                 => Piste::RENEWAL_CONDITION_ADJUSTABLE_AT_EXPIRY,
                    "Temporaire non renouvellable"    => Piste::RENEWAL_CONDITION_ONCE_OFF_AND_EXTENDABLE,
                ],
                'choice_label' => function ($choice, $key, $value) {
                    $desc = match($choice) {
                        Piste::RENEWAL_CONDITION_RENEWABLE => "Contrat renouvelable tacitement.",
                        Piste::RENEWAL_CONDITION_ADJUSTABLE_AT_EXPIRY => "Prime ajustable à la fin de la période.",
                        Piste::RENEWAL_CONDITION_ONCE_OFF_AND_EXTENDABLE => "Couverture unique, extension possible.",
                        default => ""
                    };
                    return '<div><strong>' . $key . '</strong><div class="text-muted small">' . $desc . '</div></div>';
                },
            ])

            ->add('exercice', NumberType::class, [
                'label' => "Exercice",
                // DÉFAUT : l'exercice en cours. Une piste se crée presque toujours pour
                // l'année courante, et ce champ obligatoire faisait sinon poser une
                // question dont la réponse est au calendrier.
                'data' => (int) date('Y'),
                'grouping' => false,
                'attr' => [
                    'placeholder' => "Année",
                ],
            ])
            ->add('primePotentielle', MoneyType::class, [
                'label' => "Prime Potentielle",
                'currency' => "USD",
                'grouping' => true,
                'attr' => [
                    'placeholder' => "Prime potentielle",
                ],
            ])
            ->add('commissionPotentielle', MoneyType::class, [
                'label' => "Commission Potentielle",
                'currency' => "USD",
                'grouping' => true,
                'attr' => [
                    'placeholder' => "Commission potentielle",
                ],
            ])
            ->add('descriptionDuRisque', TextareaType::class, [
                'label' => "Description du risque",
                'attr' => [
                    'placeholder' => "Description du risque",
                ],
            ])
            ->add('risque', RisqueAutocompleteField::class, [
                'label' => "Couverture d'assurance",
                'required' => true,
                'placeholder' => 'Sélectionner un risque',
            ])
            // L'INTERMÉDIAIRE DE L'AFFAIRE — un seul, aligné sur ce que le moteur sait faire.
            //
            // Le champ acceptait plusieurs apporteurs alors que le calcul n'en retenait
            // qu'un, pris au hasard d'une table de liaison sans ordre : l'écran promettait
            // un partage que personne n'aurait su honorer.
            ->add('partenaire', PartenaireAutocompleteField::class, [
                'required' => false,
                'label' => "Intermédiaire externe",
                'help' => "L'apporteur de cette affaire. Sans lui, aucune commission n'est partagée. "
                    . "Son taux habituel s'applique, sauf condition contraire ci-dessous.",
                'class' => Partenaire::class,
                'choice_label' => "nom",
                'multiple' => false,
                'expanded' => false,
                'autocomplete' => true,
                'attr' => [
                    'placeholder' => "Choisir l'intermédiaire…",
                ],
            ])
            // RATTACHEMENT (et non création) des conditions au profit d'agents INTERNES.
            // La condition vit ailleurs et sert peut-être à dix autres affaires : on la
            // désigne, on ne la duplique pas. C'est ce qui la garde comme source unique —
            // à l'inverse de `conditionsPartageExceptionnelles` juste dessous, propriété
            // de cette piste et clonée au renouvellement.
            ->add('conditionsPartageAgent', ConditionPartageAgentAutocompleteField::class, [
                'required' => false,
                'label' => "Agents internes rémunérés",
                'class' => ConditionPartage::class,
                'choice_label' => "nom",
                'multiple' => true,
                'expanded' => false,
                'by_reference' => false,
                'autocomplete' => true,
                'attr' => [
                    'placeholder' => "Les conditions d'agents",
                ],
            ])
            ->add('conditionsPartageExceptionnelles', CollectionType::class, [
                'label' => "Liste des conditions spéciales de partage",
                'entry_type' => ConditionPartageType::class,
                'by_reference' => false,
                'allow_add' => true,
                'allow_delete' => true,
                'entry_options' => [
                    'label' => false,
                ],
                'mapped' => false,
            ])
            ->add('taches', CollectionType::class, [
                'label' => "Liste des tâches",
                'entry_type' => TacheType::class,
                'by_reference' => false,
                'allow_add' => true,
                'allow_delete' => true,
                'entry_options' => [
                    'label' => false,
                ],
                'mapped' => false,
            ])
            ->add('cotations', CollectionType::class, [
                'label' => "Liste des cotations",
                'entry_type' => CotationType::class,
                'by_reference' => false,
                'allow_add' => true,
                'allow_delete' => true,
                'entry_options' => [
                    'label' => false,
                ],
                'mapped' => false,
            ])
            ->add('documents', CollectionType::class, [
                'label' => "Liste des documents",
                'entry_type' => DocumentType::class,
                'by_reference' => false,
                'allow_add' => true,
                'allow_delete' => true,
                'entry_options' => [
                    'label' => false,
                ],
                'mapped' => false,
            ])
            // ->addEventListener(FormEvents::POST_SUBMIT, $this->ecouteurFormulaire->setUtilisateur())
            ->addEventListener(FormEvents::POST_SUBMIT, $this->ecouteurFormulaire->timeStamps())
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Piste::class,
            'csrf_protection' => false,
            'allow_extra_fields' => true,
            // Synchronisation dynamique du préfixe du nom avec le type d'avenant choisi
            // (contrôleur Stimulus « piste-name-sync » posé sur le <form>). Les libellés
            // sont transmis depuis la source unique TYPE_AVENANT_LABELS.
            'attr' => [
                'data-controller' => 'piste-name-sync',
                'data-piste-name-sync-labels-value' => json_encode(self::TYPE_AVENANT_LABELS),
            ],
        ]);
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}
