<?php

namespace App\Services\Canvas\Indicator;

use App\Entity\ReversementRetroAgent;

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
            // Le nombre de PIÈCES de cette ligne. Une seule suffit pour tout un virement :
            // les autres lignes du lot afficheront donc zéro, et c'est le volet
            // « Versements enregistrés » du rapport qui raisonne par virement.
            'nombreJustificatifs' => $entity->getDocuments()->count(),
            // Un virement groupé se dit : sans cela, trois lignes d'un même décaissement se
            // liraient comme trois virements distincts.
            'virementGroupe' => $entity->getLotReference() !== null && trim($entity->getLotReference()) !== ''
                ? 'Virement groupé ' . $entity->getLotReference()
                : null,
        ];
    }
}
