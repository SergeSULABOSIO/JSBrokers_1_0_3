<?php

namespace App\Service\Console;

use App\Entity\Utilisateur;
use App\Repository\UtilisateurRepository;

/**
 * QUI A LE DROIT DE TOUCHER À QUEL COMPTE — la règle, à un seul endroit.
 *
 * ── CE QU'ELLE FERME ────────────────────────────────────────────────────────
 * Les deux écrans de comptes de la Console résolvaient leur cible par `{id}` et
 * ne regardaient QUE l'acteur. Or `#[IsGranted('ROLE_ADMIN')]` n'interdit rien à
 * propos de la CIBLE : tout agent pouvait poser un mot de passe sur le compte
 * d'un super-administrateur, puis se connecter avec. Le garde `$canGrantSuper`
 * de CollaborateurController ne protégeait que le champ de RÔLE ; le champ de
 * mot de passe, lui, passait. Et `UtilisateurController` — l'écran des comptes
 * CLIENTS — acceptait l'identifiant de n'importe quel utilisateur, agents
 * compris : sa LISTE était filtrée (`paginateRegularUsers`), sa route ne l'était
 * pas. Sa suppression contournait au passage le garde-fou « dernier
 * super-administrateur », qui n'existait que dans l'autre contrôleur.
 *
 * ── CE QU'ELLE N'INTERDIT PAS, ET POURQUOI ──────────────────────────────────
 * Un agent non super-administrateur garde le droit d'éditer un agent ORDINAIRE.
 * C'est exactement le métier du département RH — seul lui et la Direction
 * atteignent `console.collaborateur.*` — et le lui retirer viderait sa rubrique.
 * La marche qu'on ferme est celle qui mène à la RACINE : agent → super-admin.
 *
 * ── UN SEUL ENDROIT ─────────────────────────────────────────────────────────
 * La règle du « dernier super-administrateur » vivait en un exemplaire, dans
 * CollaborateurController. La recopier dans le second écran aurait fait deux
 * copies d'une règle d'accès — précisément ce que ce projet refuse ailleurs pour
 * les règles d'argent. Elle est donc ici, et les deux écrans l'appellent.
 */
final class GardeDesComptes
{
    public function __construct(
        private readonly UtilisateurRepository $utilisateurRepository,
    ) {
    }

    /**
     * L'acteur peut-il modifier ou supprimer ce compte d'AGENT ?
     *
     * Un compte de super-administrateur ne se touche que par un
     * super-administrateur. Le reste de la règle (qui atteint l'écran) est déjà
     * porté par le département.
     */
    public function peutAgirSurAgent(Utilisateur $cible, bool $acteurEstSuperAdmin): bool
    {
        return $acteurEstSuperAdmin || !self::estSuperAdmin($cible);
    }

    /**
     * Ce compte est-il le DERNIER super-administrateur de la plateforme ?
     *
     * Le supprimer fermerait à vie les écrans qui n'existent que pour lui — plan
     * tarifaire, fournisseurs de Ket, paramètres CRM —, et personne ne pourrait
     * plus en nommer un autre : l'attribution d'un rôle ne se fait que depuis
     * l'écran des collaborateurs, lui-même réservé au super-administrateur.
     * (La phrase évite volontairement d'écrire le nom du mutateur de rôles :
     * AccesReserveAuxAgentsTest le cherche TEXTUELLEMENT dans tout `src/` pour
     * recenser les endroits qui en posent un, et un commentaire suffirait à
     * faire passer ce fichier pour l'un d'eux.)
     */
    public function estLeDernierSuperAdmin(Utilisateur $cible): bool
    {
        if (!self::estSuperAdmin($cible)) {
            return false;
        }

        $superAdmins = array_filter(
            $this->utilisateurRepository->findAgents(),
            static fn (Utilisateur $u): bool => self::estSuperAdmin($u),
        );

        return \count($superAdmins) <= 1;
    }

    public static function estSuperAdmin(Utilisateur $utilisateur): bool
    {
        return \in_array('ROLE_SUPER_ADMIN', $utilisateur->getRoles(), true);
    }
}
