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
        private EntityManagerInterface $em
    ) {}
    
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'class' => RevenuPourCourtier::class,
            'placeholder' => 'Rechercher un revenu',
            'query_builder' => function (EntityRepository $er) {
                $entrepriseId = $this->ecouteurFormulaire->getCurrentEntrepriseId();
                
                $request = $this->requestStack->getCurrentRequest();
                $noteId = $request ? $request->query->get('note_id') : null;
                
                error_log(sprintf("\n[RevenuAutocomplete DEBUG] --- Démarrage. NoteID reçu: %s ---", $noteId ?? 'NULL'));
                
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

                // --- VERROUILLAGE STRICT DU FILTRAGE ---
                if ($noteId) {
                    $note = $this->em->getRepository(Note::class)->find($noteId);
                    
                    if ($note) {
                        $destinataire = $note->getAddressedTo();
                        error_log(sprintf("[RevenuAutocomplete DEBUG] Note trouvée. Type destinataire: '%s'", $destinataire));
                        
                        // Utilisation de "==" pour éviter les bugs de typage (string "1" vs entier 1)
                        if ($destinataire == Note::TO_CLIENT) {
                            if ($note->getClient()) {
                                error_log(sprintf("[RevenuAutocomplete DEBUG] Filtrage strict sur Client ID: %s", $note->getClient()->getId()));
                                $qb->andWhere('client.id = :clientId')
                                   ->setParameter('clientId', $note->getClient()->getId());
                            } else {
                                error_log("[RevenuAutocomplete DEBUG] FAIL-SAFE: Destinataire = Client, mais champ Client vide !");
                                $qb->andWhere('1 = 0');
                            }
                        } 
                        elseif ($destinataire == Note::TO_ASSUREUR) {
                            if ($note->getAssureur()) {
                                error_log(sprintf("[RevenuAutocomplete DEBUG] Filtrage strict sur Assureur ID: %s", $note->getAssureur()->getId()));
                                $qb->andWhere('assureur.id = :assureurId')
                                   ->setParameter('assureurId', $note->getAssureur()->getId());
                            } else {
                                error_log("[RevenuAutocomplete DEBUG] FAIL-SAFE: Destinataire = Assureur, mais champ Assureur vide !");
                                $qb->andWhere('1 = 0');
                            }
                        }
                        elseif ($destinataire == Note::TO_PARTENAIRE) {
                            if ($note->getPartenaire()) {
                                error_log(sprintf("[RevenuAutocomplete DEBUG] Filtrage strict sur Partenaire ID: %s", $note->getPartenaire()->getId()));
                                $qb->leftJoin('piste.partenaires', 'partenaires')
                                   ->andWhere('partenaires.id = :partenaireId')
                                   ->setParameter('partenaireId', $note->getPartenaire()->getId());
                            } else {
                                error_log("[RevenuAutocomplete DEBUG] FAIL-SAFE: Destinataire = Partenaire, mais champ Partenaire vide !");
                                $qb->andWhere('1 = 0');
                            }
                        } 
                        else {
                            error_log(sprintf("[RevenuAutocomplete DEBUG] FAIL-SAFE: Type de destinataire ignoré ou non géré (%s)", $destinataire));
                            $qb->andWhere('1 = 0'); // On bloque tout si le type n'est pas reconnu
                        }
                    } else {
                        error_log("[RevenuAutocomplete DEBUG] FAIL-SAFE: Note introuvable en base de données !");
                        $qb->andWhere('1 = 0');
                    }
                } else {
                    error_log("[RevenuAutocomplete DEBUG] FAIL-SAFE: Aucun ID de Note fourni à l'URL !");
                    $qb->andWhere('1 = 0');
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