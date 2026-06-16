<?php

namespace App\DataFixtures;

use App\Entity\Assureur;
use App\Entity\ConditionPartage;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Partenaire;
use App\Entity\Utilisateur;
use App\Services\ServiceInitialisationEntreprise;
use App\DataFixtures\UtilisateurFixtures;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Faker\Factory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AppFixtures extends Fixture implements DependentFixtureInterface
{
    private UserPasswordHasherInterface $passwordHasher;
    private ServiceInitialisationEntreprise $serviceInitialisation;

    public function __construct(
        UserPasswordHasherInterface $passwordHasher,
        ServiceInitialisationEntreprise $serviceInitialisation,
    ) {
        $this->passwordHasher = $passwordHasher;
        $this->serviceInitialisation = $serviceInitialisation;
    }

    public function getDependencies()
    {
        return [
            UtilisateurFixtures::class,
        ];
    }

    public function load(ObjectManager $manager): void
    {
        $faker = Factory::create('fr_FR');

        // 1. Création de l'entreprise et de l'admin
        $entreprise = new Entreprise();
        $entreprise->setNom('AIB RDC Sarl');
        $entreprise->setLicence($faker->swiftBicNumber());
        $entreprise->setAdresse($faker->address());
        $entreprise->setTelephone($faker->phoneNumber());
        $entreprise->setRccm($faker->numerify('RCCM/KIN/#####'));
        $entreprise->setIdnat($faker->numerify('IDNAT/#######'));
        $entreprise->setNumimpot($faker->numerify('IMPOT/#######'));
        $entreprise->setCapitalSociale($faker->randomFloat(2, 50000, 1000000));
        $entreprise->setSiteweb('www.aib-rdc.com');
        $entreprise->setPays(180); // Congo (RDC) → monnaie locale CDF dérivée par le service
        $manager->persist($entreprise);
        
        // On récupère l'admin user créé dans UtilisateurFixtures
        /** @var Utilisateur $adminUser */
        $adminUser = $this->getReference(UtilisateurFixtures::ADMIN_USER_REFERENCE);
        $adminUser->setConnectedTo($entreprise);
        $entreprise->setUtilisateur($adminUser); // Lier l'utilisateur créateur

        $adminInvite = new Invite();
        $adminInvite->setNom('Administrateur (Serge SULA)');
        $adminInvite->setUtilisateur($adminUser);
        $adminInvite->setEntreprise($entreprise);
        $adminInvite->setProprietaire(true);
        $entreprise->addInvite($adminInvite); // On l'ajoute simplement à l'entreprise

        // 2. Création de l'invité Victor ESAFE
        /** @var Utilisateur $inviteUser */
        $inviteUser = $this->getReference(UtilisateurFixtures::INVITE_USER_REFERENCE);
        $inviteUser->setConnectedTo($entreprise);

        $victorInvite = new Invite();
        $victorInvite->setNom('Victor ESAFE (Lecteur)');
        $victorInvite->setUtilisateur($inviteUser);
        $victorInvite->setEntreprise($entreprise);
        $victorInvite->setProprietaire(false);
        // TODO: Ajouter la logique des rôles pour la lecture seule
        $entreprise->addInvite($victorInvite); // On l'ajoute simplement à l'entreprise

        // Paramètres de configuration par défaut (monnaies, taxes + autorités,
        // chargements, types de revenu, risques) — source unique partagée avec la
        // création réelle d'entreprise (EntrepriseController::create).
        $this->serviceInitialisation->initialiser($entreprise, $adminInvite);

        // 5. Création des Assureurs
        $assureurNames = ['SFA CONGO', 'ACTIVA', 'ACTIVA LIFE', 'SUNU IARD RDC', 'MAYFAIR CONGO', 'RAWSUR SA', 'RAWSUR LIFE', 'AFRISSUR'];
        $assureurs = [];
        foreach ($assureurNames as $name) {
            $assureur = new Assureur();
            $assureur->setNom($name)
                ->setEmail(strtolower(str_replace(' ', '', $name)) . '@assureur.com')
                ->setTelephone($faker->phoneNumber())
                ->setAdressePhysique($faker->address())
                ->setRccm($faker->numerify('RCCM/KIN/#####'))
                ->setIdnat($faker->numerify('IDNAT/#######'))
                ->setNumimpot($faker->numerify('IMPOT/#######'))
                ->setEntreprise($entreprise)
                ->setInvite($adminInvite);
            $manager->persist($assureur);
            $assureurs[] = $assureur;
        }

        // 6. Création des Partenaires
        $partenairesData = [
            ['nom' => 'OLEA', 'part' => 0.35],
            ['nom' => 'MARSH SA', 'part' => 0.30, 'condition' => true],
            ['nom' => 'MARSH Portugal', 'part' => 0.30],
            ['nom' => 'AFINBRO', 'part' => 0.50],
            ['nom' => 'WPIB', 'part' => 0.30],
            ['nom' => 'NIRAJ', 'part' => 0.30],
            ['nom' => 'AGL', 'part' => 0.45],
            ['nom' => 'O\'NEILS', 'part' => 0.50],
            ['nom' => 'MONT BLANC', 'part' => 0.50],
            ['nom' => 'LOCKTON', 'part' => 0.30],
        ];
        $partenaires = [];
        foreach ($partenairesData as $data) {
            $partenaire = new Partenaire();
            $partenaire->setNom($data['nom'])
                ->setPart($data['part'])
                ->setEntreprise($entreprise)
                ->setInvite($adminInvite);

            if (isset($data['condition']) && $data['condition']) {
                $condition = new ConditionPartage();
                $condition->setNom("Condition spéciale MARSH SA")
                    ->setFormule(ConditionPartage::FORMULE_ASSIETTE_AU_MOINS_EGALE_AU_SEUIL)
                    ->setSeuil(2500)
                    ->setTaux($data['part'])
                    ->setCritereRisque(ConditionPartage::CRITERE_PAS_RISQUES_CIBLES)
                    ->setUniteMesure(ConditionPartage::UNITE_SOMME_COMMISSION_PURE_CLIENT)
                    ->setPartenaire($partenaire)
                    ->setEntreprise($entreprise)
                    ->setInvite($adminInvite);
                $partenaire->addConditionPartage($condition);
            }
            $manager->persist($partenaire);
            $partenaires[] = $partenaire;
        }

        $manager->flush();
    }
}
