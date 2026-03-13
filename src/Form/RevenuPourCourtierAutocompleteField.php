<?php

namespace App\Form;

use App\Entity\Note;
use App\Entity\RevenuPourCourtier;
use App\Services\FormListenerFactory;
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
        private EntityManagerInterface $em
    ) {}
    
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'class' => RevenuPourCourtier::class,
            'placeholder' => 'Rechercher un revenu',
            'note_id' => null, // Déclaration de notre option personnalisée
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
            }
        ]);

        // La MÉTHODE OFFICIELLE : Un normalizer qui retourne l'objet QueryBuilder directement
        $resolver->setNormalizer('query_builder', function (Options $options, $value) {
            
            // On récupère le repository via l'EntityManager injecté
            $er = $this->em->getRepository($options['class']);
            
            $entrepriseId = $this->ecouteurFormulaire->getCurrentEntrepriseId();
            $request = $this->requestStack->getCurrentRequest();
            
            // Récupération des IDs classiques ou LIVE depuis le Javascript
            $noteId = $options['note_id'] 
                ?? ($request ? $request->query->get('note_id') : null)
                ?? ($request ? $request->query->get('parent_id') : null);
                
            $liveAssureurId = $request ? $request->query->get('live_assureur_id') : null;
            $liveClientId = $request ? $request->query->get('live_client_id') : null;
            
            $qb = $er->createQueryBuilder('r')
                ->addSelect('tr', 'c', 'a', 'assureur', 'piste', 'client')
                ->join('r.typeRevenu', 'tr')
                ->join('r.cotation', 'c')
                ->join('c.avenants', 'a') // INNER JOIN pour les polices validées
                ->leftJoin('c.assureur', 'assureur')
                ->leftJoin('c.piste', 'piste')
                ->leftJoin('piste.client', 'client')
                ->where('tr.entreprise = :eseId')
                ->setParameter('eseId', $entrepriseId);

            // --- 1. PRIORITÉ ABSOLUE : Données LIVE de l'interface ---
            if ($liveAssureurId) {
                error_log("[RevenuAutocomplete DEBUG] Filtrage LIVE par Assureur ID: " . $liveAssureurId);
                $qb->andWhere('assureur.id = :assureurId')->setParameter('assureurId', $liveAssureurId);
            } 
            elseif ($liveClientId) {
                error_log("[RevenuAutocomplete DEBUG] Filtrage LIVE par Client ID: " . $liveClientId);
                $qb->andWhere('client.id = :clientId')->setParameter('clientId', $liveClientId);
            }
            // --- 2. FALLBACK : Données de la base de données ---
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
            else {
                error_log("[RevenuAutocomplete DEBUG] INFO: Aucun NoteID ni LiveID. Affichage global par défaut.");
            }

            $qb->orderBy('r.id', 'ASC');
            
            // On retourne DIRECTEMENT l'objet QueryBuilder
            return $qb;
        });
    }

    public function getParent(): string
    {
        return BaseEntityAutocompleteType::class;
    }
}