<?php

namespace App\Tests\Services;

use App\Entity\Assureur;
use App\Entity\Avenant;
use App\Entity\ChargementPourPrime;
use App\Entity\Client;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Groupe;
use App\Entity\Invite;
use App\Entity\Partenaire;
use App\Entity\Piste;
use App\Entity\Portefeuille;
use App\Entity\RevenuPourCourtier;
use App\Entity\Risque;
use App\Entity\TypeRevenu;
use App\Entity\Utilisateur;
use App\Services\Canvas\Indicator\IndicatorCalculationHelper;
use App\Services\CanvasBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * SEPT RUBRIQUES paient leurs colonnes par un agrégat de portefeuille appelé UNE FOIS
 * PAR LIGNE : Partenaire, Client, Assureur, Risque, Groupe, Portefeuille et Contact.
 * Une page de vingt lignes relançait donc vingt fois le même moteur sur un sous-graphe
 * très largement commun — mesuré à ~1 s par ligne en production.
 *
 * Le remède est un PRÉCHARGEMENT DE LOT : la page lit son sous-graphe en une passe, et
 * les lignes retrouvent tout en mémoire. Ce qui est groupé, c'est la LECTURE — jamais
 * l'arithmétique, qui n'est pas exprimable en SQL (prorata de notes, max() entre
 * articles et couverture bordereau, imputation FIFO sur les tranches les plus anciennes).
 *
 * D'où les deux affirmations de ce test, et l'ordre dans lequel elles comptent :
 *   1. le lot ne change AUCUN chiffre — c'est le seul risque sérieux du chantier ;
 *   2. le lot coûte STRICTEMENT MOINS de requêtes — sans quoi il ne sert à rien.
 */
class PrechargementLotIndicateursTest extends KernelTestCase
{
    private const OWNER_EMAIL = 'phpunit-lot-owner@test.local';
    private const ENTREPRISE_NOM = 'PHPUnit Lot SARL';

    /** Assez de lignes pour que le coût par ligne se distingue du coût fixe. */
    private const NB_CLIENTS = 6;

    protected function setUp(): void
    {
        static::bootKernel();
        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        parent::tearDown();
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();
        $conn->executeStatement(
            'DELETE cp FROM client_partenaire cp JOIN client c ON cp.client_id = c.id
             JOIN entreprise e ON c.entreprise_id = e.id WHERE e.nom = :nom',
            ['nom' => self::ENTREPRISE_NOM],
        );
        foreach ([
            'avenant', 'revenu_pour_courtier', 'type_revenu', 'chargement_pour_prime', 'cotation',
            'piste', 'client', 'partenaire', 'assureur', 'risque', 'groupe', 'portefeuille', 'invite',
        ] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :nom",
                ['nom' => self::ENTREPRISE_NOM],
            );
        }
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :nom', ['nom' => self::ENTREPRISE_NOM]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :email', ['email' => self::OWNER_EMAIL]);
    }

    /**
     * Un portefeuille complet, assez varié pour que chacune des sept rubriques ait de
     * quoi agréger : plusieurs clients, un partenaire attaché AUX DEUX chemins possibles
     * (la piste et le client), et des propositions non souscrites — que l'agrégat doit
     * ignorer alors que les parcours de rubrique, eux, les traversent.
     */
    private function semer(): int
    {
        $em = $this->em();

        $owner = (new Utilisateur())->setEmail(self::OWNER_EMAIL)->setNom('Lot Owner')->setVerified(true)->setPassword('x');
        $em->persist($owner);

        $entreprise = (new Entreprise())->setNom(self::ENTREPRISE_NOM)->setLicence('LIC')->setAdresse('1 rue')
            ->setTelephone('+2430000')->setRccm('RCCM')->setIdnat('IDNAT')->setNumimpot('IMP');
        $entreprise->setUtilisateur($owner);
        $em->persist($entreprise);

        $invite = (new Invite())->setNom('Proprio')->setProprietaire(true);
        $invite->setUtilisateur($owner)->setEntreprise($entreprise);
        $em->persist($invite);

        $groupe = (new Groupe())->setNom('Groupe Lot')->setDescription('Groupe du lot');
        $groupe->setEntreprise($entreprise);
        $em->persist($groupe);

        $portefeuille = (new Portefeuille())->setNom('Portefeuille Lot')->setGestionnaire($invite);
        $portefeuille->setEntreprise($entreprise);
        $em->persist($portefeuille);

        $partenaire = (new Partenaire())->setNom('Partenaire Lot')->setPart(30.0);
        $partenaire->setEntreprise($entreprise);
        $em->persist($partenaire);

        $assureur = (new Assureur())->setNom('Assureur Lot')->setEmail('assureur-lot@test.local')
            ->setNumimpot('IMP')->setIdnat('IDNAT')->setRccm('RCCM');
        $assureur->setEntreprise($entreprise);
        $em->persist($assureur);

        $risque = (new Risque())->setCode('RL')->setNomComplet('Risque Lot')->setDescription('Risque du lot')
            ->setBranche(Risque::BRANCHE_IARD_OU_NON_VIE)->setImposable(true);
        $risque->setEntreprise($entreprise);
        $em->persist($risque);

        for ($i = 0; $i < self::NB_CLIENTS; ++$i) {
            $client = (new Client())->setNom('Client Lot ' . $i)->setExonere(false);
            $client->setEntreprise($entreprise)->setGroupe($groupe)->setPortefeuille($portefeuille);
            // Un client sur deux porte le partenaire directement : l'agrégat partenaire
            // réunit les deux chemins (piste OU client) et le lot doit faire de même.
            if ($i % 2 === 0) {
                $client->addPartenaire($partenaire);
            }
            $em->persist($client);

            // Une police concrétisée, puis une proposition restée en course.
            $this->semerCotation($entreprise, $invite, $client, $assureur, $risque, $partenaire, 'P' . $i, true);
            $this->semerCotation($entreprise, $invite, $client, $assureur, $risque, $partenaire, 'Q' . $i, false);
        }

        $em->flush();
        $id = (int) $entreprise->getId();
        $em->clear();

        return $id;
    }

    private function semerCotation(
        Entreprise $e,
        Invite $invite,
        Client $client,
        Assureur $assureur,
        Risque $risque,
        Partenaire $partenaire,
        string $nom,
        bool $souscrite,
    ): void {
        $em = $this->em();

        $piste = (new Piste())->setNom('Piste ' . $nom)->setTypeAvenant(0)
            ->setDescriptionDuRisque('Risque lot')->setExercice(2026)
            ->setClient($client)->setRisque($risque);
        $piste->setEntreprise($e)->setInvite($invite);
        $piste->setPartenaire($partenaire);
        $em->persist($piste);

        $cotation = (new Cotation())->setNom($nom)->setDuree(365);
        $cotation->setPiste($piste)->setAssureur($assureur);
        $cotation->setEntreprise($e);
        $em->persist($cotation);

        $chargement = (new ChargementPourPrime())->setNom('Prime ' . $nom)->setMontantFlatExceptionel(1000.0);
        $chargement->setEntreprise($e);
        $cotation->addChargement($chargement);
        $em->persist($chargement);

        $typeRevenu = (new TypeRevenu())->setNom('Commission ' . $nom)->setMontantflat(200.0)
            ->setShared(true)->setMultipayments(true)->setRedevable(TypeRevenu::REDEVABLE_ASSUREUR);
        $typeRevenu->setEntreprise($e);
        $em->persist($typeRevenu);

        $revenu = (new RevenuPourCourtier())->setNom('Revenu ' . $nom)->setTypeRevenu($typeRevenu)->setCotation($cotation);
        $revenu->setEntreprise($e);
        $em->persist($revenu);

        if ($souscrite) {
            $avenant = (new Avenant())->setReferencePolice('POL-' . $nom)->setNumero('0')
                ->setDescription('Police lot')
                ->setStartingAt(new \DateTimeImmutable('-30 days'))
                ->setEndingAt(new \DateTimeImmutable('+335 days'));
            $avenant->setEntreprise($e)->setInvite($invite);
            $cotation->addAvenant($avenant);
            $em->persist($avenant);
        }
    }

    /**
     * @return array<string, class-string>
     */
    private function rubriquesAgregatrices(): array
    {
        return [
            'Partenaire'   => Partenaire::class,
            'Client'       => Client::class,
            'Assureur'     => Assureur::class,
            'Risque'       => Risque::class,
            'Groupe'       => Groupe::class,
            'Portefeuille' => Portefeuille::class,
        ];
    }

    /**
     * Les indicateurs d'une page, avec ou sans préchargement de lot.
     *
     * @return array{valeurs: array<int, array<string, mixed>>, requetes: int}
     */
    private function indicateursDeLaPage(string $fqcn, int $idEntreprise, bool $avecLot): array
    {
        $em = $this->em();
        $em->clear();
        static::getContainer()->get(IndicatorCalculationHelper::class)->reset();

        $canvas = static::getContainer()->get(CanvasBuilder::class);
        $page = $em->getRepository($fqcn)->findBy(['entreprise' => $idEntreprise], ['id' => 'ASC']);

        $logger = new class () implements \Doctrine\DBAL\Logging\SQLLogger {
            public int $nb = 0;

            public function startQuery($sql, ?array $params = null, ?array $types = null): void
            {
                ++$this->nb;
            }

            public function stopQuery(): void
            {
            }
        };
        $config = $em->getConnection()->getConfiguration();
        $precedent = $config->getSQLLogger();
        $config->setSQLLogger($logger);

        $valeurs = [];
        try {
            if ($avecLot) {
                $canvas->batchPreloadForCollection($page);
            }
            foreach ($page as $entite) {
                $canvas->loadAllCalculatedValues($entite);
                $lues = array_filter(get_object_vars($entite), static fn ($v) => is_scalar($v) || $v === null);
                ksort($lues);
                $valeurs[(int) $entite->getId()] = $lues;
            }
        } finally {
            $config->setSQLLogger($precedent);
        }

        ksort($valeurs);

        return ['valeurs' => $valeurs, 'requetes' => $logger->nb];
    }

    public function testLeLotNeChangeAucunChiffre(): void
    {
        $idEntreprise = $this->semer();

        foreach ($this->rubriquesAgregatrices() as $court => $fqcn) {
            $sansLot = $this->indicateursDeLaPage($fqcn, $idEntreprise, false);
            $avecLot = $this->indicateursDeLaPage($fqcn, $idEntreprise, true);

            $this->assertNotSame([], $avecLot['valeurs'], sprintf('La rubrique %s doit avoir des lignes à comparer.', $court));
            $this->assertSame(
                $sansLot['valeurs'],
                $avecLot['valeurs'],
                sprintf(
                    'Le préchargement de lot a changé un indicateur de la rubrique %s. '
                    . 'Il ne doit grouper que la LECTURE : toute divergence ici est une seconde '
                    . 'source de vérité financière qui s\'installe.',
                    $court,
                ),
            );
        }
    }

    public function testLeLotCouteMoinsDeRequetes(): void
    {
        $idEntreprise = $this->semer();

        foreach ($this->rubriquesAgregatrices() as $court => $fqcn) {
            $sansLot = $this->indicateursDeLaPage($fqcn, $idEntreprise, false);
            $avecLot = $this->indicateursDeLaPage($fqcn, $idEntreprise, true);

            $this->assertLessThan(
                $sansLot['requetes'],
                $avecLot['requetes'],
                sprintf(
                    'La rubrique %s ne tire aucun bénéfice du lot (%d requêtes avec, %d sans). '
                    . 'Soit la branche de CalculationProvider::batchPreload a disparu, soit le '
                    . 'mémo de préchargement ne retient plus rien.',
                    $court,
                    $avecLot['requetes'],
                    $sansLot['requetes'],
                ),
            );
        }
    }
}
