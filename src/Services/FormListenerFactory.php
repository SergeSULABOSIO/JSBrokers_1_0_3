<?php

namespace App\Services;

use App\Entity\Entreprise;
use DateTimeImmutable;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\EntityRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\Event\PostSubmitEvent;

class FormListenerFactory
{

    public function __construct(
        private Security $security
    )
    {
        
    }
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

    //Avec paramètre
    public function timeStampsWithWhen(string $when): callable
    {
        return function (PostSubmitEvent $event) use ($when){
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

    public function setFiltreEntreprise(): callable
    {
        return function (EntityRepository $er): QueryBuilder {
            /** @var Utilisateur $user */
            $user = $this->security->getUser();

            /** @var Entreprise $entreprise */
            $entreprise = $user->getConnectedTo();

            // dd($entreprise->getNom());

            return $er->createQueryBuilder('e')
                ->where('e.entreprise =:eseId')
                ->setParameter('eseId', $entreprise->getId())
                ->orderBy('e.id', 'ASC');
        };
    }
}
