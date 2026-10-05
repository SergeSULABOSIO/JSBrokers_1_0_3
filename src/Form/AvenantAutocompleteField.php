<?php

namespace App\Form;

use App\Entity\Avenant;
use App\Services\FormListenerFactory;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\UX\Autocomplete\Form\AsEntityAutocompleteField;
use Symfony\UX\Autocomplete\Form\BaseEntityAutocompleteType;
use App\Services\Canvas\Autocomplete\RenduOptionAutocomplete;

/**
 * Sélection d'un AVENANT (une police, ou l'un de ses actes) par sa référence.
 *
 * Ce que l'utilisateur cherche, c'est une POLICE : la référence en titre, puis le client,
 * le risque et la période en second rang — sans quoi deux avenants d'une même police sont
 * indiscernables dans la liste.
 *
 * Le filtre entreprise du projet (FormListenerFactory::setFiltreEntreprise) borne les
 * propositions à l'espace de travail actif : un avenant d'une autre entreprise ne doit
 * jamais être proposé, ni acceptable à la soumission.
 */
#[AsEntityAutocompleteField]
class AvenantAutocompleteField extends AbstractType
{
    public function __construct(
        private FormListenerFactory $ecouteurFormulaire,
        private RenduOptionAutocomplete $rendu,
    ) {
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'class' => Avenant::class,
            'placeholder' => 'Sélectionner une police',
            'query_builder' => $this->ecouteurFormulaire->setFiltreEntreprise(),
            'searchable_fields' => ['referencePolice'],
            'choice_label' => function (Avenant $avenant) {
                $cotation = $avenant->getCotation();
                $piste = $cotation?->getPiste();

                return $this->rendu->libelle(
                    titre: $avenant->getReferencePolice() ?: 'Police sans référence',
                    contact: [
                        $piste?->getClient()?->getNom(),
                        $piste?->getRisque()?->getCode(),
                        $cotation?->getAssureur()?->getNom(),
                        $this->periode($avenant),
                    ],
                );
            },
        ]);
    }

    /**
     * La periode de couverture, ou rien.
     *
     * Sans date de debut, il n'y a pas de periode a annoncer : mieux vaut se taire que
     * d'ecrire une borne seule, que le lecteur prendrait pour l'autre.
     */
    private function periode(Avenant $avenant): ?string
    {
        $debut = $avenant->getStartingAt();
        if ($debut === null) {
            return null;
        }

        return $debut->format('d/m/Y') . ' → ' . ($avenant->getEndingAt()?->format('d/m/Y') ?? '…');
    }

    public function getParent(): string
    {
        return BaseEntityAutocompleteType::class;
    }
}
