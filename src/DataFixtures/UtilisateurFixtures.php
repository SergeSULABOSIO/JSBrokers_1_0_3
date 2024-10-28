<?php

namespace App\DataFixtures;

use DateTimeImmutable;
use App\Entity\Utilisateur;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Faker\Factory;


class UtilisateurFixtures extends Fixture
{
    public const ADMIN = "admin";
    public const AUTRE_USER = "autreUser";

    public function __construct(
        private readonly UserPasswordHasherInterface $userPasswordHasher
    ) {}


    public function load(ObjectManager $manager): void
    {
        $faker = Factory::create('fr_FR');

        $admin = new Utilisateur();
        $admin->setNom("Serge SULA")
            ->setRoles(["ROLE_ADMIN"])
            ->setCreatedAt(new DateTimeImmutable("now"))
            ->setUpdatedAt(new DateTimeImmutable("now"))
            ->setLocale("fr")
            ->setVerified(true)
            ->setPassword($this->userPasswordHasher->hashPassword($admin, "admin"))
            ->setEmail("admin@js-brokers.com");
        $manager->persist($admin);
        $this->addReference(self::ADMIN, $admin);

        $autreUser = new Utilisateur();
        $autreUser->setNom($faker->name("Mr."))
            ->setRoles(["ROLE_ADMIN"])
            ->setCreatedAt(new DateTimeImmutable("now"))
            ->setUpdatedAt(new DateTimeImmutable("now"))
            ->setLocale("en")
            ->setVerified(true)
            ->setPassword($this->userPasswordHasher->hashPassword($autreUser, "user"))
            ->setEmail("user@js-brokers.com");
        $manager->persist($autreUser);
        $this->addReference(self::AUTRE_USER, $autreUser);

        $manager->flush();
    }
}
