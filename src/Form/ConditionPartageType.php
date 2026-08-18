<?php

namespace App\Form;

use App\Entity\ConditionPartage;
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

class ConditionPartageType extends AbstractType
{
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
            ])
            ->add('formule', ChoiceType::class, [
                'label' => "Formule",
                'expanded' => true,
                'choices'  => [
                   "Lorsque l'unité de mésure est au moins égale au seuil" => ConditionPartage::FORMULE_ASSIETTE_AU_MOINS_EGALE_AU_SEUIL,
                   "Lorsque l'unité de mésure est inférieure au seuil" => ConditionPartage::FORMULE_ASSIETTE_INFERIEURE_AU_SEUIL,
                   "Ne pas appliquer le seuil" => ConditionPartage::FORMULE_NE_SAPPLIQUE_PAS_SEUIL,
                ],
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
            $builder->add('agent', InviteAutocompleteField::class, [
                'label' => "Agent bénéficiaire",
                'help' => "L'agent du cabinet à qui cette part est rétrocédée. Laisser vide pour un partenaire externe.",
                'required' => false,
                'attr' => [
                    'placeholder' => "Agent bénéficiaire",
                ],
            ]);
        }
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
