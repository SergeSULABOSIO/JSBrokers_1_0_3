<?php

namespace App\DataFixtures;

use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Utilisateur;
use DateTimeImmutable;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Faker\Factory;

class AppFixtures extends Fixture implements DependentFixtureInterface
{
    

    public function load(ObjectManager $manager): void
    {
        $faker = Factory::create('fr_FR');

        /** @var Utilisateur $admin */
        $admin = $this->getReference(UtilisateurFixtures::ADMIN);

        /** @var Utilisateur $autreUser */
        $autreUser = $this->getReference(UtilisateurFixtures::AUTRE_USER);

        //Création de l'entreprise
        $entreprise = (new Entreprise())
            ->setNom("Sté. " . $faker->company())
            ->setLicence($faker->randomNumber(6))
            ->setRccm("RCCM-" . $faker->randomNumber(8))
            ->setIdnat("IDNAT-" . $faker->randomNumber(8))
            ->setNumimpot("NUMIMP-" . $faker->randomNumber(8))
            ->setAdresse($faker->address())
            ->setTelephone($faker->phoneNumber())
            ->setUtilisateur($admin)
            ->setCreatedAt(new DateTimeImmutable("now"))
            ->setUpdatedAt(new DateTimeImmutable("now"));
        $manager->persist($entreprise);

        //Les 6 autres entreprises
        for ($i = 0; $i < 6; $i++) {
            $autreEntreprise = (new Entreprise())
                ->setNom("Sté. " . $faker->company())
                ->setLicence($faker->randomNumber(6))
                ->setRccm("RCCM-" . $faker->randomNumber(8))
                ->setIdnat("IDNAT-" . $faker->randomNumber(8))
                ->setNumimpot("NUMIMP-" . $faker->randomNumber(8))
                ->setAdresse($faker->address())
                ->setTelephone($faker->phoneNumber())
                ->setUtilisateur($admin)
                ->setCreatedAt(new DateTimeImmutable("now"))
                ->setUpdatedAt(new DateTimeImmutable("now"));
            $manager->persist($autreEntreprise);
        }

        //Création de l'invité à l'entreprise de l'admin
        $invite = (new Invite())
            ->setEmail($autreUser->getEmail())
            ->setUtilisateur($admin)
            ->addEntreprise($entreprise)
            ->setCreatedAt(new DateTimeImmutable("now"))
            ->setUpdatedAt(new DateTimeImmutable("now"));

        $manager->persist($invite);
        $manager->flush();
    }

    public function getDependencies()
    {
        return [
            UtilisateurFixtures::class
        ];
    }
}
