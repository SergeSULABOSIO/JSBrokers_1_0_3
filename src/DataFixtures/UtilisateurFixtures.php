<?php

namespace App\DataFixtures;

use App\Entity\Utilisateur;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UtilisateurFixtures extends Fixture
{
    private UserPasswordHasherInterface $passwordHasher;

    public const ADMIN_USER_REFERENCE = 'admin-user';
    public const INVITE_USER_REFERENCE = 'invite-user';

    public function __construct(UserPasswordHasherInterface $passwordHasher)
    {
        $this->passwordHasher = $passwordHasher;
    }

    public function load(ObjectManager $manager): void
    {
        // Création de l'utilisateur admin
        $adminUser = new Utilisateur();
        $adminUser->setNom('Serge SULA BOSIO');
        $adminUser->setEmail('admin@js-brokers.com');
        $adminUser->setPassword($this->passwordHasher->hashPassword($adminUser, 'admin'));
        $adminUser->setRoles(['ROLE_ADMIN']);
        $adminUser->setVerified(true);
        $manager->persist($adminUser);

        // On ajoute une référence pour que d'autres fixtures puissent le retrouver
        $this->addReference(self::ADMIN_USER_REFERENCE, $adminUser);

        // Création de l'utilisateur invité
        $inviteUser = new Utilisateur();
        $inviteUser->setNom('Victor ESAFE');
        $inviteUser->setEmail('invite@js-brokers.com');
        $inviteUser->setPassword($this->passwordHasher->hashPassword($inviteUser, 'invite'));
        $inviteUser->setRoles(['ROLE_USER']); // Rôle de base
        $inviteUser->setVerified(true);
        $manager->persist($inviteUser);

        // On ajoute une référence pour cet utilisateur aussi
        $this->addReference(self::INVITE_USER_REFERENCE, $inviteUser);

        // $manager->flush(); // Retiré pour laisser la fixture principale gérer la transaction.
    }
}