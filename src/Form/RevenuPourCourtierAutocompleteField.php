<?php

namespace App\Form;

use App\Entity\Note;
use App\Entity\RevenuPourCourtier;
use App\Services\FormListenerFactory;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\AbstractType;
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
        private EntityManagerInterface $em // Indispensable pour interroger la Note
    ) {}
    
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'class' => RevenuPourCourtier::class,
            'placeholder' => 'Rechercher un revenu',
            'query_builder' => function (EntityRepository $er) {
                $entrepriseId = $this->ecouteurFormulaire->getCurrentEntrepriseId();
                
                // 1. On intercepte le note_id que notre script Javascript a ajouté à l'URL
                $request = $this->requestStack->getCurrentRequest();
                $noteId = $request ? $request->query->get('note_id') : null;
                
                // 2. Requête de base (uniquement les revenus liés à une cotation validée par un avenant)
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

                // 3. Application stricte de la logique métier (Filtrage selon le destinataire de la Note)
                if ($noteId) {
                    $note = $this->em->getRepository(Note::class)->find($noteId);
                    
                    if ($note) {
                        $destinataire = $note->getAddressedTo();
                        
                        if ($destinataire === Note::TO_CLIENT && $note->getClient()) {
                            // On filtre pour que le revenu appartienne au client de la note
                            $qb->andWhere('client.id = :clientId')
                               ->setParameter('clientId', $note->getClient()->getId());
                        } elseif ($destinataire === Note::TO_ASSUREUR && $note->getAssureur()) {
                            // On filtre pour que le revenu appartienne à l'assureur de la note
                            $qb->andWhere('assureur.id = :assureurId')
                               ->setParameter('assureurId', $note->getAssureur()->getId());
                        }
                    }
                }

                $qb->orderBy('r.id', 'ASC');
                return $qb;
            },
            'searchable_fields' => ['nom'],
            'as_html' => true,
            'choice_label' => function(RevenuPourCourtier $revenu) {
                $taux = $revenu->getTauxExceptionel() !== null ? ($revenu->getTauxExceptionel() * 100) . '%' : '-';
                $montant = $revenu->getMontantFlatExceptionel() !== null ? number_format($revenu->getMontantFlatExceptionel(), 2, ',', ' ') : '-';
                
                $cotation = $revenu->getCotation();
                $avenant = ($cotation && !$cotation->getAvenants()->isEmpty()) ? $cotation->getAvenants()->first() : null;
                $piste = $cotation ? $cotation->getPiste() : null;
                
                $policeRef = ($avenant && $avenant->getReferencePolice()) ? $avenant->getReferencePolice() : 'N/A';
                $assureurNom = ($cotation && $cotation->getAssureur()) ? $cotation->getAssureur()->getNom() : 'N/A';
                $clientNom = ($piste && $piste->getClient()) ? $piste->getClient()->getNom() : 'N/A';
                
                return sprintf(
                    '<div><strong>%s</strong><div style="color: #6c757d; font-size: 0.85em; padding-left: 2px; margin-top: 2px;">Réf Police: %s | Assureur: %s | Client: %s | Taux: %s | Flat: %s</div></div>',
                    htmlspecialchars($revenu->getNom() ?? 'Sans nom'),
                    htmlspecialchars($policeRef),
                    htmlspecialchars($assureurNom),
                    htmlspecialchars($clientNom),
                    $taux,
                    $montant
                );
            },
        ]);
    }

    public function getParent(): string
    {
        return BaseEntityAutocompleteType::class;
    }
}