<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Article;
use App\Entity\Entreprise;
use App\Services\FormListenerFactory;
use App\Services\ServiceMonnaies;
use App\Services\CanvasBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Formulaire Article refactorisé pour Symfony 7.1.5.
 * - Gestion de l'affichage en cascade (Revenu -> Tranche -> Quantité/Montant).
 * - Suppression des champs 'nom' et 'taxeFacturee'.
 * - Calcul automatique complet : Revenu TTC * Quantité * Taux.
 * - Libellés optimisés pour une meilleure expérience utilisateur (UX).
 */
class ArticleType extends AbstractType
{
    public function __construct(
        private readonly FormListenerFactory $ecouteurFormulaire,
        private readonly ServiceMonnaies $serviceMonnaies,
        private readonly RequestStack $requestStack,
        private readonly CanvasBuilder $canvasBuilder,
        private readonly EntityManagerInterface $em
    ) {}

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Article::class,
            'csrf_protection' => false,
            'allow_extra_fields' => true,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return '';
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $request = $this->requestStack->getCurrentRequest();
        /** @var Article|null $article */
        $article = $builder->getData();

        // En mode édition, on déclenche l'hydratation profonde et le debug financier
        if ($article && $article->getId()) {
            $this->hydrateArticleCascade($article);
            $this->dumpFinancialIndicators($article);
        }

        $noteId = null;
        $revenuIdInitial = null;
        $trancheIdInitial = null;
        
        // On détermine si nous sommes en mode création
        $isCreationMode = !$article || !$article->getId();

        if ($article) {
            $noteId = $article->getNote()?->getId();
            $revenuIdInitial = $article->getRevenuFacture()?->getId();
            $trancheIdInitial = $article->getTranche()?->getId();
        } elseif ($request?->query->has('parent_id')) {
            $noteId = (int)$request->query->get('parent_id');
        }

        // 'mb-3' est la classe de base pour respecter le design défini dans app.css
        $baseRowClass = 'mb-3';

        // 1. REVENU : Toujours visible au chargement.
        $builder
            ->add('revenuFacture', RevenuPourCourtierAutocompleteField::class, [
                'label' => "Revenu / Commission source à facturer",
                'required' => false,
                'note_id' => $noteId,
                'row_attr' => ['class' => $baseRowClass],
                'attr' => [
                    'data-controller' => 'revenu-autocomplete-filter',
                    'data-revenu-autocomplete-filter-note-id-value' => $noteId
                ]
            ]); // Ajout d'un écouteur PRE_SUBMIT pour ajuster dynamiquement les champs 'tranche' et 'quantite'
        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event) use ($baseRowClass, $isCreationMode) {
            $data = $event->getData();
            $form = $event->getForm();

            $revenuFactureId = $data['revenuFacture'] ?? null;

            // Re-ajout du champ 'tranche' avec l'ID du revenu soumis
            $form->add('tranche', TrancheAutocompleteField::class, [
                'label' => "Tranche de prime correspondante",
                'required' => false,
                'revenu_id' => $revenuFactureId, // Utilise l'ID du revenu soumis pour le query_builder
                'row_attr' => ['class' => sprintf('%s tranche-form-row %s', $baseRowClass, ($isCreationMode && !$revenuFactureId ? 'd-none' : ''))],
                'attr' => ['data-controller' => 'tranche-autocomplete-filter']
            ]);

            // Re-ajout du champ 'quantite'. Sa visibilité est gérée par le CanvasProvider, mais il doit faire partie du formulaire.
            $form->add('quantite', NumberType::class, [
                'label' => "Quantité (nombre d'unités)",
                'html5' => true,
                'attr' => ['placeholder' => "1.00", 'step' => '0.01'],
                'row_attr' => ['class' => sprintf('%s quantite-form-row', $baseRowClass)]
            ]);
        });

        // Configuration des options pour le champ quantité
        $quantiteOptions = [
            'label' => "Quantité (nombre d'unités)",
            'html5' => true,
            'attr' => [
                'placeholder' => "1.00",
                'step' => '0.01'
            ],
            'row_attr' => [
                'class' => sprintf('%s quantite-form-row', $baseRowClass) // La visibilité est gérée par le CanvasProvider
            ],
        ];

        // En mode création, on force la valeur à 1.0 par défaut pour l'UX.
        // En mode édition, on omet 'data' pour que le formulaire utilise la valeur de l'entité (BDD).
        if ($isCreationMode) {
            $quantiteOptions['data'] = 1.0;
        }

        // Ajout initial des champs 'tranche' et 'quantite' pour le premier rendu du formulaire (avant toute soumission).
        // Ces champs seront remplacés par l'écouteur PRE_SUBMIT si le formulaire est soumis.
        $builder
            ->add('tranche', TrancheAutocompleteField::class, [
                'label' => "Tranche de prime correspondante",
                'required' => false,
                'revenu_id' => $revenuIdInitial, // ID du revenu initial
                'row_attr' => [
                    'class' => sprintf('%s tranche-form-row %s', $baseRowClass, ($isCreationMode && !$revenuIdInitial ? 'd-none' : ''))
                ],
                // Le contrôleur est monté ici. Il écoutera la tranche, mais aussi les champs frères.
                'attr' => [
                    'data-controller' => 'tranche-autocomplete-filter'
                ]
            ])
            
            ->add('quantite', NumberType::class, $quantiteOptions);
        // Le champ idPoste n'est plus nécessaire et a été supprimé de l'entité Article.
        // Il n'est donc plus ajouté au formulaire.
    }

    /**
     * Effectue une hydratation récursive de bas en haut (Bottom-Up) pour l'Article.
     * Garantit que toutes les dépendances financières sont prêtes avant le rendu du formulaire.
     */
    private function hydrateArticleCascade(Article $article): void
    {
        // NIVEAU 4 : Le Socle (Entreprise et Taxes)
        $entrepriseId = $this->ecouteurFormulaire->getCurrentEntrepriseId();
        $entreprise = $this->em->getRepository(Entreprise::class)->find($entrepriseId);
        if ($entreprise) {
            $this->canvasBuilder->loadAllCalculatedValues($entreprise);
            foreach ($entreprise->getTaxes() as $taxe) {
                $this->canvasBuilder->loadAllCalculatedValues($taxe);
            }
        }

        // NIVEAU 3 : Les Satellites de la Cotation
        $revenu = $article->getRevenuFacture();
        $tranche = $article->getTranche();
        $cotation = $revenu?->getCotation() ?? $tranche?->getCotation();

        if ($cotation) {
            $this->em->initializeObject($cotation);
            $cotation->getNom();

            // Acteurs
            if ($assureur = $cotation->getAssureur()) {
                $this->em->initializeObject($assureur);
                $this->canvasBuilder->loadAllCalculatedValues($assureur);
            }
            if ($piste = $cotation->getPiste()) {
                $this->em->initializeObject($piste);
                if ($client = $piste->getClient()) {
                    $this->em->initializeObject($client);
                    $this->canvasBuilder->loadAllCalculatedValues($client);
                }
                $piste->getPartenaires()->count();
                foreach ($piste->getPartenaires() as $partenaire) {
                    $this->em->initializeObject($partenaire);
                    $this->canvasBuilder->loadAllCalculatedValues($partenaire);
                }
            }

            // Eléments de Prime
            $cotation->getAvenants()->count();
            foreach ($cotation->getAvenants() as $avenant) {
                $this->em->initializeObject($avenant);
                $this->canvasBuilder->loadAllCalculatedValues($avenant);
            }
            $cotation->getChargements()->count();
            foreach ($cotation->getChargements() as $cp) {
                $this->em->initializeObject($cp);
                if ($cp->getType()) $this->em->initializeObject($cp->getType());
                $this->canvasBuilder->loadAllCalculatedValues($cp);
            }

            // NIVEAU 2 : Le Moteur Financier (Cotation)
            $this->canvasBuilder->loadAllCalculatedValues($cotation);
        }

        // NIVEAU 1 : Flux de Facturation
        if ($revenu) {
            $this->em->initializeObject($revenu);
            $this->canvasBuilder->loadAllCalculatedValues($revenu);
        }
        if ($tranche) {
            $this->em->initializeObject($tranche);
            $this->canvasBuilder->loadAllCalculatedValues($tranche);
        }

        // NIVEAU 0 : L'Ancêtre
        $this->canvasBuilder->loadAllCalculatedValues($article);
    }

    /**
     * Affiche les indicateurs financiers détaillés des objets liés pour le débogage en mode édition.
     */
    private function dumpFinancialIndicators(Article $article): void
    {
        dump('--- DEBUT DEBUG INDICATEURS FINANCIERS ARTICLE (Mode Édition) ---');
        
        if ($revenu = $article->getRevenuFacture()) {
            dump('REVENU (#'.$revenu->getId().')', [
                'Montant TTC' => $revenu->montantCalculeTTC,
                'Montant Payé' => $revenu->montant_paye,
                'Solde' => $revenu->solde_restant_du,
                'Taxe Courtier' => $revenu->taxeCourtierMontant,
                'Taxe Assureur' => $revenu->taxeAssureurMontant,
                'Commission pure' => $revenu->montantPur,
                'Réserve' => $revenu->reserve,
                'Rétrocommission' => $revenu->retroCommission,
            ]);
        }

        if ($tranche = $article->getTranche()) {
            dump('TRANCHE (#'.$tranche->getId().')', [
                'Taux calculé (%)' => $tranche->tauxTranche,
                'Prime' => $tranche->primeTranche,
                'Prime payée' => $tranche->primePayee,
                'Solde' => $tranche->primeSoldeDue,
                'Taxe Courtier' => $tranche->taxeCourtierMontant,
                'Taxe Assureur' => $tranche->taxeAssureurMontant,
                'Commission pure' => $tranche->montantPur,
                'Réserve' => $tranche->reserve,
                'Rétrocommission' => $tranche->retroCommission,
            ]);
        }

        if ($cot = $article->getRevenuFacture()?->getCotation() ?? $article->getTranche()?->getCotation()) {
            dump('MOTEUR SOURCE (Cotation TTC)', $cot->montantTTC);
        }

        dump('--- FIN DEBUG INDICATEURS ---');
    }
}