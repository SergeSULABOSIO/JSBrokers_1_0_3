<?php

namespace App\EventListener;

use App\Entity\DemandeConge;
use App\Service\Conge\DemandeCongeWorkflow;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * LE COMPTEUR SUIT LA DEMANDE, QUEL QUE SOIT LE CHEMIN QUI ÉCRIT SON STATUT.
 *
 * ── CE QUI MANQUERAIT SANS LUI ──────────────────────────────────────────────────────
 * Le circuit de validation a deux portes. L'écran passe par DemandeCongeWorkflow, qui
 * écrit la ligne d'historique et le mouvement de compteur du même geste. L'assistant, lui,
 * n'y passe pas : un plan de mutation écrit la demande par le moteur générique, qui ne
 * connaît que des champs et ignore tout des conséquences d'un statut.
 *
 * Un congé approuvé via Ket n'aurait donc ni trace, ni mouvement, ni e-mail — et rien ne
 * le signalerait avant que quelqu'un ne s'aperçoive que son solde n'a pas bougé.
 *
 * ── L'ABONNÉ DÉRIVE, IL N'ARBITRE PAS ───────────────────────────────────────────────
 * Il ne décide RIEN. Il constate un écart entre le statut de la demande et son dernier
 * statut tracé, et demande au workflow de compléter — par les mêmes helpers que les
 * transitions ordinaires. Les règles (nul ne valide sa propre demande, motif obligatoire
 * après le début) restent dans le workflow, appelé par le contrôleur et par les outils.
 *
 * Un abonné qui ARBITRE casse la suite le jour où quelqu'un écrit par une porte qu'il
 * n'avait pas prévue : c'est l'incident payé par la condition d'office, dont la règle
 * a dû redescendre dans le formulaire après 965 tests rouges.
 *
 * ── ⚠ POURQUOI À LA FIN DE LA REQUÊTE, ET NON À LA FIN DU FLUSH ─────────────────────
 * Leçon de ReconductionPartageListener : le plan de l'assistant n'écrit PAS tout d'un
 * bloc — WorkspaceMutationService enregistre l'entité, puis chacun de ses éléments de
 * collection, chacun avec son propre flush. Sur `postFlush`, on verrait une demande encore
 * nue : sans son type d'absence, donc sans savoir si elle décompte, donc en écrivant un
 * mouvement qui n'aurait pas lieu d'être. La question « que faut-il tracer ? » n'a de
 * réponse juste qu'une fois tous les écrivains passés.
 *
 * ── PRIORITÉ SUR LA NOTIFICATION ────────────────────────────────────────────────────
 * Il s'exécute AVANT CongeNotificationListener (priorité 10 contre 0) : les lignes
 * d'historique qu'il fait naître doivent exister pour que le mail correspondant parte
 * dans la même requête, et non au prochain passage.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
#[AsEventListener(event: KernelEvents::TERMINATE, method: 'onKernelTerminate', priority: 10)]
#[AsEventListener(event: ConsoleEvents::TERMINATE, method: 'onConsoleTerminate', priority: 10)]
final class CongeTransitionListener
{
    /** @var array<int, DemandeConge> Les demandes vues pendant le flush en cours. */
    private array $candidats = [];

    /** @var array<int, int> Leurs identifiants, une fois l'écriture faite. */
    private array $aExaminer = [];

    /** Le verrou de ré-entrance : notre propre flush ne doit pas se remettre en file. */
    private bool $enCours = false;

    public function __construct(
        private readonly DemandeCongeWorkflow $workflow,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * On retient les créations ET les modifications.
     *
     * Une demande naît parfois directement soumise (l'assistant crée et soumet du même
     * geste), et change de statut à chaque décision. Ne surveiller que les modifications
     * laisserait la toute première transition sans trace.
     */
    public function onFlush(OnFlushEventArgs $args): void
    {
        if ($this->enCours) {
            return;
        }

        $uow = $args->getObjectManager()->getUnitOfWork();

        foreach ([...$uow->getScheduledEntityInsertions(), ...$uow->getScheduledEntityUpdates()] as $entite) {
            if ($entite instanceof DemandeConge) {
                $this->candidats[spl_object_id($entite)] = $entite;
            }
        }
    }

    /**
     * On ne retient que des IDENTIFIANTS, jamais les objets : entre ce flush et la fin de
     * la requête, un `clear()` peut détacher l'entité — le moteur de mutation en fait un —,
     * et travailler sur un objet détaché n'écrirait rien tout en le laissant croire.
     */
    public function postFlush(PostFlushEventArgs $args): void
    {
        foreach ($this->candidats as $demande) {
            $id = $demande->getId();
            if ($id !== null) {
                $this->aExaminer[$id] = $id;
            }
        }

        $this->candidats = [];
    }

    /** Fin de requête HTTP — le cas courant : picker de décision et assistant. */
    public function onKernelTerminate(): void
    {
        $this->completerLesEnAttente();
    }

    /** Fin de commande — un rattrapage ou un import peut aussi décider. */
    public function onConsoleTerminate(): void
    {
        $this->completerLesEnAttente();
    }

    /**
     * Tous les écrivains sont passés : on peut enfin compléter.
     *
     * Publique pour être exerçable sans requête HTTP — un test qui pilote directement le
     * moteur de mutation n'a pas de terminaison de noyau à attendre.
     */
    public function completerLesEnAttente(): void
    {
        if ($this->enCours || $this->aExaminer === []) {
            return;
        }

        $ids = $this->aExaminer;
        // Vidée AVANT de travailler : un échec ne doit pas laisser une file qui se
        // rejouerait plus tard, sur une requête qui n'a rien à voir.
        $this->aExaminer = [];

        // Le gestionnaire est fermé quand une écriture a échoué plus tôt : insister
        // relancerait l'erreur en la déplaçant loin de sa cause.
        if (!$this->em->isOpen()) {
            return;
        }

        $aEcrire = false;

        $this->enCours = true;
        try {
            foreach ($ids as $id) {
                $demande = $this->em->find(DemandeConge::class, $id);
                if ($demande instanceof DemandeConge) {
                    $aEcrire = $this->workflow->completerLaTrace($demande) || $aEcrire;
                }
            }

            // Un flush SEULEMENT s'il reste quelque chose à écrire : le cas courant est une
            // demande dont l'écran a déjà tout écrit, et il ne doit rien coûter.
            if ($aEcrire) {
                $this->em->flush();
            }
        } finally {
            $this->enCours = false;
        }
    }
}
