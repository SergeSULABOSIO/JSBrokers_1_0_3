<?php

namespace App\Echange\Reprise;

/**
 * LA COMMISSION PAR DÉFAUT D'UN CABINET DE COURTAGE.
 *
 * ── POURQUOI CE FICHIER EXISTE ──────────────────────────────────────────────────────
 * Un classeur de reprise écrit « Commission ». Le cabinet, lui, a « Commission
 * Ordinaire » — c'est le nom que pose le semis officiel
 * ({@see \App\Services\ServiceInitialisationEntreprise::initialiserChargementsEtRevenus()}).
 * Les deux désignent la même chose, et la reprise refusait la ligne sur cet écart, sur un
 * fichier qui disait pourtant la vérité.
 *
 * ── ELLE EST LE DÉFAUT, ET PLUS SEULEMENT UN SYNONYME ───────────────────────────────
 * Cette classe a porté un temps une liste fermée de libellés ayant droit au repli
 * (« Commission », « Commissions », « Commission de courtage »…). Elle n'a plus lieu
 * d'être : TOUT libellé que le cabinet ne connaît pas se rattache désormais à ce type.
 *
 * ⚠ ET CELA NE FACTURE RIEN AU MAUVAIS DÉBITEUR, contrairement à ce qu'on pouvait
 * craindre. Ce qui basculait, dans la version à liste fermée, c'était le NOM du revenu :
 * « Frais de consultance » devenait « Commission Ordinaire », et l'information du
 * classeur était perdue. La règle actuelle sépare les deux — le revenu garde SON nom, le
 * type n'est qu'un rattachement — et le taux de la ligne l'emporte de toute façon sur
 * celui du type ({@see \App\Constantes\Constante::Revenu_getMontant_ht()}). Le débiteur
 * reste le seul réglage hérité, et l'utilisateur en est averti nommément.
 *
 * ⚠ LA REPRISE NE CRÉE PAS DE TYPES DE REVENU — elle crée des REVENUS. Une seule
 * exception, et c'est un filet : le cabinet qui n'a AUCUNE commission par défaut n'aurait
 * rien à quoi rattacher ses revenus. Elle est alors installée à l'identique du semis
 * ({@see ReconstitueurDeTranche::commissionOrdinaire()}).
 */
final class CommissionOrdinaire
{
    /**
     * Le nom du type de revenu, tel que le semis officiel le pose.
     *
     * ⚠ EMPRUNTÉ PAR LE SEMIS LUI-MÊME. Deux littéraux pour un seul nom, et le jour où
     * l'un des deux est retouché la reprise cesse silencieusement de reconnaître ce que
     * le cabinet vient d'installer.
     */
    public const NOM = 'Commission Ordinaire';
}
