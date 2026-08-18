<?php

namespace App\Form;

use App\Entity\Avenant;
use App\Services\FormListenerFactory;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\UX\Autocomplete\Form\AsEntityAutocompleteField;
use Symfony\UX\Autocomplete\Form\BaseEntityAutocompleteType;

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
    ) {
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'class' => Avenant::class,
            'placeholder' => 'Sélectionner une police',
            'query_builder' => $this->ecouteurFormulaire->setFiltreEntreprise(),
            'searchable_fields' => ['referencePolice'],
            'as_html' => true,
            'choice_label' => function (Avenant $avenant) {
                $cotation = $avenant->getCotation();
                $piste = $cotation?->getPiste();

                $contexte = array_filter([
                    $piste?->getClient()?->getNom(),
                    $piste?->getRisque()?->getCode(),
                    $cotation?->getAssureur()?->getNom(),
                ]);
                $periode = $avenant->getStartingAt() !== null
                    ? sprintf(
                        '%s → %s',
                        $avenant->getStartingAt()->format('d/m/Y'),
                        $avenant->getEndingAt()?->format('d/m/Y') ?? '…',
                    )
                    : null;

                return sprintf(
                    '<div><strong>%s</strong><div style="color: #6c757d; font-size: 0.85em; padding-left: 2px; margin-top: 2px;">%s</div></div>',
                    htmlspecialchars($avenant->getReferencePolice() ?: 'Police sans référence'),
                    htmlspecialchars(implode(' · ', array_filter([implode(' · ', $contexte), $periode]))),
                );
            },
        ]);
    }

    public function getParent(): string
    {
        return BaseEntityAutocompleteType::class;
    }
}
