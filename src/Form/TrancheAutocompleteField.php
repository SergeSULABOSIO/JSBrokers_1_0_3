<?php

namespace App\Form;

use App\Entity\Tranche;
use App\Entity\RevenuPourCourtier;
use App\Services\FormListenerFactory;
use App\Services\CanvasBuilder;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\UX\Autocomplete\Form\AsEntityAutocompleteField;
use Symfony\UX\Autocomplete\Form\BaseEntityAutocompleteType;
use Symfony\Component\HttpFoundation\RequestStack;

#[AsEntityAutocompleteField]
class TrancheAutocompleteField extends AbstractType
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
            'class' => Tranche::class,
            'placeholder' => 'Sélectionner une tranche à facturer',
            'revenu_id' => null, // Injecté depuis ArticleType pour le mode édition
            'searchable_fields' => ['nom'],
            'as_html' => true,
            'choice_label' => function(Tranche $tranche) {
                
                $cotation = $tranche->getCotation();
                $avenant = ($cotation && !$cotation->getAvenants()->isEmpty()) ? $cotation->getAvenants()->first() : null;
                $piste = $cotation ? $cotation->getPiste() : null;
                
                $policeRef = ($avenant && $avenant->getReferencePolice()) ? $avenant->getReferencePolice() : 'N/A';
                $assureurNom = ($cotation && $cotation->getAssureur()) ? $cotation->getAssureur()->getNom() : 'N/A';
                $clientNom = ($piste && $piste->getClient()) ? $piste->getClient()->getNom() : 'N/A';

                // MAGIE DU CANVAS BUILDER : Hydratation dynamique de l'entité Tranche
                $this->canvasBuilder->loadAllCalculatedValues($tranche);

                // Extraction des indicateurs financiers hydratés
                $primeTTC = $tranche->primeTranche ?? 0.0;
                $primePayee = $tranche->primePayee ?? 0.0;
                $primeSolde = $tranche->primeSoldeDue ?? 0.0;

                $comTTC = $tranche->montant_du ?? 0.0;
                $comEncaissée = $tranche->montant_paye ?? 0.0;
                $comSolde = $tranche->solde_restant_du ?? 0.0;

                $retroDue = $tranche->retroCommission ?? 0.0;
                $retroPayee = $tranche->retroCommissionReversee ?? 0.0;
                $retroSolde = $tranche->retroCommissionSolde ?? 0.0;

                $taxeCourtier = $tranche->taxeCourtierMontant ?? 0.0;
                $taxeCPayee = $tranche->taxeCourtierPayee ?? 0.0;
                $taxeCSolde = $tranche->taxeCourtierSolde ?? 0.0;

                $taxeAssureur = $tranche->taxeAssureurMontant ?? 0.0;
                $taxeAPayee = $tranche->taxeAssureurPayee ?? 0.0;
                $taxeASolde = $tranche->taxeAssureurSolde ?? 0.0;

                $tauxAffiche = $tranche->tauxTranche ?? 0.0; // La valeur est déjà en pourcentage via la stratégie

                // Classes CSS pour les soldes (Rouge si != 0, Vert si == 0)
                $clsPS = abs($primeSolde) < 0.01 ? 'text-success' : 'text-danger';
                $clsCS = abs($comSolde) < 0.01 ? 'text-success' : 'text-danger';
                $clsRS = abs($retroSolde) < 0.01 ? 'text-success' : 'text-danger';
                $clsTCS = abs($taxeCSolde) < 0.01 ? 'text-success' : 'text-danger';
                $clsTAS = abs($taxeASolde) < 0.01 ? 'text-success' : 'text-danger';

                return sprintf(
                    '<div class="jsb-autocomplete-item" data-taux="%f">
                        <!-- BLOC 1 : TITRE -->
                        <div class="jsb-autocomplete-title">%s <span class="jsb-autocomplete-title-suffix">(Taux: %s)</span></div>
                        <!-- BLOC 2 : CONTEXTE -->
                        <div class="jsb-autocomplete-context">
                            <span>Police: <strong>%s</strong></span>
                            <span class="jsb-context-separator">|</span>
                            <span>Assureur: <strong>%s</strong></span>
                            <span class="jsb-context-separator">|</span>
                            <span>Client: <strong>%s</strong></span>
                        </div>
                        <!-- BLOC 3 : INDICATEURS -->
                        <div class="jsb-autocomplete-indicators">
                            <!-- Colonne 1 : Prime -->
                            <div>
                                <div><span class="jsb-indicator-label">Prime TTC</span><span class="jsb-indicator-value">%s</span></div>
                                <div><span class="jsb-indicator-label">Prime Payée</span><span class="jsb-indicator-value">%s</span></div>
                                <div><span class="jsb-indicator-label">Solde</span><span class="jsb-indicator-value %s">%s</span></div>
                            </div>
                            <!-- Colonne 2 : Commission -->
                            <div>
                                <div><span class="jsb-indicator-label">Com. TTC</span><span class="jsb-indicator-value">%s</span></div>
                                <div><span class="jsb-indicator-label">Com. Encaissée</span><span class="jsb-indicator-value">%s</span></div>
                                <div><span class="jsb-indicator-label">Solde</span><span class="jsb-indicator-value %s">%s</span></div>
                            </div>
                            <!-- Colonne 3 : Rétrocommission -->
                            <div>
                                <div><span class="jsb-indicator-label">Rétro Dûe</span><span class="jsb-indicator-value">%s</span></div>
                                <div><span class="jsb-indicator-label">Rétro Payée</span><span class="jsb-indicator-value">%s</span></div>
                                <div><span class="jsb-indicator-label">Solde</span><span class="jsb-indicator-value %s">%s</span></div>
                            </div>
                            <!-- Colonne 4 : Taxe Courtier -->
                            <div>
                                <div><span class="jsb-indicator-label">Taxe Courtier</span><span class="jsb-indicator-value">%s</span></div>
                                <div><span class="jsb-indicator-label">Taxe Payée</span><span class="jsb-indicator-value">%s</span></div>
                                <div><span class="jsb-indicator-label">Solde</span><span class="jsb-indicator-value %s">%s</span></div>
                            </div>
                            <!-- Colonne 5 : Taxe Assureur -->
                            <div>
                                <div><span class="jsb-indicator-label">Taxe Assureur</span><span class="jsb-indicator-value">%s</span></div>
                                <div><span class="jsb-indicator-label">Taxe Payée</span><span class="jsb-indicator-value">%s</span></div>
                                <div><span class="jsb-indicator-label">Solde</span><span class="jsb-indicator-value %s">%s</span></div>
                            </div>
                        </div>
                    </div>',
                    $tranche->tauxTranche ?? 0.0,
                    htmlspecialchars($tranche->getNom() ?? 'Tranche sans nom'),
                    number_format((float)$tauxAffiche, 2, ',', ' ') . '%',
                    htmlspecialchars($policeRef),
                    htmlspecialchars($assureurNom),
                    htmlspecialchars($clientNom),
                    number_format((float)$primeTTC, 2, ',', ' '),
                    number_format((float)$primePayee, 2, ',', ' '),
                    $clsPS, number_format((float)$primeSolde, 2, ',', ' '),
                    number_format((float)$comTTC, 2, ',', ' '),
                    number_format((float)$comEncaissée, 2, ',', ' '),
                    $clsCS, number_format((float)$comSolde, 2, ',', ' '),
                    number_format((float)$retroDue, 2, ',', ' '),
                    number_format((float)$retroPayee, 2, ',', ' '),
                    $clsRS, number_format((float)$retroSolde, 2, ',', ' '),
                    number_format((float)$taxeCourtier, 2, ',', ' '),
                    number_format((float)$taxeCPayee, 2, ',', ' '),
                    $clsTCS, number_format((float)$taxeCSolde, 2, ',', ' '),
                    number_format((float)$taxeAssureur, 2, ',', ' '),
                    number_format((float)$taxeAPayee, 2, ',', ' '),
                    $clsTAS, number_format((float)$taxeASolde, 2, ',', ' ')
                );
            },










            'query_builder' => function (Options $options) {
                return function (EntityRepository $er) use ($options): QueryBuilder {
                    $request = $this->requestStack->getCurrentRequest();
                    
                    $liveRevenuId = $request ? $request->query->get('live_revenu_id') : null;
                    $formRevenuId = $options['revenu_id'];

                    // Initialisation propre sans la jointure Piste/Entreprise
                    $qb = $er->createQueryBuilder('t');

                    // --- 1. FILTRE LIVE (Depuis Stimulus Javascript) ---
                    if ($liveRevenuId) {
                        $revenu = $this->em->getRepository(RevenuPourCourtier::class)->find($liveRevenuId);
                        
                        if ($revenu && $revenu->getCotation()) {
                            $qb->andWhere('t.cotation = :cotationId')
                               ->setParameter('cotationId', $revenu->getCotation()->getId());
                        } else {
                            $qb->andWhere('1 = 0'); // Sécurité: pas de cotation trouvée = liste vide
                        }
                    } 
                    // --- 2. FILTRE INITIAL (Au chargement de la page d'édition) ---
                    elseif ($formRevenuId) {
                        $revenu = $this->em->getRepository(RevenuPourCourtier::class)->find($formRevenuId);
                        if ($revenu && $revenu->getCotation()) {
                            $qb->andWhere('t.cotation = :cotationId')
                               ->setParameter('cotationId', $revenu->getCotation()->getId());
                        } else {
                            $qb->andWhere('1 = 0');
                        }
                    } 
                    // --- 3. AUCUN REVENU CHOISI ---
                    else {
                        $qb->andWhere('1 = 0'); // Si aucun revenu n'est sélectionné, la liste est vide par défaut
                    }

                    $qb->orderBy('t.id', 'ASC');
                    return $qb;
                };
            }
        ]);
    }


    public function getParent(): string
    {
        return BaseEntityAutocompleteType::class;
    }
}