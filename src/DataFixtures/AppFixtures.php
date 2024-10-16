<?php

namespace App\DataFixtures;

use App\Entity\Utilisateur;
use DateTimeImmutable;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AppFixtures extends Fixture
{
    public function __construct(
        private readonly UserPasswordHasherInterface $userPasswordHasher
    ) {}


    public function load(ObjectManager $manager): void
    {
        // $product = new Product();
        // $manager->persist($product);

        /** @var Utilisateur $user */
        $user = (new Utilisateur())
            ->setNom("Serge SULA")
            ->setRoles(["ROLE_ADMIN"])
            ->setCreatedAt(new DateTimeImmutable("now"))
            ->setUpdatedAt(new DateTimeImmutable("now"))
            ->setPassword($this->userPasswordHasher->hashPassword($user, "abcd"))
            ->setEmail("ssula@js-brokers.com");
        $manager->persist($user);
        $manager->flush();
    }
}
