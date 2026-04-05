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
            'choices' => [], // Sera surchargé par le normalizer
        ]);

        $resolver->setNormalizer('choices', fn(Options $options) => $this->getEligibleRevenus($options));

        // Le query_builder est maintenant simplifié. Il ne fait plus de filtrage métier.
        // Il sert juste de base pour que le composant d'autocomplétion fonctionne.
        // La recherche textuelle se fera sur les 'choices' que nous avons pré-filtrés.
        $resolver->setNormalizer('query_builder', fn(Options $options, $value) => $this->em->getRepository($options['class'])->createQueryBuilder('r')->where('r.id > 0'));
    }

    public function getParent(): string
    {
        return BaseEntityAutocompleteType::class;
    }

    /**
     * Récupère et filtre les revenus éligibles.
     *
     * @param Options $options
     * @return array
     */
    private function getEligibleRevenus(Options $options): array
    {
        $er = $this->em->getRepository($options['class']);
        $entrepriseId = $this->ecouteurFormulaire->getCurrentEntrepriseId();
        $request = $this->requestStack->getCurrentRequest();
        
        $liveAssureurId = $request?->query->get('live_assureur_id');
        $liveClientId = $request?->query->get('live_client_id');
        
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
        }

        $potentielsRevenus = $qb->getQuery()->getResult();

        // Filtre final en PHP après hydratation par le CanvasBuilder
        return array_filter($potentielsRevenus, function(RevenuPourCourtier $revenu) {
            $this->canvasBuilder->loadAllCalculatedValues($revenu);
            return ($revenu->solde_restant_du ?? 0.0) > 0.01;
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
                    <div>
                        <div><span class="jsb-indicator-label">Com. TTC</span><span class="jsb-indicator-value">%s</span></div>
                        <div><span class="jsb-indicator-label">Encaissée</span><span class="jsb-indicator-value">%s</span></div>
                        <div><span class="jsb-indicator-label">Solde</span><span class="jsb-indicator-value %s">%s</span></div>
                    </div>
                    <div>
                        <div><span class="jsb-indicator-label">Com. HT</span><span class="jsb-indicator-value">%s</span></div>
                        <div><span class="jsb-indicator-label">Com. Pure</span><span class="jsb-indicator-value text-cobalt">%s</span></div>
                        <div><span class="jsb-indicator-label">Réserve</span><span class="jsb-indicator-value">%s</span></div>
                    </div>
                    <div>
                        <div><span class="jsb-indicator-label">Rétro (%s)</span><span class="jsb-indicator-value">%s</span></div>
                        <div><span class="jsb-indicator-label">Rétro Payée</span><span class="jsb-indicator-value">%s</span></div>
                        <div><span class="jsb-indicator-label">Solde</span><span class="jsb-indicator-value %s">%s</span></div>
                    </div>
                    <div>
                        <div><span class="jsb-indicator-label">T. Courtier</span><span class="jsb-indicator-value">%s</span></div>
                        <div><span class="jsb-indicator-label">Taxe Payée</span><span class="jsb-indicator-value">%s</span></div>
                        <div><span class="jsb-indicator-label">Solde</span><span class="jsb-indicator-value %s">%s</span></div>
                    </div>
                    <div>
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
            number_format($comTTC, 2, ',', ' '),
            number_format($comPayee, 2, ',', ' '),
            $clsCS, number_format($comSolde, 2, ',', ' '),
            number_format($comHT, 2, ',', ' '),
            number_format($comPure, 2, ',', ' '),
            number_format($reserve, 2, ',', ' '),
            htmlspecialchars($partenaireNom),
            number_format($retroDue, 2, ',', ' '),
            number_format($retroPayee, 2, ',', ' '),
            $clsRS, number_format($retroSolde, 2, ',', ' '),
            number_format($taxeCMontant, 2, ',', ' '),
            number_format($taxeCPayee, 2, ',', ' '),
            $clsTCS, number_format($taxeCSolde, 2, ',', ' '),
            number_format($taxeAMontant, 2, ',', ' '),
            number_format($taxeAPayee, 2, ',', ' '),
            $clsTAS, number_format($taxeASolde, 2, ',', ' ')
        );
    }
}