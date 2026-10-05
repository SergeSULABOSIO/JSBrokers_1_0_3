<?php

namespace App\Service\Workspace;

use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Utilisateur;
use App\Repository\EntrepriseRepository;
use App\Repository\InviteRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * OUVRIR UN CABINET — LE SEUL ENDROIT QUI ÉCRIT `connectedTo`.
 *
 * ── CE QUI MANQUAIT ─────────────────────────────────────────────────────────────
 * Deux chemins écrivaient le cabinet actif, inégalement gardés, et tous deux
 * persistaient aussitôt :
 *
 *  1. `EntrepriseDashbordController::index()` — AUCUNE validation. L'identifiant venait
 *     de l'URL et partait en base, sans un seul contrôle. Un `GET` suffisait à faire
 *     basculer le cabinet actif de n'importe quel compte authentifié vers n'importe quel
 *     cabinet.
 *
 *  2. `EspaceDeTravailComponentController` — précédé de `validateWorkspaceAccess()`, qui
 *     vérifiait la cohérence invité ↔ entreprise mais JAMAIS l'appartenance de l'invité
 *     à l'utilisateur authentifié. Fournir le couple `(idInvite, idEntreprise)` de la
 *     victime — deux entiers séquentiels — passait le contrôle.
 *
 * Mesuré avant ce service : un invité du cabinet B prenait le cabinet A pour cabinet
 * actif par les DEUX chemins. Et le premier le faisait **en plantant** (HTTP 500) : le
 * `flush()` survenait tôt dans l'action, le plantage plus tard ne l'annulait pas. Juger
 * la réponse sans relire l'état aurait pris un accident pour une protection.
 *
 * ── LA RÈGLE ────────────────────────────────────────────────────────────────────
 * On n'ouvre un cabinet que sur présentation d'une `Invite` qui appartient À LA FOIS à
 * l'utilisateur courant ET à ce cabinet. Les deux appartenances sont vérifiées AVANT la
 * moindre écriture : un refus ne doit rien laisser derrière lui.
 *
 * C'est la clé de voûte du cloisonnement — `setFiltreEntreprise()` et tout ce qui lit
 * {@see CabinetActif} suivent `connectedTo`. Qui écrit cette colonne décide de ce que
 * l'utilisateur voit.
 */
class BasculeDeCabinet
{
    public function __construct(
        private Security $security,
        private EntityManagerInterface $em,
        private InviteRepository $inviteRepository,
        private EntrepriseRepository $entrepriseRepository,
        private CabinetActif $cabinetActif,
    ) {
    }

    /**
     * Ouvre ce cabinet pour l'utilisateur courant, et rend l'invité qui l'y autorise.
     *
     * @param int|null $idInvite l'invité présenté ; null pour « le mien dans ce cabinet »
     *
     * @throws AccessDeniedHttpException si l'invité n'est pas celui de l'utilisateur,
     *                                   ou s'il n'appartient pas à ce cabinet
     * @throws NotFoundHttpException     si le cabinet ou l'invité n'existe pas
     */
    public function ouvrir(?int $idInvite, int $idEntreprise): Invite
    {
        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            throw new AccessDeniedHttpException("Aucun utilisateur authentifié.");
        }

        $entreprise = $this->entrepriseRepository->find($idEntreprise)
            ?? throw new NotFoundHttpException("Ce cabinet n'existe pas.");

        $invite = $idInvite
            ? ($this->inviteRepository->find($idInvite)
                ?? throw new NotFoundHttpException("Cet invité n'existe pas."))
            : $this->inviteRepository->findOneBy([
                'utilisateur' => $utilisateur,
                'entreprise' => $entreprise,
            ]);

        // LES DEUX APPARTENANCES, ET DANS CET ORDRE — AVANT TOUTE ÉCRITURE.
        //
        // L'ancien contrôle ne posait que la seconde question. Un invité du cabinet de la
        // victime est parfaitement cohérent avec le cabinet de la victime : la cohérence
        // interne du couple ne dit rien de celui qui le présente.
        if ($invite === null || $invite->getUtilisateur() !== $utilisateur) {
            throw new AccessDeniedHttpException(
                "Cet invité n'est pas le vôtre : vous ne pouvez pas ouvrir ce cabinet.",
            );
        }
        if ($invite->getEntreprise()?->getId() !== $entreprise->getId()) {
            throw new AccessDeniedHttpException("Cet invité n'appartient pas à ce cabinet.");
        }

        $this->poser($utilisateur, $entreprise);

        return $invite;
    }

    /**
     * Écrit le cabinet actif — la SEULE colonne, et seulement si elle change.
     *
     * ⚠ Surtout pas de `flush()` général. L'ouverture peut survenir au milieu d'une
     * requête qui porte d'autres modifications volontairement non enregistrées ; les
     * écrire toutes ferait de ce correctif une corruption de données. Même raison, même
     * forme que {@see CabinetActif::fermerLeCabinet()}.
     */
    private function poser(Utilisateur $utilisateur, Entreprise $entreprise): void
    {
        if ($utilisateur->getConnectedTo()?->getId() !== $entreprise->getId()) {
            $this->em->createQuery(
                'UPDATE App\\Entity\\Utilisateur u SET u.connectedTo = :ese WHERE u.id = :id',
            )
                ->setParameter('ese', $entreprise->getId())
                ->setParameter('id', $utilisateur->getId())
                ->execute();

            $utilisateur->setConnectedTo($entreprise);
        }

        // Le gardien mémorise sa réponse pour la durée de la requête, et l'ouverture
        // change précisément ce qu'il mémorise.
        $this->cabinetActif->reset();
    }
}
