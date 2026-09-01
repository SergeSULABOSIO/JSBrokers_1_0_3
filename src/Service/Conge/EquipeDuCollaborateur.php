<?php

namespace App\Service\Conge;

use App\Entity\Invite;
use App\Repository\InviteRepository;

/**
 * QUI EST « L'ÉQUIPE » D'UN COLLABORATEUR, pour le plafond d'absents simultanés.
 *
 * ── LE PROJET N'A PAS DE NOTION DE SERVICE ──────────────────────────────────────────
 * La spécification parle d'un plafond « par service ». Il n'en existe aucun dans cette
 * application, et en inventer un aurait ajouté un concept structurant — à rattacher, à
 * maintenir, à afficher partout — pour un seul contrôle d'un seul module.
 *
 * La seule structure organisationnelle réelle est la hiérarchie manager / assistants,
 * déjà saisie dans le dialogue de l'invité (champ « Assistants »). C'est elle qu'on
 * utilise : l'équipe d'un collaborateur, ce sont ceux qui répondent au même responsable
 * que lui, le responsable compris.
 *
 * ── SANS MANAGER, LE CABINET ENTIER ─────────────────────────────────────────────────
 * Un collaborateur non rattaché n'a pas d'équipe. Le faire concourir avec personne
 * rendrait le plafond inopérant précisément là où la hiérarchie n'est pas renseignée —
 * c'est-à-dire, aujourd'hui, presque partout. On retombe donc sur le cabinet : un
 * plafond large vaut mieux qu'un plafond absent, et le cabinet peut de toute façon
 * l'éteindre.
 */
class EquipeDuCollaborateur
{
    public function __construct(
        private readonly InviteRepository $inviteRepository,
    ) {
    }

    /**
     * Les collègues qui partagent le périmètre de ce collaborateur, lui-même EXCLU.
     *
     * @return Invite[]
     */
    public function collegues(Invite $agent): array
    {
        $equipe = $this->membres($agent);

        return array_values(array_filter(
            $equipe,
            static fn (Invite $membre) => $membre->getId() !== $agent->getId(),
        ));
    }

    /**
     * L'équipe, collaborateur compris.
     *
     * @return Invite[]
     */
    public function membres(Invite $agent): array
    {
        $manager = $agent->getManager();

        if ($manager !== null) {
            // Le responsable et tous ceux qui lui répondent : c'est le groupe dont
            // l'absence simultanée se remarque.
            //
            // ── ON INTERROGE, ON NE LIT PAS LA COLLECTION INVERSE ────────────────────
            // `$manager->getAssistants()` n'est juste que si quelqu'un a maintenu la
            // relation dans les deux sens. Un rattachement posé par un simple
            // `setManager()` — ce que fait tout écrivain générique, l'assistant compris —
            // laisserait la collection vide, et le plafond ne compterait personne. Un
            // contrôle qui ne compte personne ne refuse jamais rien, et son silence
            // ressemble à un cabinet où tout va bien.
            return array_merge([$manager], $this->inviteRepository->findBy(['manager' => $manager]));
        }

        // ── SANS RESPONSABLE : LE CABINET ────────────────────────────────────────────
        // On ne prend PAS « l'agent et ses propres assistants » : un responsable serait
        // alors comparé à son équipe, mais ses pairs — les autres responsables — ne
        // seraient comparés à personne. Le cabinet est le seul repli qui traite tout le
        // monde de la même façon.
        $entreprise = $agent->getEntreprise();

        return $entreprise === null ? [$agent] : $this->inviteRepository->findBy(['entreprise' => $entreprise]);
    }

    /**
     * Le périmètre est-il celui d'une vraie équipe, ou le repli sur le cabinet ?
     *
     * Le message de refus doit le dire : « 3 absents sur votre équipe » et « 3 absents
     * dans le cabinet » ne s'entendent pas pareil, et le second signale au passage qu'il
     * manque un rattachement hiérarchique.
     */
    public function estUneVraieEquipe(Invite $agent): bool
    {
        return $agent->getManager() !== null;
    }
}
