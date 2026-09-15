<?php

namespace App\Supervision;

use App\Entity\ErreurApplicative;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @file Le pont entre le journal et la table de supervision.
 *
 * @description
 * Un handler Monolog plutôt qu'un écouteur de `kernel.exception`, pour une
 * raison précise : l'écouteur ne verrait que les exceptions NON RATTRAPÉES.
 * Or une bonne partie des défauts de ce projet sont rattrapés puis journalisés
 * — « Webhook paiement rejeté », « Impossible de charger la liste », les
 * `$logger->error()` du code métier. Ce sont de vrais défauts ; ils comptent.
 *
 * ── CE QUI EST ÉCARTÉ, ET POURQUOI ──────────────────────────────────────────
 * Les canaux de la messagerie. En production, l'envoi d'e-mail est SYNCHRONE
 * (voir `when@prod` de messenger.yaml) : un échec de transport est journalisé
 * pendant la requête. Sans cette exclusion, l'échec d'une alerte produirait un
 * enregistrement, qui produirait une alerte, qui échouerait à son tour.
 *
 * Le niveau minimal est ERROR : les avertissements ne sont pas des défauts, et
 * les compter noierait ce qui en est un.
 */
final class HandlerDeSupervision extends AbstractProcessingHandler
{
    /**
     * Canaux dont les enregistrements ne sont jamais comptés.
     *
     * « mailer » et « messenger » : anti-récursion, voir le commentaire de
     * classe. « deprecation » : ce sont des avertissements de version, pas des
     * pannes — ils ont leur propre journal et n'ont rien à faire dans une liste
     * de défauts à corriger.
     */
    private const CANAUX_EXCLUS = ['mailer', 'messenger', 'deprecation', 'assistant_tokens'];

    public function __construct(
        private readonly EnregistreurDErreurs $enregistreur,
        private readonly OrigineErreur $origine,
        private readonly RequestStack $requestStack,
        private readonly Security $security,
        Level|int|string $level = Level::Error,
        bool $bubble = true,
    ) {
        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        if (in_array($record->channel, self::CANAUX_EXCLUS, true)) {
            return;
        }

        $exception = $record->context['exception'] ?? null;

        [$type, $message, $fichier, $ligne, $trace] = $exception instanceof \Throwable
            ? $this->depuisException($exception)
            : $this->depuisMessage($record);

        $requete = $this->requestStack->getMainRequest();
        $utilisateur = $this->security->getUser();

        $this->enregistreur->enregistrer(
            cote: ErreurApplicative::COTE_SERVEUR,
            branche: $this->origine->depuisNomDeRoute($requete?->attributes->get('_route')),
            type: $type,
            message: $message,
            fichier: $fichier,
            ligne: $ligne,
            trace: $trace,
            contexte: [
                'url' => $requete?->getUri(),
                'utilisateur' => $utilisateur instanceof UserInterface ? $utilisateur->getUserIdentifier() : null,
                'cabinet' => $this->cabinetCourant($utilisateur),
            ],
        );
    }

    /**
     * @return array{0:string, 1:string, 2:?string, 3:?int, 4:?string}
     */
    private function depuisException(\Throwable $exception): array
    {
        return [
            $exception::class,
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine(),
            $exception->getTraceAsString(),
        ];
    }

    /**
     * Un enregistrement sans exception — un `$logger->error()` du code métier.
     *
     * Le LIEU vient alors du canal, faute de mieux : c'est moins précis qu'un
     * fichier et une ligne, mais cela suffit à distinguer deux défauts d'origines
     * différentes, et c'est le lieu qui fait l'empreinte.
     *
     * @return array{0:string, 1:string, 2:?string, 3:?int, 4:?string}
     */
    private function depuisMessage(LogRecord $record): array
    {
        return [
            'log:' . $record->channel,
            $record->message,
            null,
            null,
            null,
        ];
    }

    /**
     * Le cabinet sur lequel travaille l'utilisateur au moment de l'erreur.
     *
     * Enveloppé : on est déjà en train de traiter une panne, et
     * `getConnectedTo()` touche la base. Si elle est le problème, on renonce.
     */
    private function cabinetCourant(?UserInterface $utilisateur): ?string
    {
        if (!$utilisateur instanceof \App\Entity\Utilisateur) {
            return null;
        }

        try {
            return $utilisateur->getConnectedTo()?->getNom();
        } catch (\Throwable) {
            return null;
        }
    }
}
