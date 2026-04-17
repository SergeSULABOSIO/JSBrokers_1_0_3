<?php

namespace App\DataFixtures;

use App\Entity\Chargement;
use App\Entity\TypeRevenu;
use App\Entity\Article;
use App\Entity\Assureur;
use App\Entity\AutoriteFiscale;
use App\Entity\Avenant;
use App\Entity\Client;
use App\Entity\ConditionPartage;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\RevenuPourCourtier;
use App\Entity\Invite;
use App\Entity\Monnaie;
use App\Entity\Note;
use App\Entity\Paiement;
use App\Entity\Partenaire;
use App\Entity\Piste;
use App\Entity\Risque;
use App\Entity\ChargementPourPrime;
use App\Entity\Tranche;
use App\Entity\Taxe;
use App\Entity\Utilisateur;
use DateTimeImmutable;
use App\DataFixtures\UtilisateurFixtures;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Faker\Factory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AppFixtures extends Fixture implements DependentFixtureInterface
{
    private UserPasswordHasherInterface $passwordHasher;

    public function __construct(UserPasswordHasherInterface $passwordHasher)
    {
        $this->passwordHasher = $passwordHasher;
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
        $manager->persist($adminInvite);

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
        $manager->persist($victorInvite);

        $allInvites = [$adminInvite, $victorInvite];

        // 3. Création des Monnaies
        $usd = new Monnaie();
        $usd->setNom('Dollar Américain')->setCode('USD')->setTauxusd('1.00')->setLocale(false)->setFonction(Monnaie::FONCTION_SAISIE_ET_AFFICHAGE);
        $usd->setEntreprise($entreprise);
        $usd->setInvite($adminInvite);
        $manager->persist($usd);

        $cdf = new Monnaie();
        $cdf->setNom('Franc Congolais')->setCode('CDF')->setTauxusd('2250.00')->setLocale(true)->setFonction(Monnaie::FONCTION_SAISIE_UNIQUEMENT);
        $cdf->setEntreprise($entreprise);
        $cdf->setInvite($adminInvite);
        $manager->persist($cdf);

        // 4. Création des Taxes et Autorités Fiscales
        $taxeArca = new Taxe();
        $taxeArca->setCode('ARCA')->setDescription("Taxe régulateur")->setTauxIARD('2.00')->setTauxVIE('2.00')->setRedevable(Taxe::REDEVABLE_COURTIER);
        $taxeArca->setEntreprise($entreprise);
        $taxeArca->setInvite($adminInvite);
        $manager->persist($taxeArca);

        $autoriteArca = new AutoriteFiscale();
        $autoriteArca->setNom('Autorité de régulation et de contrôle des assurances en RDC')->setAbreviation('ARCA')->setTaxe($taxeArca);
        $autoriteArca->setEntreprise($entreprise);
        $autoriteArca->setInvite($adminInvite);
        $manager->persist($autoriteArca);

        $taxeTva = new Taxe();
        $taxeTva->setCode('TVA')->setDescription("Taxe sur la Valeur Ajoutée")->setTauxIARD('16.00')->setTauxVIE('0.00')->setRedevable(Taxe::REDEVABLE_ASSUREUR);
        $taxeTva->setEntreprise($entreprise);
        $taxeTva->setInvite($adminInvite);
        $manager->persist($taxeTva);

        $autoriteDgi = new AutoriteFiscale();
        $autoriteDgi->setNom('Direction Générale des Impôts')->setAbreviation('DGI')->setTaxe($taxeTva);
        $autoriteDgi->setEntreprise($entreprise);
        $autoriteDgi->setInvite($adminInvite);
        $manager->persist($autoriteDgi);

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

        // 7. Création des Risques
        $risques = [];
        $risquesData = [
            ['code' => '10A', 'nom' => 'Incendie et Risques Annexes', 'branche' => Risque::BRANCHE_IARD_OU_NON_VIE, 'commission' => 0.15],
            ['code' => '11B', 'nom' => 'Responsabilité Civile Automobile', 'branche' => Risque::BRANCHE_IARD_OU_NON_VIE, 'commission' => 0.10],
            ['code' => '13C', 'nom' => 'Assurance Maladie Groupe', 'branche' => Risque::BRANCHE_VIE, 'commission' => 0.12],
            ['code' => '05D', 'nom' => 'Transport de Marchandises', 'branche' => Risque::BRANCHE_IARD_OU_NON_VIE, 'commission' => 0.20],
            ['code' => '21A', 'nom' => 'Assurance Vie Individuelle', 'branche' => Risque::BRANCHE_VIE, 'commission' => 0.25],
            ['code' => '08E', 'nom' => 'Multirisque Habitation', 'branche' => Risque::BRANCHE_IARD_OU_NON_VIE, 'commission' => 0.18],
        ];
        foreach ($risquesData as $data) {
            $risque = new Risque();
            $risque->setCode($data['code'])
                ->setNomComplet($data['nom'])
                ->setDescription($faker->sentence)
                ->setBranche($data['branche'])
                ->setImposable(true)
                ->setPourcentageCommissionSpecifiqueHT($data['commission'])
                ->setEntreprise($entreprise)
                ->setInvite($adminInvite);
            $manager->persist($risque);
            $risques[] = $risque;
        }

        // 7.bis Création des Types de Chargement
        $chargements = [];
        $chargementPrimeNette = new Chargement();
        $chargementPrimeNette
            ->setNom("Prime nette")
            ->setFonction(Chargement::FONCTION_PRIME_NETTE)
            ->setDescription("La part de la prime destinée à couvrir le risque pur.");
        $chargementPrimeNette->setEntreprise($entreprise)->setInvite($adminInvite);
        $manager->persist($chargementPrimeNette);
        $chargements['prime_nette'] = $chargementPrimeNette;

        $chargementFronting = new Chargement();
        $chargementFronting
            ->setNom("Fronting")
            ->setFonction(Chargement::FONCTION_FRONTING)
            ->setDescription("Frais liés aux opérations de fronting.");
        $chargementFronting->setEntreprise($entreprise)->setInvite($adminInvite);
        $manager->persist($chargementFronting);
        $chargements['fronting'] = $chargementFronting;

        $chargementFrais = new Chargement();
        $chargementFrais
            ->setNom("Frais accessoires")
            ->setFonction(Chargement::FONCTION_FRAIS_ADMIN)
            ->setDescription("Frais de gestion, accessoires ou de police.");
        $chargementFrais->setEntreprise($entreprise)->setInvite($adminInvite);
        $manager->persist($chargementFrais);
        $chargements['frais'] = $chargementFrais;

        // 7.ter Création des Types de Revenu
        $typeRevenuCommOrdinaire = new TypeRevenu();
        $typeRevenuCommOrdinaire->setNom("Commission Ordinaire")->setAppliquerPourcentageDuRisque(true)->setRedevable(TypeRevenu::REDEVABLE_ASSUREUR)->setShared(true)->setTypeChargement($chargementPrimeNette);
        $typeRevenuCommOrdinaire->setMultipayments(true);
        $typeRevenuCommOrdinaire->setEntreprise($entreprise)->setInvite($adminInvite);
        $manager->persist($typeRevenuCommOrdinaire);

        $typeRevenuCommFronting = new TypeRevenu();
        $typeRevenuCommFronting->setNom("Commission sur Fronting")->setPourcentage(0.30)->setTypeChargement($chargementFronting)->setRedevable(TypeRevenu::REDEVABLE_ASSUREUR)->setShared(false);
        $typeRevenuCommFronting->setMultipayments(true);
        $typeRevenuCommFronting->setEntreprise($entreprise)->setInvite($adminInvite);
        $manager->persist($typeRevenuCommFronting);

        $typeRevenuConsultance = new TypeRevenu();
        $typeRevenuConsultance->setNom("Frais de consultance")->setPourcentage(0.05)->setTypeChargement($chargementPrimeNette)->setRedevable(TypeRevenu::REDEVABLE_CLIENT)->setShared(false);
        $typeRevenuConsultance->setMultipayments(false);
        $typeRevenuConsultance->setEntreprise($entreprise)->setInvite($adminInvite);
        $manager->persist($typeRevenuConsultance);

        $typeRevenuGestion = new TypeRevenu();
        $typeRevenuGestion->setNom("Honoraire de gestion")->setPourcentage(0.02)->setTypeChargement($chargementPrimeNette)->setRedevable(TypeRevenu::REDEVABLE_CLIENT)->setShared(false);
        $typeRevenuGestion->setMultipayments(true);
        $typeRevenuGestion->setEntreprise($entreprise)->setInvite($adminInvite);
        $manager->persist($typeRevenuGestion);

        $manager->flush();
    }
}
