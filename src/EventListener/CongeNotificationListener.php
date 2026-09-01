<?php

namespace App\EventListener;

use App\Entity\HistoriqueDemande;
use App\Service\Conge\CongeMailer;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * UNE TRANSITION TRACÉE EST UNE TRANSITION NOTIFIÉE.
 *
 * ── POURQUOI ÉCOUTER LA TRACE, ET NON LES APPELANTS ─────────────────────────────────
 * Le circuit de validation des congés a deux portes : l'écran et l'assistant. Notifier
 * depuis chacune d'elles aurait voulu deux appels à tenir alignés, donc un endroit où
 * l'oublier — et un congé approuvé sans que personne n'en soit informé n'a aucun symptôme
 * visible avant que l'agent ne réclame sa réponse.
 *
 * Toute transition écrit une ligne d'HistoriqueDemande, sans exception. En écoutant la
 * naissance de cette ligne, on notifie donc TOUT ce qui se décide, quel que soit le
 * chemin — y compris un chemin qui n'existe pas encore.
 *
 * ── ⚠ POURQUOI À LA FIN DE LA REQUÊTE, ET NON À LA FIN DU FLUSH ─────────────────────
 * C'est la leçon déjà payée par ReconductionPartageListener : le plan de l'assistant
 * n'écrit PAS tout d'un bloc — WorkspaceMutationService enregistre l'entité, puis chacun
 * de ses éléments de collection, chacun avec son propre flush. Un mail parti sur
 * `postFlush` annoncerait une demande encore nue : sans son justificatif, parfois sans
 * son type d'absence. Le mail doit décrire ce qui a été VRAIMENT enregistré, donc partir
 * quand plus personne n'écrit.
 *
 * ── CET ABONNÉ N'ÉCRIT RIEN ─────────────────────────────────────────────────────────
 * Il lit et il envoie. Aucune ré-entrance à craindre, aucun flush supplémentaire — et,
 * surtout, aucune règle métier ici : elles vivent toutes dans DemandeCongeWorkflow. Un
 * abonné qui ARBITRE est un abonné qui casse la suite le jour où quelqu'un écrit par une
 * porte qu'il n'avait pas prévue.
 */
#[AsDoctrineListener(event: Events::postPersist)]
#[AsEventListener(event: KernelEvents::TERMINATE, method: 'onKernelTerminate')]
#[AsEventListener(event: ConsoleEvents::TERMINATE, method: 'onConsoleTerminate')]
final class CongeNotificationListener
{
    /** @var array<int, int> Identifiants des lignes d'historique nées pendant la requête. */
    private array $aNotifier = [];

    public function __construct(
        private readonly CongeMailer $mailer,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * ON NE RETIENT QUE DES IDENTIFIANTS, jamais les objets.
     *
     * Entre ce flush et la fin de la requête, un `clear()` peut détacher l'entité — le
     * moteur de mutation en fait un —, et lire un objet détaché donnerait des relations
     * vides sans le moindre avertissement.
     */
    public function postPersist(PostPersistEventArgs $args): void
    {
        $entite = $args->getObject();
        if (!$entite instanceof HistoriqueDemande) {
            return;
        }

        $id = $entite->getId();
        if ($id !== null) {
            $this->aNotifier[$id] = $id;
        }
    }

    /** Fin de requête HTTP — le cas courant : picker de décision et assistant. */
    public function onKernelTerminate(): void
    {
        $this->notifierLesEnAttente();
    }

    /** Fin de commande — un rattrapage ou un import peut aussi décider. */
    public function onConsoleTerminate(): void
    {
        $this->notifierLesEnAttente();
    }

    /**
     * Tous les écrivains sont passés : on peut enfin décrire ce qui existe.
     *
     * Publique pour être exerçable sans requête HTTP — un test qui pilote directement le
     * workflow n'a pas de terminaison de noyau à attendre.
     */
    public function notifierLesEnAttente(): void
    {
        if ($this->aNotifier === []) {
            return;
        }

        $ids = $this->aNotifier;
        // Vidée AVANT de travailler : un échec ne doit pas laisser une file qui se
        // rejouerait plus tard, sur une requête qui n'a rien à voir.
        $this->aNotifier = [];

        // Le gestionnaire est fermé quand une écriture a échoué plus tôt : insister
        // relancerait l'erreur en la déplaçant loin de sa cause.
        if (!$this->em->isOpen()) {
            return;
        }

        foreach ($ids as $id) {
            $historique = $this->em->find(HistoriqueDemande::class, $id);
            if ($historique instanceof HistoriqueDemande) {
                // Le mailer avale et journalise ses propres échecs : un SMTP injoignable
                // ne doit jamais remonter jusqu'ici.
                $this->mailer->notifier($historique);
            }
        }
    }
}
