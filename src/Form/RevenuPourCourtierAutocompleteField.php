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
        // INJECTION ARCHITECTURALE PARFAITE : Le chef d'orchestre
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
                // 1. Informations textuelles basiques
                $taux = $revenu->getTauxExceptionel() !== null ? ($revenu->getTauxExceptionel() * 100) . '%' : '-';
                $montant = $revenu->getMontantFlatExceptionel() !== null ? number_format($revenu->getMontantFlatExceptionel(), 2, ',', ' ') : '-';
                
                $cotation = $revenu->getCotation();
                $avenant = ($cotation && !$cotation->getAvenants()->isEmpty()) ? $cotation->getAvenants()->first() : null;
                $piste = $cotation ? $cotation->getPiste() : null;
                
                $policeRef = ($avenant && $avenant->getReferencePolice()) ? $avenant->getReferencePolice() : 'N/A';
                $assureurNom = ($cotation && $cotation->getAssureur()) ? $cotation->getAssureur()->getNom() : 'N/A';
                $clientNom = ($piste && $piste->getClient()) ? $piste->getClient()->getNom() : 'N/A';
                
                // 2. MAGIE DU CANVAS BUILDER : On hydrate dynamiquement l'entité avec ses valeurs calculées
                $this->canvasBuilder->loadAllCalculatedValues($revenu);
                
                // L'entité possède maintenant des propriétés dynamiques injectées par la stratégie !
                $montantTTC = $revenu->montant_du ?? 0.0;
                $montantPaye = $revenu->montant_paye ?? 0.0;
                $soldeRestantDu = $revenu->solde_restant_du ?? 0.0;

                // 3. Formatage HTML enrichi
                return sprintf(
                    '<div>
                        <strong>%s</strong>
                        <div style="color: #6c757d; font-size: 0.85em; padding-left: 2px; margin-top: 2px;">
                            Réf Police: %s | Assureur: %s | Client: %s | Taux: %s | Flat: %s
                        </div>
                        <div style="font-size: 0.85em; padding-left: 2px; margin-top: 4px; border-top: 1px dashed #ccc; padding-top: 2px; display: flex; gap: 10px;">
                            <span class="text-primary"><strong>TTC:</strong> %s</span>
                            <span class="text-success"><strong>Payé:</strong> %s</span>
                            <span class="text-danger"><strong>Solde:</strong> %s</span>
                        </div>
                    </div>',
                    htmlspecialchars($revenu->getNom() ?? 'Sans nom'),
                    htmlspecialchars($policeRef),
                    htmlspecialchars($assureurNom),
                    htmlspecialchars($clientNom),
                    $taux,
                    $montant,
                    number_format((float)$montantTTC, 2, ',', ' '),
                    number_format((float)$montantPaye, 2, ',', ' '),
                    number_format((float)$soldeRestantDu, 2, ',', ' ')
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