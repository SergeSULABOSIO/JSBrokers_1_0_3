<?php

namespace App\Service\Workspace;

use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Utilisateur;
use App\Repository\InviteRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Service\ResetInterface;

/**
 * QUI LIT ? — l'invité au nom duquel une lecture s'exécute, pour les frontières par personne
 * (TacheVisibiliteScope). Décisions de Serge du 2026-10-21 : la sécurité des données prime.
 *
 *   — un CONTEXTE SYSTÈME explicite et nommé (`enContexteSysteme`) : `false`, aucune
 *     frontière. Réservé à la ligne de commande et au worker ; il REFUSE de s'ouvrir pendant
 *     une requête HTTP ;
 *   — un invité IMPOSÉ (Ket dans son worker : IdentiteDuTraitement::endosser) : lui ;
 *   — l'utilisateur connecté, par SA fiche d'invitation dans le cabinet lu ;
 *   — le PROPRIÉTAIRE du cabinet sans fiche d'invitation (cas qu'aucun chemin de création ne
 *     produit, mais qui ne doit pas le priver de ses droits) : administrateur ;
 *   — tout autre cas (anonyme, connecté sans invitation ici) : `null` — AUCUNE tâche. Ce
 *     n'est pas un membre du cabinet. Rien n'ouvre la frontière par défaut.
 */
final class InviteCourant implements ResetInterface
{
    private const SYSTEME = 'systeme';

    /** @var list<Invite|string> pile : un traitement imbriqué rend la main à celui qui l'enveloppe */
    private array $pile = [];

    /**
     * La fiche du connecté, par « utilisateur#cabinet » (identifiant, ou 0 : aucune, ou -1 :
     * propriétaire sans fiche) : UNE requête par lecture, pas une par élément filtré. Le
     * cercle, lui, se relit sur l'entité à chaque fois — rien n'est figé au-delà de la
     * requête (reset() entre deux requêtes ou deux messages du worker).
     *
     * @var array<string, int>
     */
    private array $memo = [];

    public function __construct(
        private readonly Security $security,
        private readonly InviteRepository $invites,
        private readonly RequestStack $requetes,
    ) {
    }

    /** Hors session : agir au nom de cet invité, jusqu'au `relacher()` correspondant. */
    public function imposer(Invite $invite): void
    {
        $this->pile[] = $invite;
    }

    public function relacher(): void
    {
        array_pop($this->pile);
    }

    /**
     * Exécute `$travail` SANS frontière, au titre d'un traitement système nommé (commande,
     * worker). Jamais pendant une requête HTTP : un export proposé à l'écran passe par la
     * règle de l'invité.
     *
     * @template T
     * @param callable(): T $travail
     * @return T
     */
    public function enContexteSysteme(string $motif, callable $travail): mixed
    {
        if (trim($motif) === '') {
            throw new \LogicException('Contexte système : un motif est obligatoire.');
        }
        // La ligne de commande seulement (le worker Messenger en est une) : pendant une requête
        // HTTP — et même après sa réponse (kernel.terminate, pile vide) —, jamais.
        if (\PHP_SAPI !== 'cli' || $this->requetes->getMainRequest() !== null) {
            throw new \LogicException(sprintf('Contexte système refusé pendant une requête HTTP (%s).', $motif));
        }
        $this->pile[] = self::SYSTEME;
        try {
            return $travail();
        } finally {
            array_pop($this->pile);
        }
    }

    /** @return Invite|null|false l'invité ; null : aucune tâche ; false : contexte système */
    public function pour(Entreprise $entreprise): Invite|null|false
    {
        $sommet = $this->pile[\count($this->pile) - 1] ?? null;
        if ($sommet === self::SYSTEME) {
            return false;
        }
        if ($sommet instanceof Invite) {
            return $sommet->getEntreprise()?->getId() === $entreprise->getId() ? $sommet : null;
        }
        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            return null;
        }
        $cle = $utilisateur->getId() . '#' . $entreprise->getId();
        if (!isset($this->memo[$cle])) {
            $invite = $this->invites->findOneBy(['utilisateur' => $utilisateur, 'entreprise' => $entreprise]);
            $this->memo[$cle] = match (true) {
                $invite?->getId() !== null => (int) $invite->getId(),
                $utilisateur->getId() !== null && $entreprise->getUtilisateur()?->getId() === $utilisateur->getId() => -1,
                default => 0,
            };
        }

        return match ($this->memo[$cle]) {
            0 => null,
            // Le propriétaire reste administrateur, même sans fiche : une fiche de lecture,
            // jamais persistée (aucune collection inverse ne la porte), qui porte sa qualité.
            -1 => (new Invite())->setEntreprise($entreprise)->setUtilisateur($utilisateur)->setProprietaire(true),
            default => $this->invites->find($this->memo[$cle]),
        };
    }

    /** Entre deux requêtes, deux messages du worker : plus rien d'imposé, plus rien de retenu. */
    public function reset(): void
    {
        $this->pile = [];
        $this->memo = [];
    }
}
