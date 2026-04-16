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
use App\Entity\Invite;
use App\Entity\Monnaie;
use App\Entity\Note;
use App\Entity\Paiement;
use App\Entity\Partenaire;
use App\Entity\Piste;
use App\Entity\Risque;
use App\Entity\Taxe;
use App\Entity\Utilisateur;
use DateTimeImmutable;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Faker\Factory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AppFixtures extends Fixture
{
    private UserPasswordHasherInterface $passwordHasher;

    public function __construct(UserPasswordHasherInterface $passwordHasher)
    {
        $this->passwordHasher = $passwordHasher;
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

        $adminUser = new Utilisateur();
        $adminUser->setNom('Serge SULA BOSIO');
        $adminUser->setEmail('admin@js-brokers.com');
        $adminUser->setPassword($this->passwordHasher->hashPassword($adminUser, 'admin'));
        $adminUser->setRoles(['ROLE_ADMIN']);
        $adminUser->setVerified(true);
        $adminUser->setConnectedTo($entreprise);
        $entreprise->setUtilisateur($adminUser); // Lier l'utilisateur créateur
        $manager->persist($adminUser);

        // Étape 1 : On flush pour que l'entreprise et l'utilisateur admin aient un ID.
        $manager->flush();

        $adminInvite = new Invite();
        $adminInvite->setNom('Administrateur (Serge SULA)');
        $adminInvite->setUtilisateur($adminUser);
        $adminInvite->setEntreprise($entreprise);
        $adminInvite->setProprietaire(true);
        // L'AuditableTrait s'occupera de createdAt, updatedAt.
        // On persiste l'invité une première fois pour qu'il ait un ID.
        $manager->persist($adminInvite);
        $manager->flush(); // Étape 2 : On flush pour que l'invité admin ait un ID.

        $adminInvite->setInvite($adminInvite); // Maintenant on peut lier l'invité à lui-même comme créateur.

        // 2. Création de l'invité Victor ESAFE
        $inviteUser = new Utilisateur();
        $inviteUser->setNom('Victor ESAFE');
        $inviteUser->setEmail('invite@js-brokers.com');
        $inviteUser->setPassword($this->passwordHasher->hashPassword($inviteUser, 'invite'));
        $inviteUser->setRoles(['ROLE_USER']); // Rôle de base
        $inviteUser->setVerified(true);
        $inviteUser->setConnectedTo($entreprise);
        $manager->persist($inviteUser);

        $victorInvite = new Invite();
        $victorInvite->setNom('Victor ESAFE (Lecteur)');
        $victorInvite->setUtilisateur($inviteUser);
        $victorInvite->setEntreprise($entreprise);
        $victorInvite->setProprietaire(false);
        $victorInvite->setInvite($adminInvite); // Créé par l'admin
        // TODO: Ajouter la logique des rôles pour la lecture seule
        $manager->persist($victorInvite);

        $allInvites = [$adminInvite, $victorInvite];

        // 3. Création des Monnaies
        $usd = new Monnaie();
        $usd->setNom('Dollar Américain')->setCode('USD')->setTauxusd('1.00')->setLocale(false)->setFonction(Monnaie::FONCTION_SAISIE_ET_AFFICHAGE)->setEntreprise($entreprise)->setInvite($adminInvite);
        $manager->persist($usd);

        $cdf = new Monnaie();
        $cdf->setNom('Franc Congolais')->setCode('CDF')->setTauxusd('2250.00')->setLocale(true)->setFonction(Monnaie::FONCTION_SAISIE_UNIQUEMENT)->setEntreprise($entreprise)->setInvite($adminInvite);
        $manager->persist($cdf);

        // 4. Création des Taxes et Autorités Fiscales
        $taxeArca = new Taxe();
        $taxeArca->setCode('ARCA')->setDescription("Taxe régulateur")->setTauxIARD('2.00')->setTauxVIE('2.00')->setRedevable(Taxe::REDEVABLE_COURTIER)->setEntreprise($entreprise)->setInvite($adminInvite);
        $manager->persist($taxeArca);

        $autoriteArca = new AutoriteFiscale();
        $autoriteArca->setNom('Autorité de régulation et de contrôle des assurances en RDC')->setAbreviation('ARCA')->setTaxe($taxeArca)->setEntreprise($entreprise)->setInvite($adminInvite);
        $manager->persist($autoriteArca);

        $taxeTva = new Taxe();
        $taxeTva->setCode('TVA')->setDescription("Taxe sur la Valeur Ajoutée")->setTauxIARD('16.00')->setTauxVIE('0.00')->setRedevable(Taxe::REDEVABLE_ASSUREUR)->setEntreprise($entreprise)->setInvite($adminInvite);
        $manager->persist($taxeTva);

        $autoriteDgi = new AutoriteFiscale();
        $autoriteDgi->setNom('Direction Générale des Impôts')->setAbreviation('DGI')->setTaxe($taxeTva)->setEntreprise($entreprise)->setInvite($adminInvite);
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
        $chargementPrimeNette->setNom("Prime nette")
            ->setFonction(Chargement::FONCTION_PRIME_NETTE)
            ->setDescription("La part de la prime destinée à couvrir le risque pur.")
            ->setEntreprise($entreprise)->setInvite($adminInvite);
        $manager->persist($chargementPrimeNette);
        $chargements['prime_nette'] = $chargementPrimeNette;

        $chargementFronting = new Chargement();
        $chargementFronting->setNom("Fronting")
            ->setFonction(Chargement::FONCTION_FRONTING)
            ->setDescription("Frais liés aux opérations de fronting.")
            ->setEntreprise($entreprise)->setInvite($adminInvite);
        $manager->persist($chargementFronting);
        $chargements['fronting'] = $chargementFronting;

        $chargementFrais = new Chargement();
        $chargementFrais->setNom("Frais accessoires")
            ->setFonction(Chargement::FONCTION_FRAIS_ADMIN)
            ->setDescription("Frais de gestion, accessoires ou de police.")
            ->setEntreprise($entreprise)->setInvite($adminInvite);
        $manager->persist($chargementFrais);
        $chargements['frais'] = $chargementFrais;

        // 7.ter Création des Types de Revenu
        $typeRevenuCommOrdinaire = new TypeRevenu();
        $typeRevenuCommOrdinaire->setNom("Commission Ordinaire")->setAppliquerPourcentageDuRisque(true)->setRedevable(TypeRevenu::REDEVABLE_ASSUREUR)->setShared(true)->setTypeChargement($chargementPrimeNette)->setEntreprise($entreprise)->setInvite($adminInvite);
        $manager->persist($typeRevenuCommOrdinaire);

        $typeRevenuCommFronting = new TypeRevenu();
        $typeRevenuCommFronting->setNom("Commission sur Fronting")->setPourcentage(0.30)->setTypeChargement($chargementFronting)->setRedevable(TypeRevenu::REDEVABLE_ASSUREUR)->setShared(false)->setEntreprise($entreprise)->setInvite($adminInvite);
        $manager->persist($typeRevenuCommFronting);

        $typeRevenuConsultance = new TypeRevenu();
        $typeRevenuConsultance->setNom("Frais de consultance")->setPourcentage(0.05)->setTypeChargement($chargementPrimeNette)->setRedevable(TypeRevenu::REDEVABLE_CLIENT)->setShared(false)->setEntreprise($entreprise)->setInvite($adminInvite);
        $manager->persist($typeRevenuConsultance);

        $typeRevenuGestion = new TypeRevenu();
        $typeRevenuGestion->setNom("Honoraire de gestion")->setPourcentage(0.02)->setTypeChargement($chargementPrimeNette)->setRedevable(TypeRevenu::REDEVABLE_CLIENT)->setShared(false)->setEntreprise($entreprise)->setInvite($adminInvite);
        $manager->persist($typeRevenuGestion);


        // 8. Génération des données opérationnelles (Clients, Pistes, Cotations, Avenants)
        $clients = [];
        for ($i = 0; $i < 50; $i++) {
            $client = new Client();
            $client->setNom($faker->company)
                ->setEmail($faker->companyEmail)
                ->setTelephone($faker->phoneNumber)
                ->setAdresse($faker->address)
                ->setCivilite(Client::CIVILITE_ENTREPRISE)
                ->setExonere($faker->boolean(5))
                ->setEntreprise($entreprise)
                ->setInvite($faker->randomElement($allInvites));
            $manager->persist($client);
            $clients[] = $client;
        }

        // Générer entre 100 et 150 pistes
        for ($i = 0; $i < $faker->numberBetween(100, 150); $i++) {
            $pisteDate = $faker->dateTimeBetween('2025-01-01', '2026-10-31');

            $piste = new Piste();
            $piste->setNom('Opportunité ' . $faker->catchPhrase)
                ->setClient($faker->randomElement($clients))
                ->setRisque($faker->randomElement($risques))
                ->setPrimePotentielle($faker->randomFloat(2, 5000, 100000))
                ->setCommissionPotentielle($piste->getPrimePotentielle() * $piste->getRisque()->getPourcentageCommissionSpecifiqueHT())
                ->setTypeAvenant(Piste::AVENANT_SOUSCRIPTION)
                ->setDescriptionDuRisque($faker->realText(200))
                ->setExercice((int)$pisteDate->format('Y'))
                ->setEntreprise($entreprise)
                ->setInvite($faker->randomElement($allInvites))
                ->setCreatedAt(DateTimeImmutable::createFromMutable($pisteDate));

            if ($faker->boolean(40)) { // 40% de chance d'avoir un partenaire
                $piste->addPartenaire($faker->randomElement($partenaires));
            }
            $manager->persist($piste);

            // 80% de chance que la piste aboutisse à une cotation
            if ($faker->boolean(80)) {
                $cotationDate = $faker->dateTimeBetween($pisteDate, $pisteDate->format('Y-m-d H:i:s') . ' +15 days');

                $cotation = new Cotation();
                $cotation->setNom('Proposition pour ' . $piste->getNom())
                    ->setPiste($piste)
                    ->setAssureur($faker->randomElement($assureurs))
                    ->setDuree(12)
                    ->setEntreprise($entreprise)
                    ->setInvite($piste->getInvite())
                    ->setCreatedAt(DateTimeImmutable::createFromMutable($cotationDate));
                $manager->persist($cotation);

                // 70% de chance que la cotation soit acceptée et devienne une police (Avenant)
                if ($faker->boolean(70)) {
                    $placementDate = $faker->dateTimeBetween($cotationDate, $cotationDate->format('Y-m-d H:i:s') . ' +5 days');
                    $startingAt = DateTimeImmutable::createFromMutable($placementDate);
                    $endingAt = $startingAt->modify('+1 year');

                    $avenant = new Avenant();
                    $avenant->setDescription('Police d\'assurance ' . $piste->getRisque()->getNomComplet())
                        ->setReferencePolice('POL-' . $faker->unique()->numberBetween(2025000, 2026999))
                        ->setCotation($cotation)
                        ->setStartingAt($startingAt)
                        ->setEndingAt($endingAt)
                        ->setEntreprise($entreprise)
                        ->setInvite($cotation->getInvite())
                        ->setCreatedAt($startingAt);
                    $manager->persist($avenant);

                    // Générer une note de débit pour la commission
                    if ($faker->boolean(90)) {
                        $noteDate = $faker->dateTimeBetween($startingAt->format('Y-m-d H:i:s'), $startingAt->format('Y-m-d H:i:s') . ' +10 days');
                        $note = new Note();
                        $note->setNom("Commission sur police " . $avenant->getReferencePolice())
                            ->setType(Note::TYPE_NOTE_DE_DEBIT)
                            ->setAddressedTo(Note::TO_ASSUREUR)
                            ->setAssureur($cotation->getAssureur())
                            ->setReference('ND-' . $faker->unique()->numberBetween(10000, 99999))
                            ->setValidated(true)
                            ->setSignature((string)time())
                            ->setEntreprise($entreprise)
                            ->setInvite($cotation->getInvite())
                            ->setCreatedAt(DateTimeImmutable::createFromMutable($noteDate));

                        $article = new Article();
                        $article->setNote($note)
                            ->setEntreprise($entreprise)
                            ->setInvite($cotation->getInvite());
                        // Les montants seront calculés par le listener, on ne les met pas ici.
                        $manager->persist($article);
                        $manager->persist($note);

                        // 85% de chance que la commission soit payée
                        if ($faker->boolean(85)) {
                            $paiementDate = $faker->dateTimeBetween($noteDate->format('Y-m-d H:i:s'), $noteDate->format('Y-m-d H:i:s') . ' +45 days');
                            $paiement = new Paiement();
                            // Le montant sera calculé par le listener, on met une valeur indicative
                            $paiement->setMontant($piste->getCommissionPotentielle())
                                ->setNote($note)
                                ->setReference('PAY-' . $faker->unique()->numberBetween(10000, 99999))
                                ->setPaidAt(DateTimeImmutable::createFromMutable($paiementDate))
                                ->setEntreprise($entreprise)
                                ->setInvite($note->getInvite());
                            $manager->persist($paiement);
                        }
                    }
                }
            }
        }

        $manager->flush();
    }
}
