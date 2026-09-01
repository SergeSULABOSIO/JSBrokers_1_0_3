<?php

namespace App\Service\Conge;

use App\Entity\Entreprise;
use App\Entity\ParametresConge;
use App\Repository\ParametresCongeRepository;

/**
 * LES RÉGLAGES D'UN CABINET, DÉRIVÉS EN VALEURS UTILISABLES.
 *
 * ── POURQUOI UNE COUCHE DE PLUS ─────────────────────────────────────────────────────
 * Le seuil d'alerte est stocké en MULTIPLE de la dotation (« 2× »), parce que c'est ainsi
 * qu'un cabinet le pense. Mais tout ce qui l'utilise a besoin d'un nombre de JOURS. Cette
 * multiplication traînait déjà dans la commande de rappels ; la grille des compteurs en
 * avait besoin à son tour, et la recopier aurait fait deux endroits où lire le même
 * seuil — donc deux valeurs possibles le jour où l'un change.
 *
 * Le repository, lui, rend l'entité ; ce service rend les nombres qu'on en tire.
 */
class ParametresDuCabinet
{
    public function __construct(
        private readonly ParametresCongeRepository $repository,
    ) {
    }

    public function pour(Entreprise $entreprise): ParametresConge
    {
        return $this->repository->pourEntreprise($entreprise);
    }

    /**
     * Le seuil d'alerte de report, EN JOURS.
     *
     * Zéro signifie « pas d'alerte » : un cabinet doit pouvoir l'éteindre, comme tous les
     * autres contrôles du module.
     */
    public function seuilDAlerteEnJours(Entreprise $entreprise): float
    {
        $parametres = $this->pour($entreprise);

        return $parametres->seuilAlerteReportFloat() * $parametres->dotationAnnuelleFloat();
    }

    /** La dotation annuelle du cabinet, celle que l'ouverture d'exercice crédite. */
    public function dotationAnnuelle(Entreprise $entreprise): float
    {
        return $this->pour($entreprise)->dotationAnnuelleFloat();
    }
}
