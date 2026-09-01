<?php

namespace App\Service\Conge;

use App\Entity\DemandeConge;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Repository\InviteRepository;
use App\Service\Workspace\WorkspaceAccessResolver;

/**
 * QUI PEUT QUOI SUR UNE DEMANDE DE CONGÉ.
 *
 * Source unique du contrôle par OBJET, appelée par le contrôleur, par les pickers de
 * décision et par les outils de l'assistant. Un droit retiré dans le référentiel prend
 * donc effet immédiatement sur les deux canaux, sans intervention côté assistant.
 *
 * ── PAS DE VOTER SYMFONY ────────────────────────────────────────────────────────────
 * Ce projet n'en a aucun, et son contrôle d'accès est déjà déclaratif
 * (WorkspaceAccessResolver + les scopes de recherche). Introduire ici un second régime
 * d'habilitation aurait donné deux endroits où lire la règle, donc un endroit où
 * l'oublier.
 *
 * ── LE VALIDEUR N'EST PAS STOCKÉ ────────────────────────────────────────────────────
 * Est valideur quiconque a le niveau MODIFICATION sur la rubrique « Congés » — décider
 * d'une demande, c'est la modifier. Le propriétaire l'est d'office, par le bypass du
 * resolver. Il n'y a donc aucune « liste des valideurs » à tenir à jour quelque part :
 * elle se règle dans le gestionnaire de rôles, comme tout le reste, et sans
 * redéploiement.
 */
class DemandeCongePolicy
{
    public function __construct(
        private readonly WorkspaceAccessResolver $accessResolver,
        private readonly InviteRepository $inviteRepository,
    ) {
    }

    /** Ce collaborateur peut-il décider des demandes du cabinet ? */
    public function estValideur(Invite $invite): bool
    {
        return $this->accessResolver->can($invite, 'DemandeConge', Invite::ACCESS_MODIFICATION);
    }

    /**
     * Tous les valideurs du cabinet, propriétaire compris.
     *
     * On interroge les invités de l'entreprise et on leur applique la même règle qu'à
     * l'écran : c'est plus coûteux qu'une colonne dédiée, mais c'est la seule forme qui
     * ne puisse pas se désynchroniser du gestionnaire de rôles.
     *
     * @return Invite[]
     */
    public function valideursDe(Entreprise $entreprise): array
    {
        $valideurs = [];
        foreach ($this->inviteRepository->findBy(['entreprise' => $entreprise]) as $invite) {
            if ($this->estValideur($invite)) {
                $valideurs[] = $invite;
            }
        }

        return $valideurs;
    }

    /**
     * Le demandeur est-il le SEUL valideur du cabinet ?
     *
     * Le cas se produit typiquement pour le propriétaire, qui n'a personne au-dessus de
     * lui : sa demande est alors enregistrée directement approuvée, et l'historique
     * comme le mail portent la mention « auto-approuvée ». Le taire ferait passer pour
     * une validation ordinaire ce qui n'en est pas une.
     */
    public function estSeulValideur(Invite $invite): bool
    {
        $entreprise = $invite->getEntreprise();
        if ($entreprise === null || !$this->estValideur($invite)) {
            return false;
        }

        $valideurs = $this->valideursDe($entreprise);

        return count($valideurs) === 1 && $valideurs[0]->getId() === $invite->getId();
    }

    /**
     * Peut-il consulter cette demande ?
     *
     * La sienne, toujours. Celles des autres, seulement s'il est valideur. Un
     * collaborateur qui forge l'URL de la demande d'un collègue est refusé — la liste
     * n'est pas la seule porte d'entrée, et masquer une ligne ne protège rien si la
     * fiche reste ouverte.
     */
    public function peutVoir(Invite $acteur, DemandeConge $demande): bool
    {
        if (!$this->memeCabinet($acteur, $demande)) {
            return false;
        }

        return $this->estLeSien($acteur, $demande) || $this->estValideur($acteur);
    }

    /**
     * Peut-il modifier le contenu de cette demande (dates, type, motif) ?
     *
     * Tant qu'elle est au brouillon, l'agent en est maître. Une fois soumise, elle
     * appartient au circuit : la retoucher pendant qu'un valideur la lit reviendrait à
     * lui faire signer autre chose que ce qu'il a vu.
     */
    public function peutModifier(Invite $acteur, DemandeConge $demande): bool
    {
        if (!$this->memeCabinet($acteur, $demande)) {
            return false;
        }

        if ($this->estValideur($acteur)) {
            return true;
        }

        return $this->estLeSien($acteur, $demande)
            && $demande->getStatut() === DemandeConge::STATUT_BROUILLON;
    }

    /**
     * Peut-il rendre la décision ?
     *
     * NUL NE VALIDE SA PROPRE DEMANDE (RG-01), y compris en passant par l'assistant.
     * L'auto-approbation du demandeur seul valideur est une règle de SOUMISSION, traitée
     * par DemandeCongeWorkflow : elle ne rouvre pas cette porte-ci.
     */
    public function peutDecider(Invite $acteur, DemandeConge $demande): bool
    {
        if (!$this->memeCabinet($acteur, $demande) || !$this->estValideur($acteur)) {
            return false;
        }

        if ($this->estLeSien($acteur, $demande)) {
            return false;
        }

        return $demande->getStatut() === DemandeConge::STATUT_SOUMISE;
    }

    /**
     * Peut-il annuler cette demande ?
     *
     * L'agent le peut lui-même tant que l'absence n'a pas commencé. Au-delà, seul un
     * valideur — et avec un motif obligatoire, exigé par le workflow : une absence déjà
     * entamée qu'on efface sans explication est une ligne que personne ne saura relire.
     */
    public function peutAnnuler(Invite $acteur, DemandeConge $demande, ?\DateTimeInterface $aLaDate = null): bool
    {
        if (!$this->memeCabinet($acteur, $demande)) {
            return false;
        }

        if (!in_array($demande->getStatut(), [DemandeConge::STATUT_SOUMISE, DemandeConge::STATUT_APPROUVEE], true)) {
            return false;
        }

        $aLaDate ??= new \DateTimeImmutable('today');

        if ($demande->aCommence($aLaDate)) {
            return $this->estValideur($acteur);
        }

        return $this->estLeSien($acteur, $demande) || $this->estValideur($acteur);
    }

    private function estLeSien(Invite $acteur, DemandeConge $demande): bool
    {
        $agent = $demande->getAgent();

        return $agent !== null && $agent->getId() === $acteur->getId();
    }

    /**
     * Le scoping entreprise est déjà assuré par le moteur de recherche et par
     * AuditableTrait ; on le revérifie ici parce que cette classe sert aussi des chemins
     * qui ne passent pas par eux (un identifiant forgé sur une route de décision).
     */
    private function memeCabinet(Invite $acteur, DemandeConge $demande): bool
    {
        $cabinetActeur = $acteur->getEntreprise();
        $cabinetDemande = $demande->getEntreprise();

        return $cabinetActeur !== null
            && $cabinetDemande !== null
            && $cabinetActeur->getId() === $cabinetDemande->getId();
    }
}
