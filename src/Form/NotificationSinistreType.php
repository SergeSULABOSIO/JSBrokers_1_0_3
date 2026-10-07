<?php

namespace App\Form;

use App\Entity\Client;
use App\Entity\Risque;
use App\Entity\Assureur;
use App\Services\ServiceMonnaies;
use App\Entity\NotificationSinistre;
use App\Services\FormListenerFactory;
use App\Service\Workspace\CabinetActif;
use App\Services\ReferencesDePolice;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
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
        private ReferencesDePolice $referencesDePolice,
        private CabinetActif $cabinetActif,
    ) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('assureur', AssureurAutocompleteField::class, [
                'class' => Assureur::class,
                'label' => "Assureur concerné",
                'placeholder' => "Taper pour chercher l'assureur...",
                'required' => true,
            ])
            ->add('risque', RisqueAutocompleteField::class, [
                'class' => Risque::class,
                'label' => "Couverture d'assurance concernée",
                'placeholder' => 'Taper pour chercher un risque...',
                'required' => true,
            ])
            ->add('assure', ClientAutocompleteField::class, [
                'class' => Client::class,
                'label' => "Client concernée",
                'placeholder' => 'Taper pour chercher le client...',
                'required' => true,
            ])
            // LA POLICE SE CHOISIT, ELLE NE SE TAPE PLUS.
            //
            // C'était un champ de texte libre : on pouvait y écrire n'importe quoi, et rien
            // ne le relevait. Un sinistre finissait rattaché à une police inexistante — on
            // s'en apercevait en réclamant à l'assureur. Les choix sont désormais les
            // références RÉELLES du cabinet, et le validateur d'entité applique la même
            // règle à toute écriture, assistant compris.
            //
            // Les choix se posent dans un écouteur, pas ici : l'assuré n'est connu qu'une
            // fois l'entité hydratée — y compris quand « Créer un sinistre » vient de le
            // poser depuis la fiche d'un client.
            ->add('referencePolice', ChoiceType::class, [
                'help' => "Choisissez la police concernée parmi celles de votre portefeuille.",
                'label' => "Référence de la police",
                'required' => true,
                'placeholder' => 'Sélectionner une police',
                'autocomplete' => true,
                'choices' => [],
                'attr' => [
                    'placeholder' => "Taper pour chercher une police...",
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
            ->add('contacts', CollectionType::class, [
                'label' => "Liste des contacts", // Sera surchargé par le widget mais bon à garder
                'help' => "Personnes clés à contacter.",
                'entry_type' => ContactType::class,
                'by_reference' => false,
                'allow_add' => true,
                'allow_delete' => true,
                'entry_options' => ['label' => false],
                // On indique que ce champ ne doit pas être mappé directement
                // car on le gère entièrement en AJAX.
                'mapped' => false,
            ])
            // --- AJOUT DES NOUVELLES COLLECTIONS ---
            ->add('pieces', CollectionType::class, [
                'label' => "Pièces",
                'help' => "Pièces justificatives.",
                'entry_type' => PieceSinistreType::class,
                'by_reference' => false,
                'allow_add' => true,
                'allow_delete' => true,
                'entry_options' => ['label' => false],
                'mapped' => false,
            ])
            ->add('offreIndemnisationSinistres', CollectionType::class, [
                'label' => "Offres d'indemnisation",
                'help' => "Propositions d'indemnisation.",
                'entry_type' => OffreIndemnisationSinistreType::class,
                'by_reference' => false,
                'allow_add' => true,
                'allow_delete' => true,
                'entry_options' => ['label' => false],
                'mapped' => false,
            ])
            ->add('taches', CollectionType::class, [
                'label' => "Tâches à effectuer",
                'help' => "Actions et compte-rendus.",
                'entry_type' => TacheType::class,
                'by_reference' => false,
                'allow_add' => true,
                'allow_delete' => true,
                'entry_options' => ['label' => false],
                'mapped' => false,
            ])
            // PIÈCES JOINTES de cette fiche. `mapped: false` comme les onze autres
            // collections de documents du projet : chaque pièce est créée, modifiée et
            // supprimée par son propre dialogue, via l'API de Document — le formulaire
            // parent ne fait que porter le widget.
            ->add('documents', CollectionType::class, [
                'label' => "Documents",
                'entry_type' => DocumentType::class,
                'by_reference' => false,
                'allow_add' => true,
                'allow_delete' => true,
                'required' => false,
                'entry_options' => ['label' => false],
                'mapped' => false,
            ])
        ;

        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $evenement): void {
            $this->poserLesPolices($evenement->getForm(), $evenement->getData());
        });
    }

    /**
     * LES POLICES PROPOSÉES DÉPENDENT DE L'ASSURÉ — donc de la donnée, pas du type.
     *
     * Quand l'assuré est connu, on ne propose QUE ses polices : lui en offrir d'autres
     * serait inviter à l'erreur que le validateur refusera ensuite.
     *
     * ⚠ LA VALEUR DÉJÀ ENREGISTRÉE EST TOUJOURS REMISE DANS LA LISTE, même introuvable.
     * Des sinistres anciens portent une référence saisie à la main : sans cela, leur fiche
     * deviendrait inouvrable — un ChoiceType refuse une valeur hors de ses choix — et on ne
     * pourrait même plus y corriger un numéro de téléphone. Elle est marquée pour que le
     * défaut se voie.
     */
    private function poserLesPolices(FormInterface $formulaire, ?NotificationSinistre $sinistre): void
    {
        $entreprise = $this->cabinetActif->entreprise();
        if ($entreprise === null) {
            return;
        }

        $choix = $this->referencesDePolice->pourLeCabinet($entreprise, $sinistre?->getAssure());

        $actuelle = trim((string) $sinistre?->getReferencePolice());
        if ($actuelle !== '' && !in_array($actuelle, $choix, true)) {
            $choix[$actuelle . ' — référence introuvable'] = $actuelle;
        }

        $champ = $formulaire->get('referencePolice');
        $options = $champ->getConfig()->getOptions();
        $options['choices'] = $choix;
        $formulaire->add('referencePolice', ChoiceType::class, $options);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => NotificationSinistre::class,
            'csrf_protection' => false,
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
