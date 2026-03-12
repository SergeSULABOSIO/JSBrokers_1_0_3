<?php

namespace App\Form;

use App\Entity\RevenuPourCourtier;
use App\Services\FormListenerFactory;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\UX\Autocomplete\Form\AsEntityAutocompleteField;
use Symfony\UX\Autocomplete\Form\BaseEntityAutocompleteType;
use Doctrine\ORM\EntityRepository;

#[AsEntityAutocompleteField]
class RevenuPourCourtierAutocompleteField extends AbstractType
{
    public function __construct(
        private FormListenerFactory $ecouteurFormulaire,
    ) {}
    
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'class' => RevenuPourCourtier::class,
            'placeholder' => 'Rechercher un revenu',
            'query_builder' => function (EntityRepository $er) {
                // On récupère l'ID de l'entreprise courante depuis la requête ou le contexte.
                // Si ton ecouteurFormulaire a une méthode pour récupérer l'entreprise, utilise-la.
                // Exemple : $entrepriseId = $this->ecouteurFormulaire->getCurrentEntrepriseId();
                
                // On crée le QueryBuilder en faisant la jointure avec typeRevenu
                $qb = $er->createQueryBuilder('r')
                    ->leftJoin('r.typeRevenu', 'tr');
                
                // Si tu as un moyen de récupérer l'ID de l'entreprise via ton factory
                if (method_exists($this->ecouteurFormulaire, 'getCurrentEntrepriseId')) {
                     $entrepriseId = $this->ecouteurFormulaire->getCurrentEntrepriseId();
                     if($entrepriseId) {
                         $qb->andWhere('tr.entreprise = :eseId')
                            ->setParameter('eseId', $entrepriseId);
                     }
                }
                // Si la méthode n'existe pas, on retourne simplement le QB sans le filtre
                // ou tu peux implémenter la logique pour récupérer l'entreprise active ici.

                return $qb;
            },
            'searchable_fields' => ['nom'],
            'as_html' => true,
            'choice_label' => function(RevenuPourCourtier $revenu) {
                return sprintf(
                    '<div><strong>%s</strong><div style="color: #6c757d; font-size: 0.85em; padding-left: 2px; margin-top: 2px;">Revenu / Commission</div></div>',
                    htmlspecialchars($revenu->getNom() ?? 'Sans nom')
                );
            },
        ]);
    }

    public function getParent(): string
    {
        return BaseEntityAutocompleteType::class;
    }
}