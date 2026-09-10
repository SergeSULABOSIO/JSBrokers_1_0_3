<?php

namespace App\Echange\Service;

use App\Entity\Entreprise;
use App\Repository\EchangeImportRunRepository;
use App\Token\ParametresTokenService;

/**
 * CE QUE LA REPRISE OFFRE À UN CABINET, ET CE QU'ELLE LUI FACTURE ENSUITE.
 *
 * Le modèle tient en une phrase : **les N premières lignes de la feuille `DONNEES` sont
 * offertes, à vie, quel que soit le nombre d'enregistrements qu'elles font naître.**
 * Au-delà, chaque écriture reprend son métrage ordinaire — celui d'une saisie à l'écran.
 *
 * ⚠ CE N'EST PAS UN FORFAIT, C'EST UNE EXONÉRATION. La nuance décide de tout : l'import
 * DÉBITE DÉJÀ, entité par entité, via le circuit d'écriture commun. Ajouter un prix
 * au-delà du seuil ferait payer deux fois le même geste. On retire donc le métrage sur les
 * lignes offertes, et on le laisse faire sur les autres.
 *
 * ⚠ ET ON COMPTE DES LIGNES, JAMAIS DES ENTITÉS. Une ligne qui crée un client, un risque,
 * un assureur, une piste, une cotation et une échéance consomme UNE ligne de franchise.
 * C'est ce qui rend la promesse tenable et vérifiable par le cabinet lui-même : il lui
 * suffit de regarder son fichier.
 */
final class FranchiseDeReprise
{
    public function __construct(
        private readonly EchangeImportRunRepository $runs,
        private readonly ParametresTokenService $parametres,
    ) {
    }

    /** Le seuil en vigueur — réglable en console, annoncé sur le site public. */
    public function plafond(): int
    {
        return $this->parametres->echangeFranchiseLignes();
    }

    /**
     * Lignes de franchise déjà consommées par ce cabinet, tous dépôts confondus.
     *
     * ⚠ LA SOMME PORTE SUR LES RUNS, ET NON SUR LES OCCURRENCES D'ÉCHANGE. Deux raisons,
     * chacune suffisante : une occurrence n'est écrite qu'à la fin d'un import RÉUSSI —
     * un import interrompu aurait donc écrit des lignes gratuites sans laisser de trace —,
     * et `EchangeOccurrence::nbLignes` est aussi renseigné par les EXPORTS, dont le volume
     * n'a rien à voir avec cette franchise.
     */
    public function dejaConsommees(Entreprise $entreprise): int
    {
        return $this->runs->sommeDesLignesFranchisees($entreprise);
    }

    /** Ce qu'il reste d'offert. Jamais négatif : au-delà du seuil, c'est zéro. */
    public function restantes(Entreprise $entreprise): int
    {
        return max(0, $this->plafond() - $this->dejaConsommees($entreprise));
    }

    /**
     * Sur un fichier de `$lignes` lignes, combien seront facturées ?
     *
     * Sert à l'annonce faite avant la confirmation : le rapport de contrôle en tire le
     * nombre de lignes offertes et le nombre de lignes payantes.
     */
    public function lignesFacturables(Entreprise $entreprise, int $lignes): int
    {
        return max(0, max(0, $lignes) - $this->restantes($entreprise));
    }

    /**
     * Combien de lignes d'un palier de `$taille` lignes sont encore couvertes, sachant que
     * `$dejaCeRun` l'ont déjà été depuis le début du run.
     *
     * ⚠ LE SOLDE SE LIT UNE FOIS PAR PALIER, PAS UNE FOIS PAR LIGNE. Interroger la base à
     * chaque ligne coûterait une requête par écriture ; et surtout, le compteur du run
     * n'est flushé qu'en fin de transaction : relire en cours de palier rendrait une
     * valeur périmée, donc un décompte qui dérive.
     */
    public function couvertesDansCePalier(Entreprise $entreprise, int $dejaCeRun, int $taille): int
    {
        $restantes = max(0, $this->restantes($entreprise) - max(0, $dejaCeRun));

        return min(max(0, $taille), $restantes);
    }
}
