<?php

namespace App\Controller\Console;

use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * @file Base commune aux contrôleurs de la Console JS Brokers.
 * @description Mutualise la bascule de langue persistante (?lang=) — même
 * comportement que l'espace utilisateur — pour rester DRY. L'EntityManager est
 * injecté par setter (#[Required]) afin que les sous-classes gardent un
 * constructeur libre pour leurs propres dépendances.
 */
abstract class AbstractConsoleController extends AbstractController
{
    protected EntityManagerInterface $em;

    #[Required]
    public function setEntityManager(EntityManagerInterface $em): void
    {
        $this->em = $em;
    }

    /**
     * Applique la préférence de langue portée par ?lang= : persiste sur le compte
     * de l'agent et bascule la locale du rendu courant.
     */
    protected function applyLangPreference(Request $request, LocaleSwitcher $localeSwitcher): void
    {
        /** @var Utilisateur|null $user */
        $user = $this->getUser();
        $lang = $request->query->get('lang');

        if ($user instanceof Utilisateur && in_array($lang, ['fr', 'en'], true) && $lang !== $user->getLocale()) {
            $user->setLocale($lang);
            $this->em->flush();
            $localeSwitcher->setLocale($lang);
        }
    }
}
