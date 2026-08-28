<?php

namespace App\Service\Retro;

use App\Entity\Avenant;
use App\Entity\ConditionPartage;
use App\Entity\Cotation;

/**
 * LE BÉNÉFICIAIRE D'UNE RÉTROCOMMISSION — agent interne ou partenaire externe.
 *
 * Les deux moitiés du partage sont calculées par le MÊME moteur, mais une seule était
 * racontable : un rapport de production ligne à ligne existait pour l'agent, rien pour le
 * partenaire. Écrire le second en recopiant le premier aurait produit deux jeux de règles
 * dont la première divergence n'aurait été découverte que par un intermédiaire contestant
 * sa facture.
 *
 * D'où cette interface. SIX POINTS SEULEMENT diffèrent entre les deux camps ; tout le
 * reste — prime, commission TTC et HT, taxes, commission pure, assiette partageable,
 * client, risque, assureur, gestionnaire, tri, totaux, statut de souscription — est
 * strictement identique et vit une seule fois, dans RapportProductionBuilder.
 *
 * ── LE CIRCUIT EST DÉSORMAIS COMMUN, LES ASSIETTES NON ──────────────────────────────
 * Le « payé » des DEUX familles se lit en clair sur les reversements, à la maille de la
 * TRANCHE : le partenaire envoie sa note de débit et se règle comme un agent. Il fut un
 * temps où son payé se déduisait de notes de crédit au prorata, et où celui de l'agent se
 * lisait par avenant — deux grains pour une même colonne, que note() devait signaler.
 *
 * Ce qui reste asymétrique, et qui doit le rester : l'ASSIETTE (le partenaire se sert sur
 * la commission partageable pleine, l'agent sur le reliquat) et le COMPTE comptable (632
 * contre 6611). C'est de cela que note() parle maintenant.
 */
interface BeneficiaireRetro
{
    public const TYPE_AGENT = 'agent';
    public const TYPE_PARTENAIRE = 'partenaire';

    /** self::TYPE_* — ce qui décide du circuit, du compte comptable et de la garde d'accès. */
    public function type(): string;

    public function id(): ?int;

    public function nom(): string;

    /**
     * Les affaires dont il est BÉNÉFICIAIRE — jamais celles qu'il gère : les deux axes
     * sont indépendants, et les confondre attribue la rémunération au mauvais.
     *
     * @return array<int, Cotation> indexé par id de cotation (dédoublonné)
     */
    public function cotations(): array;

    /** Ce que cette affaire lui doit, tous versements ignorés. */
    public function montantDu(?Cotation $cotation): float;

    /**
     * Lecture UNIQUE des versements pour tout le lot d'un rapport : les interroger ligne
     * par ligne coûterait une requête par affaire. Sans objet pour un bénéficiaire dont le
     * payé se lit déjà dans le graphe des notes.
     *
     * @param array<int, Avenant> $avenants
     */
    public function prechargerVersements(array $avenants): void;

    /** Ce qui lui a déjà été versé sur cette ligne. */
    public function montantPaye(?Cotation $cotation, ?Avenant $avenant): float;

    /**
     * Le solde RÉCLAMABLE : dû moins versé, mais seulement une fois le cabinet lui-même
     * encaissé. Payer avant d'avoir perçu, c'est avancer sa trésorerie sur une créance non
     * recouvrée — le « dû » reste visible, il n'est simplement pas encore exigible.
     */
    public function montantExigible(?Cotation $cotation, ?Avenant $avenant): float;

    /**
     * L'assiette sur laquelle son taux s'applique — ce à quoi le pourcentage se rapporte.
     * Sans elle, un montant ne peut être qu'affirmé : c'est le chaînon qui manque à toute
     * justification d'un chiffre contesté.
     */
    public function assiette(?Cotation $cotation): float;

    /** La condition de partage effectivement appliquée à cette affaire, s'il y en a une. */
    public function conditionRetenue(?Cotation $cotation): ?ConditionPartage;

    /**
     * La règle du métier que ces chiffres obéissent, à joindre à toute restitution : de
     * quelle assiette il s'agit, par quel circuit le versement passe, et sous quel compte
     * il se comptabilise. Trois confusions coûteuses, désamorcées d'avance.
     */
    public function note(): string;
}
