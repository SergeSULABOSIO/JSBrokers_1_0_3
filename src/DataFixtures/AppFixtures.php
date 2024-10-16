<?php

namespace App\DataFixtures;

use App\Entity\Utilisateur;
use DateTimeImmutable;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AppFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        
        $manager->persist($user);
        $manager->flush();
    }
}
