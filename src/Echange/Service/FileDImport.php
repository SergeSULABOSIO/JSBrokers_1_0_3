<?php

namespace App\Echange\Service;

use App\Entity\EchangeImportRun;
use App\Message\AvancerUnPalierDImport;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * PAR OÙ UN IMPORT RÉCLAME QU'ON LE FASSE AVANCER.
 *
 * ── DEUX POUSSEURS, UN SEUL MOTEUR ──────────────────────────────────────────────────
 * Le travail lui-même est ailleurs, tout entier dans {@see AvanceurDImport}. Reste à
 * savoir QUI appelle, palier après palier — et la réponse dépend de ce que
 * l'installation sait faire :
 *
 *  - `IMPORT_ASYNC=1` : un worker. La confirmation rend la main aussitôt, le travail
 *    continue sans le navigateur, et l'utilisateur peut fermer son onglet. C'est le
 *    vrai arrière-plan.
 *  - `IMPORT_ASYNC=0` : le navigateur. Une requête par palier, chacune dans un processus
 *    PHP neuf — la mémoire est bornée de la même façon, mais l'onglet doit rester
 *    ouvert. Aucune infrastructure à installer.
 *
 * ⚠ LE DÉFAUT EST « 0 », ET CE N'EST PAS DE LA TIMIDITÉ. Sans worker supervisé, un
 * import basculé en asynchrone ne démarrerait jamais : il attendrait un réveil qui ne
 * vient pas, et l'écran afficherait « en cours » indéfiniment. On n'active l'asynchrone
 * qu'une fois le worker exploité — même règle, et même variable d'esprit, que
 * `ASSISTANT_ASYNC`.
 *
 * ⚠ ET RIEN N'EST DISPATCHÉ EN MODE SYNCHRONE. Estampiller l'enveloppe pour le transport
 * `sync`, comme le fait la file de l'assistant, exécuterait tous les paliers DANS la
 * requête — c'est-à-dire exactement la requête interminable et gourmande que le
 * découpage existe pour supprimer. Ici, le repli n'est pas « faire tout de suite »,
 * c'est « laisser le navigateur rappeler ».
 */
final class FileDImport
{
    public function __construct(
        private readonly MessageBusInterface $bus,
        #[Autowire('%env(bool:IMPORT_ASYNC)%')]
        private readonly bool $asynchrone,
    ) {
    }

    /**
     * L'écran doit-il rappeler lui-même, ou seulement regarder ?
     *
     * C'est la seule chose que le navigateur ait besoin de savoir : le reste — combien de
     * paliers, de quelle taille — ne le regarde pas.
     */
    public function estAsynchrone(): bool
    {
        return $this->asynchrone;
    }

    /**
     * Réclame un palier, si quelqu'un est là pour l'entendre.
     *
     * Sans worker, cette méthode ne fait rien : ce n'est pas un échec, c'est le mode de
     * fonctionnement annoncé. Le navigateur prendra le relais.
     */
    public function pousser(EchangeImportRun $run): void
    {
        if (!$this->asynchrone || $run->getId() === null) {
            return;
        }

        $this->bus->dispatch(new AvancerUnPalierDImport((int) $run->getId()));
    }
}
