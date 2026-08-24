<?php

namespace App\Tests\Workspace;

use App\Entity\Avenant;
use App\Entity\Client;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Piste;
use App\Entity\ReversementRetroAgent;
use App\Entity\Risque;
use App\Entity\Utilisateur;
use App\Services\CanvasBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * LA BARRE DES TOTAUX DE LA RUBRIQUE « RÉTROS AGENTS ».
 *
 * Elle annonçait « aucune valeur numérique » et un total figé à 0,00 alors que chaque ligne
 * porte un décaissement : la rubrique était la SEULE des 34 à n'avoir aucun fournisseur
 * numérique. Rien n'était cassé — la pièce manquait, et une liste d'argent qui ne sait pas
 * s'additionner oblige le courtier à sortir sa calculette.
 *
 * Deux propriétés, et la seconde est la seule qui demande à réfléchir :
 *
 *  1. LE MONTANT EST EXPOSÉ, EN CENTIMES — le contrat du contrôleur `list-summary`, partagé
 *     par toutes les rubriques. Exposer des unités donnerait un total cent fois trop petit,
 *     sans que rien ne proteste.
 *  2. UN VIREMENT GROUPÉ S'ADDITIONNE LIGNE À LIGNE. Chaque ligne d'un lot porte SA part,
 *     jamais le total du virement : la somme des lignes est donc le décaissement réel. On
 *     le VÉRIFIE plutôt que de le supposer, parce que l'erreur inverse — une colonne qui
 *     répète un total et qu'on cumule — a déjà été commise ailleurs, et qu'elle ne se voit
 *     pas : le total est simplement faux.
 */
class ReversementTotauxTest extends KernelTestCase
{
    private const OWNER_EMAIL = 'phpunit-totaux-reversement@test.local';
    private const ENT = 'PHPUnit Totaux Reversement SARL';

    /** Les parts du lot, distinctes à dessein : un cumul erroné se verrait tout de suite. */
    private const PART_A = 1250.0;
    private const PART_B = 830.0;
    private const ISOLE = 420.0;

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
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email = :e', ['e' => self::OWNER_EMAIL]);
        foreach (['document', 'reversement_retro_agent', 'avenant', 'cotation', 'piste', 'client', 'risque', 'invite'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :nom",
                ['nom' => self::ENT],
            );
        }
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :nom', ['nom' => self::ENT]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :email', ['email' => self::OWNER_EMAIL]);
        $this->em()->clear();
    }

    /**
     * Un virement GROUPÉ de deux parts inégales, plus un versement isolé.
     *
     * @return list<ReversementRetroAgent> dans l'ordre : part A, part B, isolé
     */
    private function semer(): array
    {
        $em = $this->em();

        $owner = (new Utilisateur())->setEmail(self::OWNER_EMAIL)->setNom('Totaux')->setVerified(true)->setPassword('x');
        $em->persist($owner);

        $ent = (new Entreprise())->setNom(self::ENT)->setLicence('LIC')->setAdresse('1 rue')
            ->setTelephone('+2430000')->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($owner);
        $em->persist($ent);
        $owner->setConnectedTo($ent);

        $proprietaire = (new Invite())->setNom('Owner')->setProprietaire(true);
        $proprietaire->setUtilisateur($owner)->setEntreprise($ent);
        $em->persist($proprietaire);

        $agent = (new Invite())->setNom('Alice')->setProprietaire(false);
        $agent->setEntreprise($ent);
        $em->persist($agent);

        $risque = (new Risque())->setCode('TOT')->setNomComplet('Risque totaux')
            ->setBranche(Risque::BRANCHE_IARD_OU_NON_VIE)->setImposable(true);
        $risque->setEntreprise($ent);
        $em->persist($risque);

        $client = (new Client())->setNom('Client totaux')->setExonere(false);
        $client->setEntreprise($ent);
        $em->persist($client);

        $piste = (new Piste())->setNom('Affaire totaux')->setTypeAvenant(Piste::AVENANT_SOUSCRIPTION)
            ->setDescriptionDuRisque('x')->setExercice((int) date('Y'))->setClient($client)->setRisque($risque);
        $piste->setEntreprise($ent)->setInvite($proprietaire);
        $em->persist($piste);

        $cotation = (new Cotation())->setNom('Cotation totaux')->setDuree(365);
        $cotation->setPiste($piste)->setEntreprise($ent);
        $em->persist($cotation);

        $verser = function (float $montant, ?string $lot, string $ref) use ($ent, $proprietaire, $agent, $cotation, $em): ReversementRetroAgent {
            $avenant = (new Avenant())->setReferencePolice('POL-TOT-' . $ref . '-' . $montant)->setNumero('0')
                ->setDescription('Police ' . $ref)
                ->setStartingAt(new \DateTimeImmutable('-30 days'))
                ->setEndingAt(new \DateTimeImmutable('+335 days'));
            $avenant->setEntreprise($ent)->setInvite($proprietaire);
            $cotation->addAvenant($avenant);
            $em->persist($avenant);

            $r = (new ReversementRetroAgent())->setAgent($agent)->setAvenant($avenant)->setMontant($montant)
                ->setPaidAt(new \DateTimeImmutable('now'))->setReference($ref)->setLotReference($lot);
            $r->setEntreprise($ent)->setInvite($proprietaire);
            $em->persist($r);

            return $r;
        };

        $lignes = [
            $verser(self::PART_A, 'VIR-TOT', 'VIR-TOT'),
            $verser(self::PART_B, 'VIR-TOT', 'VIR-TOT'),
            $verser(self::ISOLE, null, 'VIR-SOLO'),
        ];
        $em->flush();

        return $lignes;
    }

    private function canvasBuilder(): CanvasBuilder
    {
        return static::getContainer()->get(CanvasBuilder::class);
    }

    /**
     * LE MONTANT EST TOTALISABLE, ET EN CENTIMES.
     *
     * Sans fournisseur, ce tableau était VIDE et la barre affichait « aucune valeur
     * numérique » : c'est très exactement le défaut constaté à l'écran.
     */
    public function testLeMontantEstExposeEnCentimes(): void
    {
        $lignes = $this->semer();

        $numerique = $this->canvasBuilder()->getNumericAttributesAndValues($lignes[0]);

        self::assertArrayHasKey('montant', $numerique, 'La barre des totaux n’a aucune valeur à additionner.');
        self::assertSame('Montant versé', $numerique['montant']['description']);
        self::assertEqualsWithDelta(
            self::PART_A * 100,
            $numerique['montant']['value'],
            0.001,
            'Le contrat de list-summary est le CENTIME : en unités, le total serait cent fois trop petit.',
        );
    }

    /**
     * UN VIREMENT GROUPÉ S'ADDITIONNE LIGNE À LIGNE.
     *
     * Chaque ligne d'un lot porte sa part, jamais le total du virement. Si un jour la
     * colonne venait à répéter le total du lot, ce test tomberait — et c'est bien là son
     * seul intérêt, car un total faux ne se voit pas.
     */
    public function testLaSommeDUnLotEstCelleDeSesParts(): void
    {
        $lignes = $this->semer();

        $total = 0.0;
        foreach ([$lignes[0], $lignes[1]] as $ligne) {
            $total += $this->canvasBuilder()->getNumericAttributesAndValues($ligne)['montant']['value'];
        }

        self::assertEqualsWithDelta(
            (self::PART_A + self::PART_B) * 100,
            $total,
            0.001,
            'Le total du virement groupé doit valoir la somme de ses parts, jamais un montant répété.',
        );
    }

    /** La collection entière — le chemin réel de la barre des totaux. */
    public function testToutesLesLignesDeLaPageSontTotalisees(): void
    {
        $this->semer();

        $lignes = $this->em()->getRepository(ReversementRetroAgent::class)
            ->createQueryBuilder('r')
            ->join('r.entreprise', 'e')->andWhere('e.nom = :nom')->setParameter('nom', self::ENT)
            ->getQuery()->getResult();
        self::assertCount(3, $lignes);

        $parId = $this->canvasBuilder()->getNumericAttributesAndValuesForCollection($lignes);
        self::assertCount(3, $parId, 'Chaque ligne doit porter ses valeurs numériques.');

        $total = array_sum(array_map(static fn (array $v) => $v['montant']['value'], $parId));
        self::assertEqualsWithDelta((self::PART_A + self::PART_B + self::ISOLE) * 100, $total, 0.001);
    }
}
