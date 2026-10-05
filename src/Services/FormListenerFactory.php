<?php

namespace App\Services;

use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Partenaire;
use App\Entity\Utilisateur;
use App\Service\Partage\ConditionDOffice;
use DateTimeImmutable;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\Event\PostSubmitEvent;
use App\Service\Workspace\CabinetActif;

class FormListenerFactory
{

    public function __construct(
        private Security $security,
        private CabinetActif $cabinetActif,
    ) {}
    public function timeStamps(): callable
    {
        return function (PostSubmitEvent $event) {
            $data = $event->getData();
            $data->setUpdatedAt(new DateTimeImmutable('now'));
            if (!$data->getId()) {
                $data->setCreatedAt(new DateTimeImmutable('now'));
            }
        };
    }
    
    /**
     * Met à jour uniquement le champ 'updatedAt' d'une entité.
     * Idéal pour les entités où la date de création est gérée manuellement (ex: Bordereau.receivedAt).
     */
    public function updateTimestamp(): callable
    {
        return function (PostSubmitEvent $event) {
            $entity = $event->getData();

            // S'assure que l'entité existe et qu'elle a une méthode setUpdatedAt
            if ($entity && method_exists($entity, 'setUpdatedAt')) {
                $entity->setUpdatedAt(new DateTimeImmutable('now'));
            }
        };
    }

    //Avec paramètre
    public function timeStampsWithWhen(string $when): callable
    {
        return function (PostSubmitEvent $event) use ($when) {
            $data = $event->getData();
            $data->setUpdatedAt(new DateTimeImmutable($when));
            if (!$data->getId()) {
                $data->setCreatedAt(new DateTimeImmutable($when));
            }
        };
    }

    public function setUtilisateur(): callable
    {
        return function (PostSubmitEvent $event) {
            $data = $event->getData();
            $data->setUtilisateur($this->security->getUser());
        };
    }

    public function setFiltreUtilisateur(): callable
    {
        return function (EntityRepository $er): QueryBuilder {
            /** @var Utilisateur $user */
            $user = $this->security->getUser();
            return $er->createQueryBuilder('e')
                ->where('e.utilisateur =:userId')
                ->setParameter('userId', $user->getId())
                ->orderBy('e.id', 'ASC');
        };
    }

    public function getCurrentEntrepriseId(): int
    {
        /** @var Utilisateur $user */
        $user = $this->security->getUser();

        /** @var Entreprise $entreprise */
        $entreprise = $user->getConnectedTo();
        return $entreprise->getId();
    }

    public function getCurrentUtilisateurId(): int
    {
        /** @var Utilisateur $user */
        $user = $this->security->getUser();
        return $user->getId();
    }

    /**
     * LE FILTRE D'ENTREPRISE DE TOUS LES CHAMPS DE RELATION.
     *
     * Il ne lit plus `getConnectedTo()` directement : il demande au gardien
     * {@see CabinetActif}, seul habilite a dire quel cabinet est OUVERT. La difference
     * n'est pas cosmetique -- `connectedTo` pouvait designer un cabinet ou l'utilisateur
     * n'avait plus aucune Invite, et le filtre servait alors ses donnees quand meme.
     *
     * FAIL-CLOSED : sans cabinet ouvert, le gardien rend AUCUN_CABINET (-1), qu'aucune
     * ligne ne porte, donc la liste de choix est VIDE. Le cas d'un appelant sans session
     * existe pour de bon -- une ligne de commande, un test qui n'inspecte qu'un champ,
     * FormTreeInspector qui monte un FormType pour en lire l'arborescence : aucun n'a de
     * session, et tous doivent pouvoir CONSTRUIRE le formulaire sans rien proposer.
     */
    public function setFiltreEntreprise(): callable
    {
        return function (EntityRepository $er): QueryBuilder {
            return $er->createQueryBuilder('e')
                ->where('e.entreprise =:eseId')
                ->setParameter('eseId', $this->cabinetActif->identifiant())
                ->orderBy('e.id', 'ASC');
        };
    }

    /**
     * TOUT INTERMÉDIAIRE REPART DE SON FORMULAIRE AVEC UNE CONDITION DE PARTAGE.
     *
     * ── CE QUI MANQUAIT ─────────────────────────────────────────────────────────────
     * Un partenaire ou un agent fraîchement créé n'en avait aucune. Le partage d'un
     * partenaire fonctionnait quand même — sa « Part % » sert de taux tout en bas de la
     * cascade — mais il n'était pas RATTACHABLE : le geste qui dit « ces affaires-ci
     * relèvent de son accord » n'avait rien à désigner, et il fallait aller écrire une
     * condition dans une autre rubrique avant de pouvoir s'en servir.
     *
     * ── POURQUOI SUR LE FORMULAIRE, ET NON AU RAS DE DOCTRINE ───────────────────────
     * Un abonné `onFlush` aurait doté TOUT invité écrit, y compris ceux que le code crée
     * pour lui-même : la condition serait alors devenue une clé étrangère de plus à
     * dénouer avant de pouvoir supprimer un membre de l'espace de travail. Le formulaire
     * est le point où un intermédiaire est VOULU — et il est commun à l'écran et à
     * l'assistant, qui soumet les mêmes FormType : la parité est acquise sans second
     * chemin à tenir d'accord.
     *
     * Le cabinet est déjà posé sur l'entité à ce moment (cf. le trait CRUD, qui le
     * renseigne AVANT de soumettre) ; sans lui on s'abstient plutôt que d'écrire une
     * condition orpheline que la base refuserait, en emportant l'enregistrement entier.
     */
    public function conditionDOffice(): callable
    {
        return function (PostSubmitEvent $event) {
            $beneficiaire = $event->getData();
            if (!$beneficiaire instanceof Invite && !$beneficiaire instanceof Partenaire) {
                return;
            }
            if ($beneficiaire->getEntreprise() === null) {
                return;
            }

            $condition = ConditionDOffice::manquantePour($beneficiaire);
            if ($condition === null) {
                return;
            }

            // Le côté inverse porte le `cascade: persist` : c'est lui qui écrit la
            // condition, sans que le formulaire ait à connaître le gestionnaire d'entités.
            if ($beneficiaire instanceof Partenaire) {
                $beneficiaire->addConditionPartage($condition);
            } else {
                $beneficiaire->addConditionsPartageAgent($condition);
            }
        };
    }
}
