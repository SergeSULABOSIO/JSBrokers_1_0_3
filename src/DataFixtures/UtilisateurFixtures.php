<?php

namespace App\DataFixtures;

use DateTimeImmutable;
use App\Entity\Utilisateur;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UtilisateurFixtures extends Fixture
{
    public function __construct(
        private readonly UserPasswordHasherInterface $userPasswordHasher
    ) {}


    public function load(ObjectManager $manager): void
    {
        /** @var Utilisateur $user */
        $user = (new Utilisateur())
            ->setNom("Serge SULA")
            ->setRoles(["ROLE_ADMIN"])
            ->setCreatedAt(new DateTimeImmutable("now"))
            ->setUpdatedAt(new DateTimeImmutable("now"))
            ->setVerified(true)
            ->setPassword($this->userPasswordHasher->hashPassword($user, "admin"))
            ->setEmail("ssula@js-brokers.com");
        $manager->persist($user);
        $manager->flush();
    }
}
