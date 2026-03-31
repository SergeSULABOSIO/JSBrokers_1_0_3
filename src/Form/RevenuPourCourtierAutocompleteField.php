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
            'choice_label' => function(RevenuPourCourtier $revenu) {
                
                $cotation = $revenu->getCotation();
                $avenant = ($cotation && !$cotation->getAvenants()->isEmpty()) ? $cotation->getAvenants()->first() : null;
                $piste = $cotation ? $cotation->getPiste() : null;
                
                $policeRef = ($avenant && $avenant->getReferencePolice()) ? $avenant->getReferencePolice() : 'N/A';
                $assureurNom = ($cotation && $cotation->getAssureur()) ? $cotation->getAssureur()->getNom() : 'N/A';
                $clientNom = ($piste && $piste->getClient()) ? $piste->getClient()->getNom() : 'N/A';
                
                // MAGIE DU CANVAS BUILDER : On hydrate dynamiquement l'entité avec la stratégie
                $this->canvasBuilder->loadAllCalculatedValues($revenu);
                
                // 1. Extraction des indicateurs financiers (Basé sur RevenuPourCourtier.php)
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

                // Tente de récupérer le nom du partenaire via les clés hydratées, sinon via les entités liées
                $partenaireNom = $revenu->partenaireNom ?? $revenu->partenaire_nom ?? null;
                if (!$partenaireNom && $piste && method_exists($piste, 'getPartenaires') && !$piste->getPartenaires()->isEmpty()) {
                    $partenaireNom = $piste->getPartenaires()->first()->getNom();
                }
                $partenaireNom = $partenaireNom ?? 'N/A';

                // 2. Récupération du nombre de tranches depuis la cotation liée
                $nombreTranches = 0;
                if ($cotation && method_exists($cotation, 'getTranches')) {
                    $nombreTranches = $cotation->getTranches()->count();
                }

                // Classes CSS pour les soldes (Rouge si != 0, Vert si == 0)
                // Note: On utilise un delta de 0.01 pour les comparaisons de flottants
                $clsCS = abs($comSolde) < 0.01 ? 'text-success' : 'text-danger';
                $clsRS = abs($retroSolde) < 0.01 ? 'text-success' : 'text-danger';
                $clsTCS = abs($taxeCSolde) < 0.01 ? 'text-success' : 'text-danger';
                $clsTAS = abs($taxeASolde) < 0.01 ? 'text-success' : 'text-danger';

                // 3. Formatage HTML enrichi
                return sprintf(
                    '<div class="jsb-autocomplete-item" data-id="%d">
                        <!-- BLOC 1 : TITRE -->
                        <div class="jsb-autocomplete-title">%s</div>
                        <!-- BLOC 2 : CONTEXTE -->
                        <div class="jsb-autocomplete-context">
                            <span>Police: <strong>%s</strong></span>
                            <span class="jsb-context-separator">|</span>
                            <span>Assureur: <strong>%s</strong></span>
                            <span class="jsb-context-separator">|</span>
                            <span>Client: <strong>%s</strong></span>
                            <span class="jsb-context-separator">|</span>
                            <span class="badge bg-secondary">%d tranches</span>
                        </div>
                        <!-- BLOC 3 : INDICATEURS -->
                        <div class="jsb-autocomplete-indicators">
                            <!-- Colonne 1 : Commission TTC -->
                            <div>
                                <div><span class="jsb-indicator-label">Com. TTC</span><span class="jsb-indicator-value">%s</span></div>
                                <div><span class="jsb-indicator-label">Encaissée</span><span class="jsb-indicator-value">%s</span></div>
                                <div><span class="jsb-indicator-label">Solde</span><span class="jsb-indicator-value %s">%s</span></div>
                            </div>
                            <!-- Colonne 2 : Détails HT -->
                            <div>
                                <div><span class="jsb-indicator-label">Com. HT</span><span class="jsb-indicator-value">%s</span></div>
                                <div><span class="jsb-indicator-label">Com. Pure</span><span class="jsb-indicator-value text-cobalt">%s</span></div>
                                <div><span class="jsb-indicator-label">Réserve</span><span class="jsb-indicator-value">%s</span></div>
                            </div>
                            <!-- Colonne 3 : Rétrocommission -->
                            <div>
                                <div><span class="jsb-indicator-label">Rétro (%s)</span><span class="jsb-indicator-value">%s</span></div>
                                <div><span class="jsb-indicator-label">Rétro Payée</span><span class="jsb-indicator-value">%s</span></div>
                                <div><span class="jsb-indicator-label">Solde</span><span class="jsb-indicator-value %s">%s</span></div>
                            </div>
                            <!-- Colonne 4 : Taxe Courtier -->
                            <div>
                                <div><span class="jsb-indicator-label">T. Courtier</span><span class="jsb-indicator-value">%s</span></div>
                                <div><span class="jsb-indicator-label">Taxe Payée</span><span class="jsb-indicator-value">%s</span></div>
                                <div><span class="jsb-indicator-label">Solde</span><span class="jsb-indicator-value %s">%s</span></div>
                            </div>
                            <!-- Colonne 5 : Taxe Assureur -->
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
                    number_format((float)$comTTC, 2, ',', ' '),
                    number_format((float)$comPayee, 2, ',', ' '),
                    $clsCS, number_format((float)$comSolde, 2, ',', ' '),
                    number_format((float)$comHT, 2, ',', ' '),
                    number_format((float)$comPure, 2, ',', ' '),
                    number_format((float)$reserve, 2, ',', ' '),
                    htmlspecialchars($partenaireNom),
                    number_format((float)$retroDue, 2, ',', ' '),
                    number_format((float)$retroPayee, 2, ',', ' '),
                    $clsRS, number_format((float)$retroSolde, 2, ',', ' '),
                    number_format((float)$taxeCMontant, 2, ',', ' '),
                    number_format((float)$taxeCPayee, 2, ',', ' '),
                    $clsTCS, number_format((float)$taxeCSolde, 2, ',', ' '),
                    number_format((float)$taxeAMontant, 2, ',', ' '),
                    number_format((float)$taxeAPayee, 2, ',', ' '),
                    $clsTAS, number_format((float)$taxeASolde, 2, ',', ' ')
                );
            }
        ]);

        $resolver->setNormalizer('query_builder', function (Options $options, $value) {
            
            $er = $this->em->getRepository($options['class']);
            $entrepriseId = $this->ecouteurFormulaire->getCurrentEntrepriseId();
            $request = $this->requestStack->getCurrentRequest();
            
            $noteId = $options['note_id'] 
                ?? ($request ? $request->query->get('note_id') : null)
                ?? ($request ? $request->query->get('parent_id') : null);
                
            $liveAssureurId = $request ? $request->query->get('live_assureur_id') : null;
            $liveClientId = $request ? $request->query->get('live_client_id') : null;
            
            $qb = $er->createQueryBuilder('r')
                ->addSelect('tr', 'c', 'a', 'assureur', 'piste', 'client')
                ->join('r.typeRevenu', 'tr')
                ->join('r.cotation', 'c')
                ->join('c.avenants', 'a') 
                ->leftJoin('c.assureur', 'assureur')
                ->leftJoin('c.piste', 'piste')
                ->leftJoin('piste.client', 'client')
                ->where('tr.entreprise = :eseId')
                ->setParameter('eseId', $entrepriseId);

            if ($liveAssureurId) {
                $qb->andWhere('assureur.id = :assureurId')->setParameter('assureurId', $liveAssureurId);
            } 
            elseif ($liveClientId) {
                $qb->andWhere('client.id = :clientId')->setParameter('clientId', $liveClientId);
            }
            elseif ($noteId) {
                $note = $this->em->getRepository(Note::class)->find($noteId);
                
                if ($note) {
                    $destinataire = $note->getAddressedTo();
                    
                    if ($destinataire == Note::TO_CLIENT && $note->getClient()) {
                        $qb->andWhere('client.id = :clientId')->setParameter('clientId', $note->getClient()->getId());
                    } 
                    elseif ($destinataire == Note::TO_ASSUREUR && $note->getAssureur()) {
                        $qb->andWhere('assureur.id = :assureurId')->setParameter('assureurId', $note->getAssureur()->getId());
                    }
                    elseif ($destinataire == Note::TO_PARTENAIRE && $note->getPartenaire()) {
                        $qb->leftJoin('piste.partenaires', 'partenaires')
                           ->andWhere('partenaires.id = :partenaireId')
                           ->setParameter('partenaireId', $note->getPartenaire()->getId());
                    } else {
                        $qb->andWhere('1 = 0');
                    }
                } else {
                    $qb->andWhere('1 = 0');
                }
            } 

            $qb->orderBy('r.id', 'ASC');
            
            return $qb;
        });
    }

    public function getParent(): string
    {
        return BaseEntityAutocompleteType::class;
    }
}