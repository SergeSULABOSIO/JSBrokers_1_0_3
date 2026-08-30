<?php

namespace App\EventListener;

use App\Entity\Piste;
use App\Services\ReconductionPartageService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * LE PARTAGE SUIT LA POLICE, QUEL QUE SOIT LE CHEMIN QUI ÉCRIT SA SUITE.
 *
 * ── CE QUI MANQUAIT ─────────────────────────────────────────────────────────────────
 * La reconduction n'existait que là où quelqu'un pensait à l'appeler. Un plan d'écriture
 * générique de l'assistant qui pose `avenantDeBase` par une autre porte ne reconduisait
 * rien — et le partage d'une police disparaissait au renouvellement, sans un mot.
 *
 * ── DEUX GESTES, ET L'ÉTAT DE LA PISTE DÉCIDE LEQUEL ────────────────────────────────
 *   1. La piste dérivée n'a AUCUNE condition → on reconduit tout le schéma.
 *   2. Elle en a → elles viennent d'un plan de l'assistant, qui les écrit avec leur
 *      critère mais SANS leurs risques : `produits` est `mapped: false` et ne passe pas
 *      par un formulaire. On complète alors le seul ciblage, sans rien créer
 *      ({@see ReconductionPartageService::completerLeCiblage()}).
 *
 * Le second geste est ce qui rend la parité vraie plutôt que promise : sans lui, une
 * condition ciblée « Incendie » reconduite par Ket arrivait avec un ciblage VIDE — inerte
 * si elle incluait, universelle si elle excluait. Dans un cas la rétrocommission
 * disparaissait, dans l'autre elle s'élargissait, et les deux en silence.
 *
 * ── ⚠ POURQUOI À LA FIN DE LA REQUÊTE, ET NON À LA FIN DU FLUSH ─────────────────────
 * C'est la leçon d'un doublon observé, pas une précaution théorique. Le plan de
 * l'assistant n'écrit PAS tout d'un bloc : {@see \App\Service\Workspace\WorkspaceMutationService}
 * enregistre la piste seule (`commitWrite`), puis chacun de ses éléments de collection,
 * chacun avec son propre flush. Sur `postFlush`, l'abonné voyait donc une piste dérivée
 * encore nue, reconduisait ses deux conditions — et le plan ajoutait ensuite les siennes.
 * Quatre conditions au lieu de deux, et une rétrocommission payée deux fois.
 *
 * La question « cette piste a-t-elle déjà son partage ? » n'a de réponse juste qu'une fois
 * TOUS les écrivains passés. C'est ce que la fin de la requête garantit, et rien d'autre.
 * Les deux terminaisons sont écoutées — HTTP et ligne de commande — parce qu'une piste
 * s'écrit aussi depuis un import.
 *
 * ── CE QUI RESTE APPELÉ EXPLICITEMENT, ET POURQUOI ──────────────────────────────────
 * L'import de bordereau ({@see \App\Services\AvenantActionService}) garde son appel : la
 * piste qu'il crée n'a PAS d'avenant de base, et sa police de rattachement se retrouve par
 * une tout autre règle — même client, même risque, exercice précédent. Cet abonné ne
 * saurait pas la désigner. Le formulaire de renouvellement, lui, n'appelle plus rien.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
#[AsEventListener(event: KernelEvents::TERMINATE, method: "onKernelTerminate")]
#[AsEventListener(event: ConsoleEvents::TERMINATE, method: "onConsoleTerminate")]
final class ReconductionPartageListener
{
    /** @var array<int, Piste> Les pistes dérivées vues pendant le flush en cours. */
    private array $candidats = [];

    /** @var array<int, int> Leurs identifiants, une fois l'écriture faite. */
    private array $aExaminer = [];

    /** Le verrou de ré-entrance : notre propre flush ne doit pas se remettre en file. */
    private bool $enCours = false;

    public function __construct(
        private readonly ReconductionPartageService $reconduction,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        if ($this->enCours) {
            return;
        }

        $uow = $args->getObjectManager()->getUnitOfWork();

        // LES MODIFICATIONS COMPTENT AUTANT QUE LES CRÉATIONS : une piste reçoit souvent son
        // avenant de base dans un second temps — c'est le cas du formulaire de
        // renouvellement, qui persiste la piste avant de la relier.
        foreach ([...$uow->getScheduledEntityInsertions(), ...$uow->getScheduledEntityUpdates()] as $entite) {
            if ($entite instanceof Piste && $entite->getAvenantDeBase() !== null) {
                $this->candidats[spl_object_id($entite)] = $entite;
            }
        }
    }

    /**
     * On ne retient que des IDENTIFIANTS, jamais les objets.
     *
     * Entre ce flush et la fin de la requête, un `clear()` peut détacher l'entité — l'un
     * des chemins d'écriture en fait un, et travailler sur un objet détaché n'écrirait rien
     * tout en le laissant croire. L'identifiant, lui, se relit toujours.
     */
    public function postFlush(PostFlushEventArgs $args): void
    {
        foreach ($this->candidats as $piste) {
            $id = $piste->getId();
            if ($id !== null) {
                $this->aExaminer[$id] = $id;
            }
        }

        $this->candidats = [];
    }

    /** Fin de requête HTTP — le cas courant : formulaire de renouvellement et assistant. */
    public function onKernelTerminate(): void
    {
        $this->reconduireLesEnAttente();
    }

    /** Fin de commande — les imports et les rattrapages écrivent aussi des pistes. */
    public function onConsoleTerminate(): void
    {
        $this->reconduireLesEnAttente();
    }

    /**
     * TOUS LES ÉCRIVAINS SONT PASSÉS : on peut enfin trancher.
     *
     * Publique pour être exerçable sans requête HTTP — un test qui pilote directement le
     * moteur de mutation n'a pas de terminaison de noyau à attendre.
     */
    public function reconduireLesEnAttente(): void
    {
        if ($this->enCours || $this->aExaminer === []) {
            return;
        }

        $ids = $this->aExaminer;
        // Vidée AVANT de travailler : un échec ne doit pas laisser une file qui se
        // rejouerait plus tard sur une transaction qui n'a rien à voir.
        $this->aExaminer = [];

        // Le gestionnaire est fermé quand une écriture a échoué plus tôt : insister
        // relancerait l'erreur, en la déplaçant loin de sa cause.
        if (!$this->em->isOpen()) {
            return;
        }

        $aEcrire = false;

        $this->enCours = true;
        try {
            foreach ($ids as $id) {
                $piste = $this->em->find(Piste::class, $id);
                if ($piste instanceof Piste) {
                    $aEcrire = $this->reconduireOuCompleter($piste) || $aEcrire;
                }
            }

            // Un flush SEULEMENT s'il reste quelque chose à écrire : le cas courant est une
            // piste qui porte déjà son partage, et il ne doit rien coûter.
            if ($aEcrire) {
                $this->em->flush();
            }
        } finally {
            $this->enCours = false;
        }
    }

    /** @return bool vrai si la piste a reçu quelque chose qu'il reste à écrire */
    private function reconduireOuCompleter(Piste $cible): bool
    {
        $source = $cible->getAvenantDeBase()?->getCotation()?->getPiste();
        // Une piste qui serait sa propre source ne peut rien s'apporter — et se reconduire
        // elle-même doublerait ses conditions.
        if ($source === null || $source === $cible) {
            return false;
        }

        $entreprise = $cible->getEntreprise();
        if ($entreprise === null) {
            return false;
        }

        if (!$this->reconduction->porteDejaUnPartage($cible)) {
            $this->reconduction->reconduire($source, $cible, $entreprise, $cible->getInvite());

            return true;
        }

        return $this->reconduction->completerLeCiblage($source, $cible);
    }
}
