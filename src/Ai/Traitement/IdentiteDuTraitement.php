<?php

namespace App\Ai\Traitement;

use App\Entity\Entreprise;
use App\Entity\Invite;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * @file Poser l'identité de l'invité hors de toute session web.
 * @description Jeton de sécurité éphémère, le temps d'un traitement.
 *
 * POURQUOI C'EST INDISPENSABLE, ET POURQUOI L'OUBLI SERAIT SILENCIEUX. Le
 * traitement d'un message n'a besoin de rien de la requête HTTP — l'invité et
 * l'entreprise voyagent explicitement dans AiScope. Mais les services
 * PÉRIPHÉRIQUES qu'atteignent les outils IA, eux, lisent encore
 * Security::getUser() : les formulaires filtrent leurs relations sur l'espace de
 * travail connecté, ServiceTaxes retombe sur getConnectedTo() quand l'entreprise
 * ne lui est pas passée, et ServiceMonnaies::getMonnaies() renvoie un tableau
 * VIDE lorsqu'il n'y a pas d'utilisateur — sans lever, sans journaliser.
 *
 * C'est ce dernier cas qui commande cette classe. Un worker sans jeton ne
 * planterait pas : il ferait travailler Ket sur un cabinet réputé sans aucune
 * monnaie, et rendrait des montants faux avec le plus grand aplomb. Une panne
 * franche se voit ; celle-là, non.
 *
 * MÊME BESOIN, DEUX APPELANTS. AssistantSmokeCommand posait déjà ce jeton pour
 * la même raison. Il n'y a aucune raison que deux chemins hors-HTTP vers le même
 * pipeline s'y prennent chacun à leur façon.
 */
final class IdentiteDuTraitement
{
    /**
     * Le jeton qui était en place avant qu'on endosse celui de l'invité.
     *
     * ⚠️ CE N'EST PAS UNE PRÉCAUTION THÉORIQUE. Le traitement tourne dans un
     * worker quand ASSISTANT_ASYNC vaut 1, mais PENDANT LA REQUÊTE HTTP quand il
     * vaut 0 (transport `sync`) — et là, le stockage de jeton n'est pas à nous :
     * c'est celui de la session de l'utilisateur. Une première version relâchait
     * en posant `null` ; le firewall a écrit ce vide en session, et la requête
     * suivante repartait sur /login. Toute la suite fonctionnelle est tombée
     * d'un coup. On rend donc ce qu'on a emprunté.
     */
    private ?TokenInterface $jetonPrecedent = null;

    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Place l'utilisateur de l'invité dans l'espace de travail visé, comme le
     * ferait une session web.
     *
     * ⚠️ setConnectedTo() N'EST APPELÉ QUE SI LA VALEUR DIFFÈRE. En ligne de
     * commande, écrire ce champ était sans conséquence : rien n'était jamais
     * flushé. Le traitement d'un message, lui, flushe — il persisterait donc le
     * changement d'espace de travail, et l'utilisateur verrait son autre onglet
     * basculer tout seul. Quand l'écart existe vraiment, c'est un état
     * incohérent : on le journalise plutôt que de le corriger en silence.
     */
    public function endosser(Invite $invite, Entreprise $entreprise): void
    {
        $this->jetonPrecedent = $this->tokenStorage->getToken();

        $utilisateur = $invite->getUtilisateur();
        if ($utilisateur === null) {
            return;
        }

        if ($utilisateur->getConnectedTo() !== $entreprise) {
            $this->logger->warning("Assistant IA : l'espace de travail de l'utilisateur ne correspond pas à la conversation traitée.", [
                'invite'          => $invite->getId(),
                'entrepriseCible' => $entreprise->getId(),
                'entrepriseLue'   => $utilisateur->getConnectedTo()?->getId(),
            ]);
            $utilisateur->setConnectedTo($entreprise);
        }

        $this->tokenStorage->setToken(
            new UsernamePasswordToken($utilisateur, 'main', $utilisateur->getRoles())
        );
    }

    /**
     * Rend le stockage de jeton tel qu'on l'a trouvé.
     *
     * Dans un worker, il était vide : on le revide, et la tâche suivante —
     * peut-être d'un autre cabinet — ne peut pas hériter d'une identité qui
     * n'est pas la sienne. Dans une requête HTTP, il portait la session de
     * l'utilisateur : on la lui rend, et il reste connecté.
     */
    public function relacher(): void
    {
        $this->tokenStorage->setToken($this->jetonPrecedent);
        $this->jetonPrecedent = null;
    }
}
