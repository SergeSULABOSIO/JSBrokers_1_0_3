<?php

namespace App\Form;

use App\Entity\Avenant;
use App\Services\FormListenerFactory;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;

class AvenantType extends AbstractType
{
    /**
     * Libellés du statut de renouvellement (valeur → texte). Source unique du
     * champ ; alignée sur AvenantIndicatorStrategy::getAvenantStatutRenouvellementString().
     */
    public const RENEWAL_STATUS_LABELS = [
        Avenant::RENEWAL_STATUS_RUNNING  => "En cours",
        Avenant::RENEWAL_STATUS_RENEWING => "En renouvellement",
        Avenant::RENEWAL_STATUS_RENEWED  => "Renouvelé",
        Avenant::RENEWAL_STATUS_EXTENDED => "Prorogé",
        Avenant::RENEWAL_STATUS_CANCELLED => "Annulé / résilié",
        Avenant::RENEWAL_STATUS_ONCE_OFF => "Unique (sans renouvellement)",
        Avenant::RENEWAL_STATUS_LOST     => "Perdu",
    ];

    public function __construct(
        private FormListenerFactory $ecouteurFormulaire,
        private TranslatorInterface $translatorInterface
    ) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('referencePolice', TextType::class, [
                'required' => true,
                'label' => "Référence de la police",
                'attr' => [
                    'placeholder' => "Référence de la police",
                ],
            ])
            ->add('numero', TextType::class, [
                'required' => true,
                'label' => "Numéro",
                'attr' => [
                    'placeholder' => "Numéro",
                ],
            ])
            ->add('description', TextareaType::class, [
                'required' => false,
                'label' => "Description",
                'attr' => [
                    'placeholder' => "Description",
                ],
            ])
            ->add('startingAt', DateTimeType::class, [
                'label' => "Date début",
                'widget' => 'single_text',
            ])
            ->add('endingAt', DateTimeType::class, [
                'label' => "Echéance",
                'widget' => 'single_text',
            ])
            // Statut de renouvellement de la police. Colonne stockée que deux KPI du
            // tableau de bord interrogent (DashboardDataProvider::getPoliciesActives /
            // getAvenantsActifsHydrates) : sans champ de formulaire, une police annulée
            // ou résiliée restait comptée « active » à jamais, et aucun chemin — écran
            // comme assistant — ne pouvait la corriger.
            ->add('renewalStatus', ChoiceType::class, [
                'label' => "Statut de la police",
                'help' => "Laissez « En cours » tant que la police court. Un mouvement (renouvellement, prorogation, résiliation) met ce statut à jour.",
                'required' => false,
                'placeholder' => false,
                'choices' => array_flip(self::RENEWAL_STATUS_LABELS),
            ])
            // DÉCISION DE NE PAS RENOUVELER. Ces deux champs existent AUSSI pour l'assistante :
            // WorkspaceMutationService n'écrit que ce que le FormType expose (allow_extra_fields
            // ignore le reste EN SILENCE), donc sans eux un plan validé s'exécuterait sans rien
            // changer. L'auteur (nonRenouvelablePar) n'a pas besoin d'un champ : c'est une
            // relation to-one propriétaire, pré-hydratée par le moteur depuis son id.
            //
            // false_values explicite : le moteur normalise les booléens du LLM en '1'/'0', or
            // CheckboxType tient par défaut TOUTE valeur non nulle pour vraie — '0' aurait donc
            // MARQUÉ la police au lieu de lever le marquage.
            ->add('nonRenouvelable', CheckboxType::class, [
                'label' => "Ne pas renouveler cette police",
                'help' => "Signale que la police n'aura pas de suite. Elle sort du suivi des échéances, mais la couverture court jusqu'à son terme et tout ce qui reste dû continue d'être réclamé.",
                'required' => false,
                'false_values' => [null, '0', 'false', ''],
            ])
            ->add('nonRenouvelableMotif', TextareaType::class, [
                'label' => "Motif de non-renouvellement",
                'help' => "Écrit pour le collègue qui rouvrira ce dossier dans plusieurs mois. Note interne : jamais transmise au client.",
                'required' => false,
                'attr' => [
                    'rows' => 3,
                    'placeholder' => "Ex. : le client revend sa flotte en fin d'année.",
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
                        'tailleMax' => 10,
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
            'data_class' => Avenant::class,
            'csrf_protection' => false,
            'allow_extra_fields' => true,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}
