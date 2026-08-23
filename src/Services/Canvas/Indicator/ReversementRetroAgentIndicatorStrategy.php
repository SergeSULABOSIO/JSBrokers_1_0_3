<?php

namespace App\Services\Canvas\Indicator;

use App\Entity\ReversementRetroAgent;
use App\Service\Retro\LotDeVersement;

/**
 * CE QU'UNE LIGNE DE LA LISTE DES REVERSEMENTS DOIT MONTRER.
 *
 * Un versement ne se lit pas sans son bénéficiaire ni la police qu'il solde — mais un
 * canevas de liste ne sait lire qu'un attribut PLAT : `attribute(entity, code)`, sans
 * chemin pointé. La rubrique affichait donc `agent.nom` et tombait en erreur dès la
 * première ligne, ce qu'une liste vide ne pouvait pas révéler.
 *
 * Ces indicateurs sont la traduction : un code plat par donnée de relation, calculé ici et
 * nulle part ailleurs.
 */
class ReversementRetroAgentIndicatorStrategy implements IndicatorCalculationStrategyInterface
{
    public function __construct(private readonly LotDeVersement $lots)
    {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === ReversementRetroAgent::class;
    }

    public function calculate(object $entity): array
    {
        /** @var ReversementRetroAgent $entity */
        return [
            'beneficiaireNom' => $entity->getAgent()?->getNom() ?? 'N/A',
            'policeReference' => $entity->getAvenant()?->getReferencePolice()
                ?: ($entity->getAvenant() !== null ? '#' . $entity->getAvenant()->getId() : 'N/A'),
            // « Caisse (espèces) » plutôt que rien : un versement sans compte n'est pas un
            // versement sans provenance, c'est une sortie de caisse. Le même libellé qu'au
            // picker, pour que la ligne et le formulaire disent la même chose.
            'compteLibelle' => $entity->getCompteBancaire()?->getIntitule() ?? 'Caisse (espèces)',
            // LES PIÈCES DU VIREMENT, PAS DE LA LIGNE.
            //
            // Un bordereau couvre tout un lot : ne compter que les pièces de la ligne
            // ferait passer pour nues deux lignes sur trois d'un virement pourtant
            // justifié — la dette de preuve affichée serait fausse. Le compte est
            // PRÉCHARGÉ par page (LotDeVersement::prechargerJustificatifs), sans quoi
            // chaque ligne interrogerait son lot.
            'nombreJustificatifs' => $this->lots->compteDeJustificatifs($entity),
            'justificatifLibelle' => $this->lots->libelleJustificatif($entity),
            // Un virement groupé se dit : sans cela, trois lignes d'un même décaissement se
            // liraient comme trois virements distincts.
            'virementGroupe' => $entity->getLotReference() !== null && trim($entity->getLotReference()) !== ''
                ? 'Virement groupé ' . $entity->getLotReference()
                : null,
        ];
    }
}
