<?php

namespace App\DataFixtures;

use DateTimeImmutable;
use App\Entity\Utilisateur;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UtilisateurFixtures extends Fixture
{
    public const ADMIN = "admin";
    public const AUTRE_USER = "autreUser";

    public function __construct(
        private readonly UserPasswordHasherInterface $userPasswordHasher
    ) {}


    public function load(ObjectManager $manager): void
    {
        /** @var Utilisateur $admin */
        $admin = (new Utilisateur())
            ->setNom("Serge SULA")
            ->setRoles(["ROLE_ADMIN"])
            ->setCreatedAt(new DateTimeImmutable("now"))
            ->setUpdatedAt(new DateTimeImmutable("now"))
            ->setVerified(true)
            ->setPassword($this->userPasswordHasher->hashPassword($admin, "admin"))
            ->setEmail("admin@js-brokers.com");
        $manager->persist($admin);
        $this->addReference(self::ADMIN, $admin);

        /** @var Utilisateur $autreUser */
        $autreUser = (new Utilisateur())
            ->setNom("Jean DODO")
            // ->setRoles(["ROLE_USER"]) //Ce rôle est déjà par défaut attribué à toutes les entités Utilisateurs
            ->setCreatedAt(new DateTimeImmutable("now"))
            ->setUpdatedAt(new DateTimeImmutable("now"))
            ->setVerified(true)
            ->setPassword($this->userPasswordHasher->hashPassword($autreUser, "user"))
            ->setEmail("user@gmail.com");
        $manager->persist($autreUser);
        $this->addReference(self::AUTRE_USER, $autreUser);

        $manager->flush();
    }
}
