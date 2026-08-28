<?php

namespace App\Form;

use App\Entity\ConditionPartage;
use App\Entity\Invite;
use App\Entity\Partenaire;
use App\Services\FormListenerFactory;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\PercentType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;

class ConditionPartageType extends AbstractType
{
    /**
     * Les deux réponses possibles à « cette part revient à qui ? », quand la condition
     * appartient à une AFFAIRE. Ailleurs — sur la fiche d'un intermédiaire ou d'un agent —
     * le parent a déjà tranché et la question ne se pose pas.
     */
    public const BENEFICIAIRE_INTERMEDIAIRE = 'intermediaire';
    public const BENEFICIAIRE_AGENT = 'agent';

    public function __construct(
        private FormListenerFactory $ecouteurFormulaire,
        private TranslatorInterface $translatorInterface
    ) {}
    
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var ConditionPartage|null $condition */
        $condition = $builder->getData();
        $isCreationMode = !$condition || null === $condition->getId();

        // DÉFAUTS DE CRÉATION — posés ICI, comme les autres FormTypes du projet (étage
        // STATIQUE des défauts). Une condition ouverte depuis la fiche d'un agent a déjà
        // son bénéficiaire (injecté par le trait CRUD avant la construction du
        // formulaire) : on s'en sert pour proposer un formulaire déjà conséquent plutôt
        // que trois champs vides que l'utilisateur remplirait toujours pareil.
        //
        // Aucun défaut n'est un choix MÉTIER déguisé : le taux de 5 % et le seuil à zéro
        // sont des points de départ visibles et modifiables, pas des règles cachées.
        $agent = $condition?->getAgent();
        $nomParDefaut = null;
        if ($isCreationMode && $agent !== null) {
            $nomParDefaut = sprintf('Rétrocommission — %s', $agent->getNom() ?? 'agent');
        }

        $builder
            ->add('nom', TextType::class, [
                'label' => "Nom",
                'data' => $isCreationMode ? $nomParDefaut : $condition?->getNom(),
                'attr' => [
                    'placeholder' => "Nom",
                ],
            ])

            ->add('uniteMesure', ChoiceType::class, [
                'label' => "Unité de mésure",
                'help' => "L'unité de mésure représente l'indicateur où le seuil s'applique.",
                'expanded' => true,
                'choices'  => [
                   "La somme des commissions pures du risque" => ConditionPartage::UNITE_SOMME_COMMISSION_PURE_RISQUE,
                   "La somme des commissions pures du client" => ConditionPartage::UNITE_SOMME_COMMISSION_PURE_CLIENT,
                   "La somme des commissions pures du parténaire" => ConditionPartage::UNITE_SOMME_COMMISSION_PURE_PARTENAIRE,
                   // Pendant interne du précédent : toute la production dont l'agent est
                   // BÉNÉFICIAIRE sur l'exercice (jamais celle qu'il gère).
                   "La somme des commissions pures apportées par l'agent" => ConditionPartage::UNITE_SOMME_COMMISSION_PURE_AGENT,
                ],
                // Pour un agent, la seule unité qui parle de LUI : les trois autres
                // mesurent la production d'un partenaire ou d'un périmètre qui ne le
                // concerne pas. Reste modifiable — c'est un point de départ, pas un verrou.
                'data' => $isCreationMode && $agent !== null
                    ? ConditionPartage::UNITE_SOMME_COMMISSION_PURE_AGENT
                    : $condition?->getUniteMesure(),
            ])
            ->add('formule', ChoiceType::class, [
                'label' => "Formule",
                'expanded' => true,
                'choices'  => [
                   "Lorsque l'unité de mésure est au moins égale au seuil" => ConditionPartage::FORMULE_ASSIETTE_AU_MOINS_EGALE_AU_SEUIL,
                   "Lorsque l'unité de mésure est inférieure au seuil" => ConditionPartage::FORMULE_ASSIETTE_INFERIEURE_AU_SEUIL,
                   "Ne pas appliquer le seuil" => ConditionPartage::FORMULE_NE_SAPPLIQUE_PAS_SEUIL,
                ],
                // Sans seuil par défaut : la condition s'applique dès le premier franc.
                // C'est la formule la plus simple à comprendre, et celle qui ne réserve
                // aucune surprise à qui ne toucherait pas au champ.
                'data' => $isCreationMode
                    ? ConditionPartage::FORMULE_NE_SAPPLIQUE_PAS_SEUIL
                    : $condition?->getFormule(),
            ])
            ->add('seuil', NumberType::class, [
                'label' => "Seuil applicable",
                'help' => "Le seuil à appliquer dans la condition de partage. Zéro = tout montant "
                    . "produit par l'affaire, qu'il soit nul, positif ou négatif.",
                'required' => false,
                // Zéro plutôt que vide : un seuil absent et un seuil nul se comportent
                // pareil, mais seul le second se lit à l'écran.
                'data' => $isCreationMode ? 0.0 : $condition?->getSeuil(),
                'attr' => [
                    'placeholder' => "Seuil",
                ],
            ])
            ->add('taux', PercentType::class, [
                'label' => "Taux applicable",
                'help' => "Ce pourcentage ne s'appliquera que sur les commissions hors taxes (l'assiette partageable).",
                'required' => false,
                // Point de départ usuel d'un intéressement, en POINTS (5 = 5 %).
                'data' => $isCreationMode ? 5.0 : $condition?->getTaux(),
                // Stockage en POINTS (30 = 30 %), pas en fraction. Calculs via ConditionPartage::getFraction().
                'type' => 'integer',
                'scale' => 3,
                'attr' => [
                    'placeholder' => "Taux",
                ],
            ])
            ->add('critereRisque', ChoiceType::class, [
                'label' => "Critère sur le risque",
                'help' => "Comment s'applique cette condition par rapport au risque.",
                // `required: false` ajoutait un radio « None » en tête — un choix qui ne
                // veut rien dire et que la colonne, NOT NULL, refuse. Les trois options
                // couvrent tous les cas, et l'entité porte déjà son défaut.
                'required' => true,
                'placeholder' => false,
                'expanded' => true,
                'choices'  => [
                   "On ne partage pas quand il s'agit de risques ciblés" => ConditionPartage::CRITERE_EXCLURE_TOUS_CES_RISQUES,
                   "On ne partage que quand il s'agit de risques ciblés" => ConditionPartage::CRITERE_INCLURE_TOUS_CES_RISQUES,
                   "Il n'y a pas de risques ciblés" => ConditionPartage::CRITERE_PAS_RISQUES_CIBLES,
                ],
                // Aucun risque ciblé : la condition vaut pour toutes les affaires. Le champ
                // « Risques ciblés » reste donc masqué tant que l'utilisateur n'a pas
                // choisi de restreindre (visibility_conditions du canvas, cf. le provider).
                'data' => $isCreationMode
                    ? ConditionPartage::CRITERE_PAS_RISQUES_CIBLES
                    : $condition?->getCritereRisque(),
            ])
            
            ->add('produits', CollectionType::class, [
                'label' => "Risques ciblés",
                'entry_type' => RisqueType::class,
                'by_reference' => false,
                'allow_add' => true,
                'allow_delete' => true,
                'mapped' => false,
                'entry_options' => [
                    'label' => false,
                ],
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
            // ->addEventListener(FormEvents::POST_SUBMIT, $this->ecouteurFormulaire->setUtilisateur())
            // ->addEventListener(FormEvents::POST_SUBMIT, $this->ecouteurFormulaire->timeStamps())
        ;

        // LE BÉNÉFICIAIRE INTERNE, seulement quand la question se pose.
        //
        // Une condition rétrocède soit à un partenaire EXTERNE, soit à un agent du
        // cabinet — jamais aux deux. Sur une condition qui appartient déjà à un
        // partenaire, « Agent bénéficiaire » ne décrit rien : c'est un champ qu'on ne
        // remplira jamais, et dont le seul effet possible serait de déclencher le refus
        // de l'invariant. On ne le propose donc pas.
        //
        // Le champ ABSENT du formulaire est aussi un garde-fou : une valeur soumise
        // malgré tout est simplement ignorée par Symfony.
        if ($condition?->getPartenaire() === null) {
            $options = [
                'label' => "Agent bénéficiaire",
                'help' => "L'agent du cabinet à qui cette part est rétrocédée.",
                'required' => false,
                'attr' => [
                    'placeholder' => "Agent bénéficiaire",
                ],
            ];

            // UNE SEULE RÉPONSE POSSIBLE SE DONNE, ELLE NE SE DEMANDE PAS.
            //
            // Quand l'affaire ne rémunère qu'un seul agent, lui demander lequel revient à
            // faire chercher une information que l'écran possède déjà. Avec plusieurs
            // agents rattachés, en revanche, aucune réponse ne s'impose : le choix reste
            // entier plutôt que deviné (Bastien & Scapin > Charge de travail).
            $agentUnique = $this->agentUniqueDeLAffaire($condition);
            if ($agentUnique !== null && $condition?->getId() === null && $condition?->getAgent() === null) {
                $options['data'] = $agentUnique;
            }

            $builder->add('agent', InviteAutocompleteField::class, $options);
        }

        // LA QUESTION POSÉE FRANCHEMENT — seulement quand elle a un sens.
        //
        // Une condition qui appartient à une AFFAIRE peut rétrocéder à l'intermédiaire de
        // cette affaire, ou à un agent du cabinet. Le formulaire n'exposait jamais le champ
        // `partenaire` : ouvert depuis une piste, il ne proposait donc que `agent`, et son
        // aide affirmait « laisser vide pour un partenaire externe » — ce qui était faux,
        // puisque vide viole l'invariant et fait refuser l'écriture en 422. Une condition
        // pour l'intermédiaire était tout simplement impossible à créer depuis une affaire.
        //
        // Le choix est donc explicite, et il est APPLIQUÉ (ci-dessous) plutôt que deviné
        // d'un champ laissé vide — c'est cette implicitude qui avait produit la confusion.
        $piste = $condition?->getPiste();
        $autonome = $this->beneficiaireLibrementChoisi($condition);

        if ($piste !== null && $condition?->getPartenaire() === null) {
            $intermediaire = $piste->getPartenaire();

            $choix = [];
            // Sans intermédiaire sur l'affaire, l'option ne peut pas être honorée : on ne
            // la propose pas, plutôt que de la laisser échouer plus tard.
            if ($intermediaire !== null) {
                $choix["L'intermédiaire de cette affaire (" . $intermediaire->getNom() . ')'] = self::BENEFICIAIRE_INTERMEDIAIRE;
            }
            $choix['Un agent interne'] = self::BENEFICIAIRE_AGENT;

            $defaut = ($intermediaire === null || $condition?->estPourAgent())
                ? self::BENEFICIAIRE_AGENT
                : self::BENEFICIAIRE_INTERMEDIAIRE;

            $builder->add('beneficiaireType', ChoiceType::class, [
                'label' => "Cette part revient à",
                'help' => "Une condition rétrocède à UN bénéficiaire : l'intermédiaire de l'affaire, "
                    . "ou un agent du cabinet. Jamais aux deux.",
                'mapped' => false,
                'expanded' => true,
                'required' => true,
                'placeholder' => false,
                'choices' => $choix,
                'data' => $defaut,
            ]);

            $builder->addEventListener(
                FormEvents::POST_SUBMIT,
                static function (FormEvent $event) use ($intermediaire): void {
                    /** @var ConditionPartage $entite */
                    $entite = $event->getData();
                    $form = $event->getForm();
                    if ($entite === null || !$form->has('beneficiaireType')) {
                        return;
                    }

                    // On POSE le bénéficiaire au lieu de le déduire d'un champ vide :
                    // l'invariant « exactement un » est ainsi satisfait par construction,
                    // au lieu d'être découvert par un refus que rien n'expliquait.
                    if ($form->get('beneficiaireType')->getData() === self::BENEFICIAIRE_INTERMEDIAIRE) {
                        $entite->setPartenaire($intermediaire);
                        $entite->setAgent(null);

                        return;
                    }

                    $entite->setPartenaire(null);
                },
            );

            return;
        }

        // ── LA CRÉATION AUTONOME : LES DEUX FAMILLES, LIBREMENT ─────────────────────
        //
        // Ce formulaire ne déclarait AUCUN champ `partenaire`. Depuis la rubrique
        // « Conditions de partage », on ne pouvait donc créer que des conditions d'AGENT :
        // un partenaire n'y était pas désignable, et il fallait passer par sa fiche.
        // L'agent, lui, se choisissait librement d'un autocomplete — c'était toute
        // l'asymétrie.
        if (!$autonome) {
            return;
        }

        $builder->add('partenaire', PartenaireAutocompleteField::class, [
            'label' => 'Intermédiaire bénéficiaire',
            'help' => "L'apporteur externe à qui cette part est rétrocédée. "
                . "⚠ Une condition de partenaire s'applique à TOUTES les affaires qu'il apporte, "
                . "dès son enregistrement — là où celle d'un agent reste sans effet tant qu'on ne "
                . "l'a pas rattachée à des affaires.",
            'class' => Partenaire::class,
            'required' => false,
            'attr' => ['placeholder' => 'Intermédiaire bénéficiaire'],
        ]);

        $builder->add('beneficiaireType', ChoiceType::class, [
            'label' => "Cette part revient à",
            'help' => "Une condition rétrocède à UN bénéficiaire : un agent du cabinet, ou un "
                . "intermédiaire externe. Jamais aux deux.",
            'mapped' => false,
            'expanded' => true,
            'required' => true,
            'placeholder' => false,
            'choices' => [
                'Un agent interne' => self::BENEFICIAIRE_AGENT,
                'Un intermédiaire externe' => self::BENEFICIAIRE_INTERMEDIAIRE,
            ],
            'data' => $condition?->getPartenaire() !== null
                ? self::BENEFICIAIRE_INTERMEDIAIRE
                : self::BENEFICIAIRE_AGENT,
        ]);

        $builder->addEventListener(
            FormEvents::POST_SUBMIT,
            static function (FormEvent $event): void {
                /** @var ConditionPartage $entite */
                $entite = $event->getData();
                $form = $event->getForm();
                if ($entite === null || !$form->has('beneficiaireType')) {
                    return;
                }

                // ON POSE, ON NE DÉDUIT PAS. L'invariant « exactement un » est satisfait par
                // construction : le camp non retenu est vidé, quel qu'ait été le contenu du
                // champ. Sans cela, un agent saisi puis un intermédiaire choisi laisserait
                // les deux en place, et l'écriture serait refusée en 422 sans explication.
                if ($form->get('beneficiaireType')->getData() === self::BENEFICIAIRE_INTERMEDIAIRE) {
                    $entite->setAgent(null);

                    return;
                }

                $entite->setPartenaire(null);
            },
        );
    }

    /**
     * LE BÉNÉFICIAIRE EST-IL À CHOISIR, OU DÉJÀ IMPOSÉ ?
     *
     * Une condition créée depuis la fiche d'un agent ou d'un partenaire reçoit son
     * bénéficiaire du parent (`parentFieldName`) : lui redemander serait faire chercher une
     * réponse que l'écran possède déjà, et lui permettre de la contredire.
     *
     * Le choix ne s'ouvre donc que sur une condition AUTONOME — celle de la rubrique — et
     * sur une condition existante, qu'on doit pouvoir corriger. La distinction tient en un
     * point : une condition NEUVE qui porte déjà un bénéficiaire l'a reçu de sa fiche
     * parente.
     */
    private function beneficiaireLibrementChoisi(?ConditionPartage $condition): bool
    {
        if ($condition === null || $condition->getPiste() !== null) {
            return false;
        }

        $injecte = $condition->getId() === null
            && ($condition->getAgent() !== null || $condition->getPartenaire() !== null);

        return !$injecte;
    }

    /**
     * L'unique agent rémunéré par cette affaire, s'il n'y en a qu'un.
     *
     * On lit les conditions d'agents RATTACHÉES à la piste : ce sont elles qui désignent
     * qui, dans le cabinet, touche une part sur cette affaire. Deux conditions au profit
     * du même agent ne font qu'un bénéficiaire — c'est le nombre d'AGENTS distincts qui
     * décide, pas le nombre de lignes.
     */
    private function agentUniqueDeLAffaire(?ConditionPartage $condition): ?Invite
    {
        $piste = $condition?->getPiste();
        if ($piste === null) {
            return null;
        }

        $agents = [];
        foreach ($piste->getConditionsPartageAgent() as $conditionDAgent) {
            $agent = $conditionDAgent->getAgent();
            if ($agent !== null) {
                $agents[spl_object_id($agent)] = $agent;
            }
        }

        return count($agents) === 1 ? reset($agents) : null;
    }
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ConditionPartage::class,
            'csrf_protection' => false,
            'allow_extra_fields' => true,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}
