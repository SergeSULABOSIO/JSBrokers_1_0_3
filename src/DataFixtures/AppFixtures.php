<?php

namespace App\DataFixtures;

use App\Constantes\Constantes;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Monnaie;
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
        //On charge les monnaies par défauts
        $this->setMonnaises($faker, $entreprise);
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
            //On charge les monnaies par défauts
            $this->setMonnaises($faker, $autreEntreprise);
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

    private function setMonnaises($faker, ?Entreprise $entreprise)
    {
        $tabNomsMonnaies = [];
        foreach (Constantes::TAB_MONNAIES as $nom => $code) {
            $tabNomsMonnaies[] = $nom;
        }
        $monnaies = array_map(
            fn(string $nom) => (new Monnaie())
                ->setNom($nom),
            $tabNomsMonnaies
        );

        foreach ($monnaies as $monnaie) {
            $monnaie->setCode(Constantes::TAB_MONNAIES[$monnaie->getNom()]);
            if ($monnaie->getCode() == 'USD') {
                $monnaie->setFonction(Constantes::TAB_MONNAIE_FONCTIONS[Constantes::FONCTION_SAISIE_ET_AFFICHAGE]);
                $monnaie->setLocale(true);
                $monnaie->setTauxusd(1);
            } else {
                $monnaie->setFonction(Constantes::TAB_MONNAIE_FONCTIONS[Constantes::FONCTION_AUCUNE]);
                $monnaie->setTauxusd($faker->randomFloat(2, 10));
                $monnaie->setLocale(false);
            }
            $entreprise->addMonnaie($monnaie);
        }
    }
}
