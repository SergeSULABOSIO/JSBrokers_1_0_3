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
            // Le nom du bénéficiaire, quelle que soit sa famille : la source unique vit sur
            // l'entité, sinon chaque surface refait le XOR et l'une d'elles n'en traite
            // qu'une moitié — ici, un partenaire se serait affiché « N/A ».
            'beneficiaireNom' => $entity->beneficiaireNom(),
            // LA POLICE ET L'ÉCHÉANCE NE PARLENT QUE D'UNE LIGNE. Sur une vue repliée, où
            // une ligne en couvre six, nommer celle du porteur aurait désigné une affaire
            // sur six comme si c'était la seule réglée. Elles se taisent alors, et le
            // nombre d'échéances prend leur place.
            'policeReference' => $this->lots->litLesVirements() ? null : ($entity->getAvenant()?->getReferencePolice()
                ?: ($entity->getAvenant() !== null ? '#' . $entity->getAvenant()->getId() : 'N/A')),
            // L'ÉCHÉANCE RÉGLÉE — la maille du versement, et donc l'information qui
            // distingue deux lignes d'une même police. Sans elle, un virement sur la 1re
            // échéance et un autre sur la 2e se lisaient à l'identique.
            //
            // « Toute la police » plutôt que rien pour les lignes ANTÉRIEURES au passage à
            // cette maille : elles n'ont pas de tranche, et un blanc aurait laissé croire à
            // une donnée manquante alors que c'est un versement d'affaire entière.
            'echeanceLibelle' => $this->lots->litLesVirements()
                ? null
                : ($entity->getTranche()?->getNom() ?: 'Toute la police'),
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
            // ── CE QUE CETTE LIGNE REPRÉSENTE, EN ARGENT ─────────────────────────────
            //
            // Repliée, elle vaut son VIREMENT entier ; dépliée, elle ne vaut qu'elle-même.
            // La somme des lignes affichées rend donc le décaissement réel dans les deux
            // modes — et c'est exactement ce que la barre des totaux additionne. Rendre
            // toujours le total du virement l'aurait TRIPLÉ sous « Détail par échéance » ;
            // rendre toujours celui de la ligne l'aurait fait chuter au sixième sous la
            // vue repliée. Ni l'un ni l'autre n'aurait produit d'erreur.
            'montantAffiche' => $this->lots->montantAffiche($entity),
            // Combien d'échéances ce virement règle — sur une vue repliée seulement, où
            // c'est l'information qui remplace la police et l'échéance du seul porteur.
            'echeancesDuVirement' => $this->lots->litLesVirements()
                ? (($n = $this->lots->nombreEcheances($entity)) > 1
                    ? $n . ' échéances réglées'
                    : '1 échéance réglée')
                : null,
        ];
    }
}
