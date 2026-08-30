<?php

namespace App\Tests\Services;

use App\Entity\Client;
use App\Entity\ConditionPartage;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Partenaire;
use App\Entity\Piste;
use App\Entity\Risque;
use App\Entity\Utilisateur;
use App\Services\ReconductionPartageService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * LE CIBLAGE DES RISQUES SE RECONDUIT, IL NE SE REDÉFINIT PLUS.
 *
 * ── CE QUE CE TEST FERME ────────────────────────────────────────────────────────────
 * Une condition qui visait « Incendie » ne revenait jamais telle quelle sur la piste
 * dérivée. Son ciblage était traduit en « effet équivalent » :
 *
 *   — applicable au risque de la piste  → reconduite en condition GÉNÉRALE ;
 *   — non applicable                    → reconduite inerte, à ré-armer à la main.
 *
 * La raison était bonne à l'époque : `Risque::conditionPartage` était un ManyToOne, et
 * rattacher les mêmes risques au clone les aurait RETIRÉS de la condition d'origine —
 * cassant la rétrocommission de la police de base. Depuis le passage des risques ciblés en
 * ManyToMany, un risque appartient à autant de conditions qu'on veut : la prudence était
 * devenue une perte, et l'utilisateur re-cochait ses risques exercice après exercice.
 *
 * ⚠ ET LE PREMIER CAS COÛTAIT DE L'ARGENT. Une condition ciblée « Incendie » devenait
 * GÉNÉRALE sur la suite de la police : elle payait dès lors sur TOUS les risques, y compris
 * ceux qu'elle n'avait jamais visés. Ce test verrouille les deux moitiés de la correction —
 * ce que la dérivée reçoit, et ce que l'originale garde.
 */
class ReconductionCiblageTest extends KernelTestCase
{
    private const ENTREPRISE_NOM = 'PHPUnit Ciblage SARL';
    private const OWNER_EMAIL = 'phpunit-ciblage-owner@test.local';

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
            'DELETE cpr FROM condition_partage_risque cpr
             JOIN condition_partage cp ON cpr.condition_partage_id = cp.id
             JOIN entreprise e ON cp.entreprise_id = e.id WHERE e.nom = :nom',
            ['nom' => self::ENTREPRISE_NOM],
        );
        foreach (['condition_partage', 'piste', 'risque', 'client', 'partenaire', 'invite'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :nom",
                ['nom' => self::ENTREPRISE_NOM],
            );
        }
        $conn->executeStatement(
            'UPDATE utilisateur u JOIN entreprise e ON u.connected_to_id = e.id
             SET u.connected_to_id = NULL WHERE e.nom = :nom',
            ['nom' => self::ENTREPRISE_NOM],
        );
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :nom', ['nom' => self::ENTREPRISE_NOM]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :email', ['email' => self::OWNER_EMAIL]);
        $this->em()->clear();
    }

    /**
     * Une police d'INCENDIE dont la condition vise « Incendie » — donc applicable ET
     * ciblée. C'est le cas que l'ancien comportement transformait en condition générale.
     *
     * @return array{source: Piste, entreprise: Entreprise, invite: Invite,
     *               incendie: Risque, degats: Risque, condition: ConditionPartage}
     */
    private function semer(): array
    {
        $em = $this->em();

        $owner = (new Utilisateur())->setEmail(self::OWNER_EMAIL)->setNom('Ciblage')
            ->setVerified(true)->setPassword('x');
        $em->persist($owner);

        $entreprise = (new Entreprise())->setNom(self::ENTREPRISE_NOM)->setLicence('LIC')
            ->setAdresse('1 rue')->setTelephone('+2430000')->setRccm('R')->setIdnat('I')->setNumimpot('N')
            ->setUtilisateur($owner);
        $em->persist($entreprise);

        $invite = (new Invite())->setNom('Gestionnaire')->setProprietaire(true);
        $invite->setUtilisateur($owner)->setEntreprise($entreprise);
        $em->persist($invite);

        $faireRisque = static function (string $code, string $nom) use ($em, $entreprise): Risque {
            $r = (new Risque())->setCode($code)->setNomComplet($nom)->setDescription($nom)
                ->setBranche(Risque::BRANCHE_IARD_OU_NON_VIE)->setImposable(true);
            $r->setEntreprise($entreprise);
            $em->persist($r);

            return $r;
        };

        $incendie = $faireRisque('CIB-INC', 'Incendie');
        $degats = $faireRisque('CIB-DEG', 'Dégâts des eaux');

        $client = (new Client())->setNom('Client Ciblage')->setExonere(false);
        $client->setEntreprise($entreprise);
        $em->persist($client);

        $partenaire = (new Partenaire())->setNom('SUNU Ciblage')->setPart(20.0);
        $partenaire->setEntreprise($entreprise);
        $em->persist($partenaire);

        $source = (new Piste())->setNom('Police Incendie')->setTypeAvenant(0)
            ->setDescriptionDuRisque('Entrepôt')->setExercice(2026)
            ->setClient($client)->setRisque($incendie);
        $source->setEntreprise($entreprise)->setInvite($invite);
        $source->setPartenaire($partenaire);

        // LA CONDITION DU LITIGE : elle vise DEUX risques, dont celui de la piste.
        $condition = (new ConditionPartage())
            ->setNom('Apport SUNU 20 %')
            ->setFormule(ConditionPartage::FORMULE_NE_SAPPLIQUE_PAS_SEUIL)
            ->setSeuil(0.0)->setTaux(20.0)
            ->setCritereRisque(ConditionPartage::CRITERE_INCLURE_TOUS_CES_RISQUES)
            ->setPartenaire($partenaire);
        $condition->addProduit($incendie);
        $condition->addProduit($degats);
        $condition->setEntreprise($entreprise)->setInvite($invite);
        $source->addConditionsPartageExceptionnelle($condition);

        $em->persist($source);
        $em->flush();

        return [
            'source' => $source,
            'entreprise' => $entreprise,
            'invite' => $invite,
            'incendie' => $incendie,
            'degats' => $degats,
            'condition' => $condition,
        ];
    }

    private function service(): ReconductionPartageService
    {
        return static::getContainer()->get(ReconductionPartageService::class);
    }

    /**
     * LA DÉRIVÉE VISE EXACTEMENT CE QUE VISAIT L'ORIGINALE.
     *
     * Avant, ce test rendait « pas de risques ciblés » et une collection vide : la
     * condition payait sur tout, et l'utilisateur devait re-cocher Incendie et Dégâts des
     * eaux à la main.
     */
    public function testLeCiblageEstRecopieALIdentique(): void
    {
        $s = $this->semer();

        $cible = (new Piste())->setNom('Renouvellement 2027')->setTypeAvenant(0)
            ->setDescriptionDuRisque('Entrepôt')->setExercice(2027)
            ->setClient($s['source']->getClient())->setRisque($s['incendie']);
        $cible->setEntreprise($s['entreprise'])->setInvite($s['invite']);

        $this->service()->reconduire($s['source'], $cible, $s['entreprise'], $s['invite']);

        $clones = $cible->getConditionsPartageExceptionnelles();
        self::assertCount(1, $clones, 'La condition est reconduite.');
        $clone = $clones->first();

        self::assertSame(
            ConditionPartage::CRITERE_INCLURE_TOUS_CES_RISQUES,
            $clone->getCritereRisque(),
            'Le critère est celui de l’originale, pas une traduction.',
        );

        $vises = array_map(static fn (Risque $r) => $r->getCode(), $clone->getProduits()->toArray());
        sort($vises);
        self::assertSame(['CIB-DEG', 'CIB-INC'], $vises, 'Les DEUX risques visés suivent.');
        self::assertSame(20.0, $clone->getTaux(), 'Le taux ne bouge pas.');
    }

    /**
     * ⚠ ET L'ORIGINALE GARDE LES SIENS.
     *
     * C'est la moitié du test qui prouve que le ManyToMany tient. Sous l'ancienne
     * cardinalité, rattacher ces risques au clone les aurait retirés d'ici — la
     * rétrocommission de la police de base aurait cessé, en silence.
     */
    public function testLOriginaleGardeSesRisques(): void
    {
        $s = $this->semer();

        $cible = (new Piste())->setNom('Renouvellement 2027')->setTypeAvenant(0)
            ->setDescriptionDuRisque('Entrepôt')->setExercice(2027)
            ->setClient($s['source']->getClient())->setRisque($s['incendie']);
        $cible->setEntreprise($s['entreprise'])->setInvite($s['invite']);

        $this->service()->reconduire($s['source'], $cible, $s['entreprise'], $s['invite']);
        // La piste dérivée doit être ÉCRITE pour que sa condition — et la table de liaison
        // de son ciblage — le soient aussi : c est la base qu on interroge ensuite.
        $this->em()->persist($cible);
        $this->em()->flush();

        $vises = array_map(static fn (Risque $r) => $r->getCode(), $s['condition']->getProduits()->toArray());
        sort($vises);
        self::assertSame(['CIB-DEG', 'CIB-INC'], $vises, 'La condition d’origine n’a rien perdu.');

        // Et le risque lui-même est visé par les DEUX conditions.
        //
        // ON RELIT DEPUIS LA BASE, et il le faut : `addProduit()` ne touche que le côté
        // PROPRIÉTAIRE de la relation — la collection inverse du risque n'est peuplée qu'au
        // chargement suivant. L'interroger en mémoire aurait rendu zéro et fait croire à un
        // rattachement perdu, alors que la table de liaison porte bien les deux lignes.
        $codeIncendie = $s['incendie']->getCode();
        $this->em()->clear();
        $incendie = $this->em()->getRepository(Risque::class)->findOneBy(['code' => $codeIncendie]);

        self::assertCount(
            2,
            $incendie->getConditionsPartage(),
            'Un risque du catalogue est visé par autant de conditions qu’on veut — c’est ce '
            . 'que la cardinalité ManyToMany autorise, et ce que l’ancienne interdisait.',
        );
    }

    /**
     * L'INTERMÉDIAIRE SUIT AUSSI — c'est lui qui décide à qui la part revient, et sans lui
     * la condition reconduite nommerait quelqu'un que l'affaire ne connaît pas.
     */
    public function testLIntermediaireEstReconduit(): void
    {
        $s = $this->semer();

        $cible = (new Piste())->setNom('Renouvellement 2027')->setTypeAvenant(0)
            ->setDescriptionDuRisque('Entrepôt')->setExercice(2027)
            ->setClient($s['source']->getClient())->setRisque($s['incendie']);
        $cible->setEntreprise($s['entreprise'])->setInvite($s['invite']);

        $this->service()->reconduire($s['source'], $cible, $s['entreprise'], $s['invite']);

        self::assertSame(
            $s['source']->getPartenaire()?->getId(),
            $cible->getPartenaire()?->getId(),
            'La piste dérivée revient au même intermédiaire.',
        );
    }
}
