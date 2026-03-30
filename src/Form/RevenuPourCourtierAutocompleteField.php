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
                
                // 1. Extraction des indicateurs financiers via les VRAIES clés de ta stratégie
                $revenuTTC = $revenu->montantCalculeTTC ?? $revenu->montant_du ?? 0.0;
                $montantPaye = $revenu->montant_paye ?? 0.0; // Correction : Utiliser la clé exacte de la stratégie
                $soldeRestantDu = $revenu->solde_restant_du ?? 0.0; // Correction : Utiliser la clé exacte
                $taxeCourtier = $revenu->taxeCourtierMontant ?? 0.0;
                $taxeAssureur = $revenu->taxeAssureurMontant ?? 0.0;
                
                // NOUVEAU: Extraction de la commission pure, réserve et rétrocommission
                $commissionPure = $revenu->montantPur ?? 0.0; 
                $reserve = $revenu->reserve ?? 0.0;
                $retrocom = $revenu->retroCommission ?? 0.0; // Correction : Case sensitive (C majuscule)
                
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

                // 3. Formatage HTML enrichi
                return sprintf(
                    '<div class="jsb-autocomplete-item" data-montant-ttc="%f">
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
                            <div><span class="jsb-indicator-label">Valeur TTC</span><span class="jsb-indicator-value">%s</span></div>
                            <div><span class="jsb-indicator-label">Total Payé</span><span class="jsb-indicator-value text-success">%s</span></div>
                            <div><span class="jsb-indicator-label">Solde Dû</span><span class="jsb-indicator-value text-danger">%s</span></div>
                            <div><span class="jsb-indicator-label">Com. Pure</span><span class="jsb-indicator-value text-cobalt">%s</span></div>
                            <div><span class="jsb-indicator-label">Réserve</span><span class="jsb-indicator-value">%s</span></div>
                            <div><span class="jsb-indicator-label">Rétro (%s)</span><span class="jsb-indicator-value">%s</span></div>
                            <div><span class="jsb-indicator-label">T. Courtier</span><span class="jsb-indicator-value">%s</span></div>
                            <div><span class="jsb-indicator-label">T. Assureur</span><span class="jsb-indicator-value">%s</span></div>
                        </div>
                    </div>',
                    $revenuTTC,
                    htmlspecialchars($revenu->getNom() ?? 'Sans nom'),
                    htmlspecialchars($policeRef),
                    htmlspecialchars($assureurNom),
                    htmlspecialchars($clientNom),
                    $nombreTranches,
                    number_format((float)$revenuTTC, 2, ',', ' '),
                    number_format((float)$montantPaye, 2, ',', ' '),
                    number_format((float)$soldeRestantDu, 2, ',', ' '),
                    number_format((float)$commissionPure, 2, ',', ' '),
                    number_format((float)$reserve, 2, ',', ' '),
                    htmlspecialchars($partenaireNom),
                    number_format((float)$retrocom, 2, ',', ' '),
                    number_format((float)$taxeCourtier, 2, ',', ' '),
                    number_format((float)$taxeAssureur, 2, ',', ' ')
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