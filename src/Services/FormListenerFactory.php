<?php

namespace App\Services;

use App\Entity\Entreprise;
use App\Entity\Utilisateur;
use DateTimeImmutable;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\Event\PostSubmitEvent;

class FormListenerFactory
{

    public function __construct(
        private Security $security
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

    public function setFiltreEntreprise(): callable
    {
        return function (EntityRepository $er): QueryBuilder {
            $user = $this->security->getUser();

            // AUCUN UTILISATEUR AUTHENTIFIÉ : le cas existe pour de bon, et il ne doit
            // pas casser la CONSTRUCTION du formulaire. Une ligne de commande, un test
            // qui n'inspecte qu'un champ, FormTreeInspector qui monte un FormType pour
            // en lire l'arborescence : aucun n'a de session, et tous montaient jusqu'ici
            // sur une erreur fatale « getConnectedTo() on null » — d'autant plus
            // déroutante qu'elle survenait dans un champ SANS RAPPORT avec ce qu'on
            // regardait, embarqué par une collection imbriquée.
            //
            // Le repli est FAIL-CLOSED : l'entreprise -1 n'existe pas, la liste de choix
            // est donc VIDE. Sans identité, on ne propose rien — jamais tout.
            $entreprise = $user instanceof Utilisateur ? $user->getConnectedTo() : null;

            return $er->createQueryBuilder('e')
                ->where('e.entreprise =:eseId')
                ->setParameter('eseId', $entreprise?->getId() ?? -1)
                ->orderBy('e.id', 'ASC');
        };
    }
}
