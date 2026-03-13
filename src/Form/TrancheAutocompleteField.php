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
        private CanvasBuilder $canvasBuilder // Injection du CanvasBuilder
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

                // Extraction des indicateurs financiers hydratés (Adapté aux attributs de l'entité Tranche)
                $prime = $tranche->primeTranche ?? $tranche->montant_du ?? 0.0;
                $paye = $tranche->primePayee ?? $tranche->montant_paye ?? 0.0;
                $solde = $tranche->primeSoldeDue ?? $tranche->solde_restant_du ?? 0.0;
                
                $taxeCourtier = $tranche->taxeCourtierMontant ?? 0.0;
                $taxeAssureur = $tranche->taxeAssureurMontant ?? 0.0;
                
                $commissionPure = $tranche->montantPur ?? 0.0;
                $reserve = $tranche->reserve ?? 0.0;
                $retrocom = $tranche->retroCommission ?? 0.0;

                // Formatage HTML enrichi, cohérent avec RevenuPourCourtier (ligne bleue, séparateurs &bull;)
                return sprintf(
                    '<div>
                        <strong>%s</strong>
                        <div style="color: #6c757d; font-size: 0.85em; padding-left: 2px; margin-top: 2px;">
                            Réf Police: %s <span style="color: #adb5bd; margin: 0 4px;">&bull;</span> Assureur: %s <span style="color: #adb5bd; margin: 0 4px;">&bull;</span> Client: %s
                        </div>
                        <div style="color: #0047AB; font-weight: bold; font-size: 0.85em; padding-left: 2px; margin-top: 4px; border-top: 1px dashed #ccc; padding-top: 2px; display: flex; flex-wrap: wrap; align-items: center; gap: 6px;">
                            <span title="Prime TTC calculée pour cette tranche">Prime: %s</span>
                            <span style="color: #adb5bd; font-weight: normal;">&bull;</span>
                            <span title="Montant payé sur cette tranche">Payé: %s</span>
                            <span style="color: #adb5bd; font-weight: normal;">&bull;</span>
                            <span title="Solde restant dû">Solde: %s</span>
                            <span style="color: #adb5bd; font-weight: normal;">&bull;</span>
                            <span title="Taxe à la charge du courtier (Ex: ARCA)">Taxe Courtier: %s</span>
                            <span style="color: #adb5bd; font-weight: normal;">&bull;</span>
                            <span title="Taxe à la charge de l\'assureur (Ex: TVA)">Taxe Assureur: %s</span>
                            <span style="color: #adb5bd; font-weight: normal;">&bull;</span>
                            <span title="Commission pure (assiette partageable)">Com. Pure: %s</span>
                            <span style="color: #adb5bd; font-weight: normal;">&bull;</span>
                            <span title="Réserve calculée">Réserve: %s</span>
                            <span style="color: #adb5bd; font-weight: normal;">&bull;</span>
                            <span title="Rétrocommission associée à cette tranche">Rétro: %s</span>
                        </div>
                    </div>',
                    htmlspecialchars($tranche->getNom() ?? 'Tranche sans nom'),
                    htmlspecialchars($policeRef),
                    htmlspecialchars($assureurNom),
                    htmlspecialchars($clientNom),
                    number_format((float)$prime, 2, ',', ' '),
                    number_format((float)$paye, 2, ',', ' '),
                    number_format((float)$solde, 2, ',', ' '),
                    number_format((float)$taxeCourtier, 2, ',', ' '),
                    number_format((float)$taxeAssureur, 2, ',', ' '),
                    number_format((float)$commissionPure, 2, ',', ' '),
                    number_format((float)$reserve, 2, ',', ' '),
                    number_format((float)$retrocom, 2, ',', ' ')
                );
            },
            
            // La méthode officielle pour retourner le QueryBuilder
            'query_builder' => function (Options $options) {
                return function (EntityRepository $er) use ($options): QueryBuilder {
                    $entrepriseId = $this->ecouteurFormulaire->getCurrentEntrepriseId();
                    $request = $this->requestStack->getCurrentRequest();
                    
                    // Récupération de l'ID du revenu (via AJAX Stimulus ou via l'option d'édition)
                    $liveRevenuId = $request ? $request->query->get('live_revenu_id') : null;
                    $formRevenuId = $options['revenu_id'];

                    $qb = $er->createQueryBuilder('t')
                        ->join('t.cotation', 'c')
                        ->join('c.piste', 'piste')
                        ->where('piste.entreprise = :eseId')
                        ->setParameter('eseId', $entrepriseId);

                    // --- 1. FILTRE LIVE (Depuis Stimulus Javascript) ---
                    if ($liveRevenuId) {
                        $revenu = $this->em->getRepository(RevenuPourCourtier::class)->find($liveRevenuId);
                        
                        if ($revenu && $revenu->getCotation()) {
                            // On filtre strictement sur la Cotation du Revenu choisi !
                            $qb->andWhere('t.cotation = :cotationId')
                               ->setParameter('cotationId', $revenu->getCotation()->getId());
                        } else {
                            $qb->andWhere('1 = 0'); // Sécurité: pas de revenu = pas de tranche
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
                        // On n'affiche aucune tranche par défaut.
                        $qb->andWhere('1 = 0');
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