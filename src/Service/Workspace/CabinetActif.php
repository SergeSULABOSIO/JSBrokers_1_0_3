<?php

namespace App\Service\Workspace;

use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Utilisateur;
use App\Repository\InviteRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\Service\ResetInterface;

/**
 * LE CABINET OUVERT, ET LA SEULE AUTORITÉ QUI LE DIT.
 *
 * ── CE QUI MANQUAIT ─────────────────────────────────────────────────────────────
 * Tout le cloisonnement du produit repose sur `Utilisateur::$connectedTo`. Or cette
 * colonne était lue partout et vérifiée nulle part : personne ne s'assurait qu'une
 * `Invite` existait encore pour le couple (utilisateur courant, cabinet ouvert).
 *
 * Deux conséquences mesurées avant ce service :
 *
 *  1. RÉVOQUER NE FERMAIT RIEN. `Invite` n'a aucun état d'acceptation ni de
 *     révocation — révoquer, c'est SUPPRIMER la ligne. Aucun code ne remettait
 *     `connectedTo` à zéro au passage : ni listener, ni subscriber. Le cabinet restait
 *     donc ouvert pour un compte qui n'y avait plus aucun droit.
 *
 *  2. UN REPLI RATTRAPAIT L'ABSENCE. `ControllerUtilsTrait::getInvite()` retombait sur
 *     `findOneBy(['utilisateur' => $user])` quand aucun invité ne correspondait au
 *     cabinet ouvert : il rendait alors un invité ARBITRAIRE d'un autre cabinet. Le
 *     périmètre de rôles venait d'un cabinet pendant que `connectedTo` en désignait un
 *     autre, et les requêtes filtrées servaient le second.
 *
 * ── LA RÈGLE, EN UNE PHRASE ─────────────────────────────────────────────────────
 * Un cabinet n'est ouvert que si l'utilisateur courant y possède une `Invite`. Sinon
 * il n'y a pas de cabinet ouvert — et surtout, on n'en ouvre aucun autre à sa place.
 *
 * ── FAIL-CLOSED, ET SANS SECOND CHOIX ───────────────────────────────────────────
 * Quand le droit a disparu, on FERME : `connectedTo` passe à null et y reste. Le
 * repli d'autrefois partait d'une bonne intention — ne pas casser l'accès — mais il
 * répondait à « quel cabinet puis-je ouvrir ? » là où la question est « quel cabinet
 * est ouvert ? ». Ces deux questions n'ont la même réponse que par accident.
 *
 * `AUCUN_CABINET` vaut -1 : un identifiant qu'aucune ligne ne porte. Les filtres de
 * requête s'en servent pour rendre une liste VIDE plutôt que de laisser tomber la
 * clause — une absence de cabinet ne doit jamais élargir un périmètre.
 */
class CabinetActif implements ResetInterface
{
    /** Identifiant qu'aucune entreprise ne porte : un filtre dessus ne rend rien. */
    public const AUCUN_CABINET = -1;

    private bool $resolu = false;
    private ?Invite $invite = null;

    public function __construct(
        private Security $security,
        private EntityManagerInterface $em,
        private InviteRepository $inviteRepository,
    ) {
    }

    /**
     * L'invité de l'utilisateur courant DANS le cabinet ouvert, ou null.
     *
     * C'est le seul point du code autorisé à répondre à cette question, et il la pose
     * à la base : une Invite existe-t-elle pour ce couple ?
     */
    public function invite(): ?Invite
    {
        if ($this->resolu) {
            return $this->invite;
        }
        $this->resolu = true;

        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            return $this->invite = null;
        }

        $entreprise = $utilisateur->getConnectedTo();
        if ($entreprise === null) {
            return $this->invite = null;
        }

        $invite = $this->inviteRepository->findOneBy([
            'utilisateur' => $utilisateur,
            'entreprise' => $entreprise,
        ]);

        if ($invite === null) {
            $this->fermerLeCabinet($utilisateur);

            return $this->invite = null;
        }

        return $this->invite = $invite;
    }

    /**
     * FERME LE CABINET — SANS TOUCHER AU RESTE DE LA REQUÊTE.
     *
     * ⚠ Surtout pas de `flush()` général ici. Le gardien est interrogé au milieu de
     * n'importe quoi — y compris d'une soumission de formulaire dont la validation vient
     * d'échouer, avec des entités modifiées et volontairement NON enregistrées dans
     * l'unité de travail. Un `flush()` les écrirait toutes, et ce correctif de sécurité
     * deviendrait une corruption de données.
     *
     * On écrit donc la SEULE colonne concernée, par un UPDATE direct, puis on aligne
     * l'objet en mémoire pour que le reste de la requête lise la même chose que la base.
     */
    private function fermerLeCabinet(Utilisateur $utilisateur): void
    {
        $this->em->createQuery(
            'UPDATE App\\Entity\\Utilisateur u SET u.connectedTo = NULL WHERE u.id = :id',
        )->setParameter('id', $utilisateur->getId())->execute();

        $utilisateur->setConnectedTo(null);
    }

    /** Le cabinet ouvert, ou null s'il n'y en a pas. */
    public function entreprise(): ?Entreprise
    {
        return $this->invite()?->getEntreprise();
    }

    /** L'identifiant du cabinet ouvert, ou AUCUN_CABINET — jamais null, pour les filtres. */
    public function identifiant(): int
    {
        return $this->entreprise()?->getId() ?? self::AUCUN_CABINET;
    }

    /** Un cabinet est-il ouvert pour l'utilisateur courant ? */
    public function estOuvert(): bool
    {
        return $this->invite() !== null;
    }

    /**
     * Oublie la réponse mémorisée.
     *
     * À appeler après une bascule : la réponse est mémorisée pour la durée d'une
     * requête, et une bascule change précisément ce qu'elle mémorise. Sert aussi de
     * `reset()` entre deux requêtes d'un worker au long cours.
     */
    public function reset(): void
    {
        $this->resolu = false;
        $this->invite = null;
    }
}
