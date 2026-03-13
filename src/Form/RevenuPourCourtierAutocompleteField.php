<?php

namespace App\Form;

use App\Entity\Note;
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
                $montantPaye = $revenu->montantPaye ?? $revenu->montant_paye ?? 0.0;
                $soldeRestantDu = $revenu->soldeRestantDu ?? $revenu->solde_restant_du ?? 0.0;
                $taxeCourtier = $revenu->taxeCourtierMontant ?? 0.0;
                $taxeAssureur = $revenu->taxeAssureurMontant ?? 0.0;
                
                // NOUVEAU: Extraction de la commission pure, réserve et rétrocommission
                $commissionPure = $revenu->commissionPure ?? $revenu->commission_pure ?? 0.0;
                $reserve = $revenu->reserve ?? 0.0;
                $retrocom = $revenu->retrocommission ?? $revenu->retrocom ?? $revenu->montantRetrocommission ?? 0.0;
                
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
                // Utilisation de puces (&bull;) élégantes et discrètes entre les attributs
                return sprintf(
                    '<div>
                        <strong>%s</strong>
                        <div style="color: #6c757d; font-size: 0.85em; padding-left: 2px; margin-top: 2px;">
                            Réf Police: %s <span style="color: #adb5bd; margin: 0 4px;">&bull;</span> Assureur: %s <span style="color: #adb5bd; margin: 0 4px;">&bull;</span> Client: %s <span style="color: #adb5bd; margin: 0 4px;">&bull;</span> Tranches: %d
                        </div>
                        <div style="color: #0047AB; font-weight: bold; font-size: 0.85em; padding-left: 2px; margin-top: 4px; border-top: 1px dashed #ccc; padding-top: 2px; display: flex; flex-wrap: wrap; align-items: center; gap: 6px;">
                            <span title="Montant TTC calculé">TTC: %s</span>
                            <span style="color: #adb5bd; font-weight: normal;">&bull;</span>
                            <span title="Montant payé">Payé: %s</span>
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
                            <span title="Rétrocommission au partenaire">Rétro (%s): %s</span>
                        </div>
                    </div>',
                    htmlspecialchars($revenu->getNom() ?? 'Sans nom'),
                    htmlspecialchars($policeRef),
                    htmlspecialchars($assureurNom),
                    htmlspecialchars($clientNom),
                    $nombreTranches,
                    number_format((float)$revenuTTC, 2, ',', ' '),
                    number_format((float)$montantPaye, 2, ',', ' '),
                    number_format((float)$soldeRestantDu, 2, ',', ' '),
                    number_format((float)$taxeCourtier, 2, ',', ' '),
                    number_format((float)$taxeAssureur, 2, ',', ' '),
                    number_format((float)$commissionPure, 2, ',', ' '),
                    number_format((float)$reserve, 2, ',', ' '),
                    htmlspecialchars($partenaireNom),
                    number_format((float)$retrocom, 2, ',', ' ')
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