<?php

namespace App\Form;

use App\Entity\Note;
use App\Entity\RevenuPourCourtier;
use App\Services\FormListenerFactory;
use App\Services\CanvasBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\UX\Autocomplete\Form\AsEntityAutocompleteField;
use Symfony\UX\Autocomplete\Form\BaseEntityAutocompleteType;
use Symfony\Component\HttpFoundation\RequestStack;

#[AsEntityAutocompleteField]
class RevenuPourCourtierAutocompleteField extends AbstractType
{
    public function __construct(
        private FormListenerFactory $ecouteurFormulaire,
        private RequestStack $requestStack,
        private EntityManagerInterface $em,
        private CanvasBuilder $canvasBuilder
    ) {}
    
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'class' => RevenuPourCourtier::class,
            'placeholder' => 'Rechercher un revenu',
            'note_id' => null, 
            'searchable_fields' => ['nom'],
            'as_html' => true,
            'choice_label' => fn(RevenuPourCourtier $revenu) => $this->renderChoiceLabel($revenu),
            'parent_article' => null, // NOUVEAU : On définit l'option personnalisée.
            'parent_note' => null, // NOUVEAU : Pour recevoir l'entité Note parente.
        ]);

        // Le query_builder est maintenant le seul responsable du filtrage.
        $resolver->setNormalizer('query_builder', fn(Options $options) => $this->getEligibleRevenusQueryBuilder($options));
    }

    public function getParent(): string
    {
        return BaseEntityAutocompleteType::class;
    }

    /**
     * Construit le QueryBuilder pour ne récupérer que les revenus éligibles.
     *
     * @param Options $options
     * @return \Doctrine\ORM\QueryBuilder
     */
    private function getEligibleRevenusQueryBuilder(Options $options): \Doctrine\ORM\QueryBuilder
    {
        // 1. On récupère la liste des IDs des revenus éligibles (non soldés) pour la recherche.
        $eligibleRevenus = $this->fetchAndFilterEligibleRevenus($options);
        $eligibleIds = array_map(fn(RevenuPourCourtier $r) => $r->getId(), $eligibleRevenus);

        // 2. En mode édition, on s'assure que l'ID du revenu déjà associé à l'article est inclus, même si son solde est nul.
        // On utilise notre nouvelle option 'parent_article'.
        $parentArticle = $options['parent_article'] ?? null;
        if ($parentArticle instanceof \App\Entity\Article && $parentArticle->getRevenuFacture()) {
            $currentRevenuId = $parentArticle->getRevenuFacture()->getId();
            if (!in_array($currentRevenuId, $eligibleIds)) {
                $eligibleIds[] = $currentRevenuId;
            }
        }

        // Si aucun revenu n'est éligible, on s'assure que la requête ne retourne rien.
        if (empty($eligibleIds)) {
            $eligibleIds = [0]; // Utilise un ID qui ne correspondra à rien.
        }

        // 3. On retourne un QueryBuilder final qui filtre sur ces IDs.
        $er = $this->em->getRepository($options['class']);
        return $er->createQueryBuilder('r')
            ->where('r.id IN (:ids)')
            ->setParameter('ids', $eligibleIds);
    }

    private function fetchAndFilterEligibleRevenus(Options $options): array
    {
        $er = $this->em->getRepository($options['class']);
        $entrepriseId = $this->ecouteurFormulaire->getCurrentEntrepriseId();
        $request = $this->requestStack->getCurrentRequest();
        
        $liveAssureurId = $request?->query->get('live_assureur_id');
        $liveClientId = $request?->query->get('live_client_id');
        $livePartenaireId = $request?->query->get('live_partenaire_id');
        
        $qb = $er->createQueryBuilder('r')
            ->addSelect('tr', 'c', 'a', 'assureur', 'piste', 'client')
            ->join('r.typeRevenu', 'tr')
            ->join('r.cotation', 'c')
            ->leftJoin('c.avenants', 'a') 
            ->leftJoin('c.assureur', 'assureur')
            ->leftJoin('c.piste', 'piste')
            ->leftJoin('piste.client', 'client')
            ->where('tr.entreprise = :eseId')
            ->setParameter('eseId', $entrepriseId);

        // Applique les filtres de base (destinataire)
        if ($liveAssureurId) { 
            $qb->andWhere('assureur.id = :assureurId')->setParameter('assureurId', $liveAssureurId); 
        } elseif ($liveClientId) { 
            $qb->andWhere('client.id = :clientId')->setParameter('clientId', $liveClientId); 
        } elseif ($livePartenaireId) {
            // Le filtrage pour partenaire se fait en PHP car il dépend de la rétrocommission calculée
        }

        $potentielsRevenus = $qb->getQuery()->getResult();

        // Filtre final en PHP après hydratation par le CanvasBuilder
        return array_filter($potentielsRevenus, function(RevenuPourCourtier $revenu) {
            $this->canvasBuilder->loadAllCalculatedValues($revenu);
            $request = $this->requestStack->getCurrentRequest();

            // Scénario 1: On facture une rétro-commission à un partenaire
            if ($request?->query->has('live_partenaire_id')) {
                return ($revenu->retroCommissionSolde ?? 0.0) > 0.01;
            }

            // Scénario 2: On facture une taxe à une autorité fiscale
            if ($liveAutoriteId = $request?->query->get('live_autorite_id')) {
                $autorite = $this->em->getRepository(\App\Entity\AutoriteFiscale::class)->find($liveAutoriteId);
                if ($autorite && $taxe = $autorite->getTaxe()) {
                    if ($taxe->getRedevable() === \App\Entity\Taxe::REDEVABLE_COURTIER) {
                        return ($revenu->taxeCourtierSolde ?? 0.0) > 0.01;
                    } elseif ($taxe->getRedevable() === \App\Entity\Taxe::REDEVABLE_ASSUREUR) {
                        return ($revenu->taxeAssureurSolde ?? 0.0) > 0.01;
                    }
                }
            }

            // Scénario 3 (par défaut): On facture une commission à un client ou un assureur.
            // On ne montre que les revenus dont le solde de commission est positif.
            // Les taxes seront facturées dans une note séparée destinée à l'autorité fiscale.
            if ($request?->query->has('live_assureur_id') || $request?->query->has('live_client_id')) {
                return ($revenu->solde_restant_du ?? 0.0) > 0.01;
            }
            return false; // Par défaut, on ne montre rien si le contexte n'est pas clair.
        });
    }

    /**
     * Génère le label HTML pour une option d'autocomplétion.
     *
     * @param RevenuPourCourtier $revenu
     * @return string
     */
    private function renderChoiceLabel(RevenuPourCourtier $revenu): string
    {
        $cotation = $revenu->getCotation();
        $avenant = ($cotation && !$cotation->getAvenants()->isEmpty()) ? $cotation->getAvenants()->first() : null;
        $piste = $cotation ? $cotation->getPiste() : null;
        
        $policeRef = ($avenant && $avenant->getReferencePolice()) ? $avenant->getReferencePolice() : 'N/A';
        $assureurNom = ($cotation && $cotation->getAssureur()) ? $cotation->getAssureur()->getNom() : 'N/A';
        $clientNom = ($piste && $piste->getClient()) ? $piste->getClient()->getNom() : 'N/A';
        
        // L'hydratation est déjà faite dans getEligibleRevenus, mais on s'assure qu'elle est là.
        $this->canvasBuilder->loadAllCalculatedValues($revenu);
        
        $comTTC = $revenu->montantCalculeTTC ?? 0.0;
        $comPayee = $revenu->montant_paye ?? 0.0;
        $comSolde = $revenu->solde_restant_du ?? 0.0;
        $comHT = $revenu->montantCalculeHT ?? 0.0;
        $comPure = $revenu->montantPur ?? 0.0;
        $reserve = $revenu->reserve ?? 0.0;
        $retroDue = $revenu->retroCommission ?? 0.0;
        $retroPayee = $revenu->retroCommissionReversee ?? 0.0;
        $retroSolde = $revenu->retroCommissionSolde ?? 0.0;
        $taxeCMontant = $revenu->taxeCourtierMontant ?? 0.0;
        $taxeCPayee = $revenu->taxeCourtierPayee ?? 0.0;
        $taxeCSolde = $revenu->taxeCourtierSolde ?? 0.0;
        $taxeAMontant = $revenu->taxeAssureurMontant ?? 0.0;
        $taxeAPayee = $revenu->taxeAssureurPayee ?? 0.0;
        $taxeASolde = $revenu->taxeAssureurSolde ?? 0.0;

        $partenaireNom = $revenu->partenaireNom ?? $revenu->partenaire_nom ?? 'N/A';
        $nombreTranches = $cotation ? $cotation->getTranches()->count() : 0;

        $clsCS = abs($comSolde) < 0.01 ? 'text-success' : 'text-danger';
        $clsRS = abs($retroSolde) < 0.01 ? 'text-success' : 'text-danger';
        $clsTCS = abs($taxeCSolde) < 0.01 ? 'text-success' : 'text-danger';
        $clsTAS = abs($taxeASolde) < 0.01 ? 'text-success' : 'text-danger';

        // NOUVEAU : Logique pour déterminer les colonnes à surligner
        $request = $this->requestStack->getCurrentRequest();
        $highlightClass = 'jsb-indicator-highlight';
        $col1_class = '';
        $col2_class = '';
        $col3_class = '';
        $col4_class = '';
        $col5_class = '';

        // Contexte live (AJAX)
        $liveAssureurId = $request?->query->get('live_assureur_id');
        $liveClientId = $request?->query->get('live_client_id');
        $livePartenaireId = $request?->query->get('live_partenaire_id');
        $liveAutoriteId = $request?->query->get('live_autorite_id');

        // Contexte statique (chargement initial en mode édition)
        /** @var \App\Entity\Note|null $parentNote */
        $parentNote = $this->getParentNoteFromOptions();
        $noteAddressedTo = $parentNote?->getAddressedTo();

        // Logique de surlignage unifiée
        if ($liveAssureurId || $liveClientId || ($parentNote && in_array($noteAddressedTo, [Note::TO_ASSUREUR, Note::TO_CLIENT]))) {
            // Facturation de commission (due par assureur ou client)
            $col1_class = $highlightClass;
            $col2_class = $highlightClass;
        } elseif ($livePartenaireId || ($parentNote && $noteAddressedTo === Note::TO_PARTENAIRE)) {
            // Facturation de rétro-commission
            $col3_class = $highlightClass;
        } elseif ($liveAutoriteId || ($parentNote && $noteAddressedTo === Note::TO_AUTORITE_FISCALE)) {
            $autoriteId = $liveAutoriteId ?? $parentNote?->getAutoritefiscale()?->getId();
            if ($autoriteId) {
                $autorite = $this->em->getRepository(\App\Entity\AutoriteFiscale::class)->find($autoriteId);
                if ($autorite && $taxe = $autorite->getTaxe()) {
                    if ($taxe->getRedevable() === \App\Entity\Taxe::REDEVABLE_COURTIER) {
                        $col4_class = $highlightClass;
                    } elseif ($taxe->getRedevable() === \App\Entity\Taxe::REDEVABLE_ASSUREUR) {
                        $col5_class = $highlightClass;
                    }
                }
            }
        }

        return sprintf(
            '<div class="jsb-autocomplete-item" data-id="%d">
                <div class="jsb-autocomplete-title">%s</div>
                <div class="jsb-autocomplete-context">
                    <span>Police: <strong>%s</strong></span>
                    <span class="jsb-context-separator">|</span>
                    <span>Assureur: <strong>%s</strong></span>
                    <span class="jsb-context-separator">|</span>
                    <span>Client: <strong>%s</strong></span>
                    <span class="jsb-context-separator">|</span>
                    <span class="badge bg-secondary">%d tranches</span>
                </div>
                <div class="jsb-autocomplete-indicators">
                    <div class="%s">
                        <div><span class="jsb-indicator-label">Com. TTC</span><span class="jsb-indicator-value">%s</span></div>
                        <div><span class="jsb-indicator-label">Encaissée</span><span class="jsb-indicator-value">%s</span></div>
                        <div><span class="jsb-indicator-label">Solde</span><span class="jsb-indicator-value %s">%s</span></div>
                    </div>
                    <div class="%s">
                        <div><span class="jsb-indicator-label">Com. HT</span><span class="jsb-indicator-value">%s</span></div>
                        <div><span class="jsb-indicator-label">Com. Pure</span><span class="jsb-indicator-value text-cobalt">%s</span></div>
                        <div><span class="jsb-indicator-label">Réserve</span><span class="jsb-indicator-value">%s</span></div>
                    </div>
                    <div class="%s">
                        <div><span class="jsb-indicator-label">Rétro (%s)</span><span class="jsb-indicator-value">%s</span></div>
                        <div><span class="jsb-indicator-label">Rétro Payée</span><span class="jsb-indicator-value">%s</span></div>
                        <div><span class="jsb-indicator-label">Solde</span><span class="jsb-indicator-value %s">%s</span></div>
                    </div>
                    <div class="%s">
                        <div><span class="jsb-indicator-label">T. Courtier</span><span class="jsb-indicator-value">%s</span></div>
                        <div><span class="jsb-indicator-label">Taxe Payée</span><span class="jsb-indicator-value">%s</span></div>
                        <div><span class="jsb-indicator-label">Solde</span><span class="jsb-indicator-value %s">%s</span></div>
                    </div>
                    <div class="%s">
                        <div><span class="jsb-indicator-label">T. Assureur</span><span class="jsb-indicator-value">%s</span></div>
                        <div><span class="jsb-indicator-label">Taxe Payée</span><span class="jsb-indicator-value">%s</span></div>
                        <div><span class="jsb-indicator-label">Solde</span><span class="jsb-indicator-value %s">%s</span></div>
                    </div>
                </div>
            </div>',
            $revenu->getId(),
            htmlspecialchars($revenu->getNom() ?? 'Sans nom'),
            htmlspecialchars($policeRef),
            htmlspecialchars($assureurNom),
            htmlspecialchars($clientNom),
            $nombreTranches,
            $col1_class,
            number_format($comTTC, 2, ',', ' '),
            number_format($comPayee, 2, ',', ' '),
            $clsCS, number_format($comSolde, 2, ',', ' '),
            $col2_class,
            number_format($comHT, 2, ',', ' '),
            number_format($comPure, 2, ',', ' '),
            number_format($reserve, 2, ',', ' '),
            $col3_class,
            htmlspecialchars($partenaireNom),
            number_format($retroDue, 2, ',', ' '),
            number_format($retroPayee, 2, ',', ' '),
            $clsRS, number_format($retroSolde, 2, ',', ' '),
            $col4_class,
            number_format($taxeCMontant, 2, ',', ' '),
            number_format($taxeCPayee, 2, ',', ' '),
            $clsTCS, number_format($taxeCSolde, 2, ',', ' '),
            $col5_class,
            number_format($taxeAMontant, 2, ',', ' '),
            number_format($taxeAPayee, 2, ',', ' '),
            $clsTAS, number_format($taxeASolde, 2, ',', ' ')
        );
    }

    /**
     * Helper pour récupérer la note parente depuis les options du formulaire.
     * Nécessaire car renderChoiceLabel n'a pas accès directement aux options.
     */
    private function getParentNoteFromOptions(): ?\App\Entity\Note
    {
        // Cette méthode est une astuce. Normalement, on accède aux options dans configureOptions.
        // Ici, on suppose que le resolver a déjà été configuré.
        return $this->requestStack->getCurrentRequest()?->attributes->get('data')?->getParent()?->getData();
    }
}