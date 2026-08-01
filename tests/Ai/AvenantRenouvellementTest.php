<?php

namespace App\Tests\Ai;

use App\Ai\FicheNormaliseur;
use App\Entity\Assureur;
use App\Entity\Avenant;
use App\Entity\Client;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Piste;
use App\Entity\Risque;
use App\Entity\Utilisateur;
use App\Services\AvenantRenouvellementResolver;
use App\Constantes\Constante;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * QU'EST DEVENUE CETTE POLICE ? — non-régression du bug KIN AVIA.
 *
 * Interrogée sur « cet avenant est-il renouvelé ? », l'assistante répondait
 * « pas encore renouvelé : une piste de renouvellement a bien été initiée, mais
 * la police n'a pas fait l'objet d'un avenant de reconduction effectif », alors
 * qu'un avenant successeur existait. Elle ne voyait qu'un demi-fait (« une piste
 * dérivée existe ») et a comblé le vide par une négation plausible.
 *
 * Ces tests protègent, dans l'ordre d'importance :
 *  1. une police dont la piste dérivée a produit un avenant est RENOUVELÉE, et
 *     la phrase de suite NOMME cet avenant (numéro et période) ;
 *  2. la réciproque tient : une piste dérivée SANS avenant n'est pas un
 *     renouvellement acquis — sinon on corrigerait le bug en en créant l'inverse ;
 *  3. l'absence de suite est affirmée comme une VÉRIFICATION de la chaîne, pas
 *     comme un silence : c'est ce qui autorise à dire « non renouvelée » ;
 *  4. les deux sens du double lien Piste::avenantDeBase ⇄ Avenant::pisteDeRenouvellement
 *     donnent le même verdict, et le badge des listes (Constante) partage ce calcul.
 */
class AvenantRenouvellementTest extends WebTestCase
{
    private const ENT   = 'PHPUnit-KetRenouvellement';
    private const OWNER = 'phpunit-ketrenouvellement-owner@test.local';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private AvenantRenouvellementResolver $resolver;

    protected function setUp(): void
    {
        $this->client   = static::createClient();
        $this->em       = static::getContainer()->get(EntityManagerInterface::class);
        $this->resolver = static::getContainer()->get(AvenantRenouvellementResolver::class);
        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        parent::tearDown();
    }

    private function cleanUp(): void
    {
        $conn = $this->em->getConnection();
        $n = self::ENT;
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email = :e', ['e' => self::OWNER]);
        // Cycle de FK Avenant ↔ Piste : dissocier les deux liens croisés d'abord.
        $conn->executeStatement('UPDATE avenant a JOIN entreprise e ON a.entreprise_id = e.id SET a.piste_de_renouvellement_id = NULL WHERE e.nom = :n', ['n' => $n]);
        $conn->executeStatement('UPDATE piste p JOIN entreprise e ON p.entreprise_id = e.id SET p.avenant_de_base_id = NULL WHERE e.nom = :n', ['n' => $n]);

        foreach ([
            'DELETE a FROM avenant a JOIN entreprise e ON a.entreprise_id = e.id WHERE e.nom = :n',
            'DELETE co FROM cotation co JOIN entreprise e ON co.entreprise_id = e.id WHERE e.nom = :n',
            'DELETE p FROM piste p JOIN entreprise e ON p.entreprise_id = e.id WHERE e.nom = :n',
            'DELETE ri FROM risque ri JOIN entreprise e ON ri.entreprise_id = e.id WHERE e.nom = :n',
            'DELETE c FROM client c JOIN entreprise e ON c.entreprise_id = e.id WHERE e.nom = :n',
            'DELETE ass FROM assureur ass JOIN entreprise e ON ass.entreprise_id = e.id WHERE e.nom = :n',
            'DELETE i FROM invite i JOIN entreprise e ON i.entreprise_id = e.id WHERE e.nom = :n',
        ] as $sql) {
            $conn->executeStatement($sql, ['n' => $n]);
        }
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :n', ['n' => $n]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :e', ['e' => self::OWNER]);
        $this->em->clear();
    }

    // ------------------------------------------------------------------ fixtures

    /**
     * Police EXPIRÉE, calquée sur le cas réel : couverture du 31/01 de l'an
     * dernier au 30/01 de cette année. Le contexte (client, risque, assureur) est
     * réduit au strict nécessaire : ces tests portent sur la CHAÎNE, pas sur les
     * montants.
     *
     * @return array{ent: Entreprise, inv: Invite, user: Utilisateur, base: Avenant, piste: Piste, assureur: Assureur, client: Client, risque: Risque}
     */
    private function seed(int $renewalCondition = Piste::RENEWAL_CONDITION_RENEWABLE): array
    {
        $user = (new Utilisateur())->setEmail(self::OWNER)->setNom('PHPUnit')->setVerified(true);
        $user->setPassword('x');
        $this->em->persist($user);

        $ent = (new Entreprise())
            ->setNom(self::ENT)->setLicence('LIC')->setAdresse('1 rue')->setTelephone('+243000')
            ->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($user);
        $this->em->persist($ent);
        $user->setConnectedTo($ent);

        $inv = (new Invite())->setNom('Owner')->setUtilisateur($user)->setEntreprise($ent)->setProprietaire(true);
        $this->em->persist($inv);

        $scoper = function (object $e) use ($ent, $inv): object {
            $e->setEntreprise($ent);
            if (method_exists($e, 'setInvite')) {
                $e->setInvite($inv);
            }
            $this->em->persist($e);

            return $e;
        };

        $client   = $scoper((new Client())->setNom('KIN AVIA PHPUnit')->setExonere(false));
        $assureur = $scoper((new Assureur())
            ->setNom('SFA CONGO PHPUnit')->setEmail('sfa@assureur.test')
            ->setNumimpot('IMP-RNV')->setIdnat('IDNAT-RNV')->setRccm('RCCM-RNV'));
        $risque   = $scoper((new Risque())
            ->setNomComplet('Aviation PHPUnit')->setCode('RNV-RQ')->setDescription('Risque aviation')
            ->setBranche(Risque::BRANCHE_IARD_OU_NON_VIE)->setImposable(true));

        $piste = $scoper((new Piste())
            ->setNom('Aviation KIN AVIA')
            ->setClient($client)->setRisque($risque)
            ->setTypeAvenant(Piste::AVENANT_SOUSCRIPTION)
            ->setDescriptionDuRisque('Flotte principale')
            ->setExercice((int) (new DateTimeImmutable('-1 year'))->format('Y'))
            ->setRenewalCondition($renewalCondition));

        $cotation = $scoper((new Cotation())->setNom('Offre Aviation')->setDuree(12)->setAssureur($assureur));
        $cotation->setPiste($piste);

        $base = $scoper((new Avenant())
            ->setReferencePolice('12002-33002-0010-108')
            ->setNumero('0')
            ->setDescription('Police aviation KIN AVIA')
            ->setStartingAt(new DateTimeImmutable('-1 year -1 day'))
            ->setEndingAt(new DateTimeImmutable('-1 day'))
            ->setCotation($cotation));

        $this->em->flush();
        $this->client->loginUser($user);

        return compact('ent', 'inv', 'user', 'base', 'piste', 'assureur', 'client', 'risque');
    }

    /**
     * Opportunité DÉRIVÉE de la police de base, avec ou sans avenant issu, et
     * avec l'un ou les deux sens du double lien.
     */
    private function deriver(
        array $s,
        int $typeAvenant,
        bool $avecAvenantIssu,
        bool $lienSurAvenant = true,
    ): ?Avenant {
        /** @var Entreprise $ent */
        $ent = $s['ent'];
        /** @var Invite $inv */
        $inv = $s['inv'];
        /** @var Avenant $base */
        $base = $s['base'];

        $scoper = function (object $e) use ($ent, $inv): object {
            $e->setEntreprise($ent);
            if (method_exists($e, 'setInvite')) {
                $e->setInvite($inv);
            }
            $this->em->persist($e);

            return $e;
        };

        $derivee = $scoper((new Piste())
            ->setNom('Renouvellement — Aviation KIN AVIA')
            ->setClient($s['client'])->setRisque($s['risque'])
            ->setTypeAvenant($typeAvenant)
            ->setDescriptionDuRisque('Flotte principale')
            ->setExercice((int) (new DateTimeImmutable('now'))->format('Y'))
            ->setRenewalCondition(Piste::RENEWAL_CONDITION_RENEWABLE));
        $derivee->setAvenantDeBase($base);
        if ($lienSurAvenant) {
            $base->setPisteDeRenouvellement($derivee);
        }

        $issu = null;
        if ($avecAvenantIssu) {
            $cotationDerivee = $scoper((new Cotation())->setNom('Offre Aviation N+1')->setDuree(12)->setAssureur($s['assureur']));
            $cotationDerivee->setPiste($derivee);

            $issu = $scoper((new Avenant())
                ->setReferencePolice('12002-33002-0010-108')
                ->setNumero('1')
                ->setDescription('Police aviation KIN AVIA')
                ->setStartingAt(new DateTimeImmutable('today'))
                ->setEndingAt(new DateTimeImmutable('+1 year -1 day'))
                ->setCotation($cotationDerivee));
        }

        $this->em->flush();

        return $issu;
    }

    // ------------------------------------------------------------------ tests

    /**
     * LE BUG. Police expirée + piste dérivée de renouvellement + avenant issu :
     * la police est RENOUVELÉE, et la phrase nomme son successeur. Toute réponse
     * du genre « pas encore renouvelé » est désormais impossible à fabriquer
     * depuis cette fiche.
     */
    public function testPoliceExpireeAvecAvenantIssuEstRenouvelee(): void
    {
        $s = $this->seed();
        $issu = $this->deriver($s, Piste::AVENANT_RENOUVELLEMENT, true);

        $suite = $this->resolver->resoudre($s['base']);

        $this->assertSame(Avenant::RENEWAL_STATUS_RENEWED, $suite['code']);
        $this->assertSame('Renouvelée', $suite['statut']);
        $this->assertCount(1, $suite['avenantsIssus']);
        $this->assertSame($issu->getId(), $suite['avenantsIssus'][0]['id']);

        // La phrase doit porter la PREUVE : l'id, le numéro et la période du successeur.
        $this->assertStringContainsString('#' . $issu->getId(), $suite['phrase']);
        $this->assertStringContainsString('n° 1', $suite['phrase']);
        $this->assertStringContainsString($issu->getStartingAt()->format('d/m/Y'), $suite['phrase']);
        $this->assertStringContainsString($issu->getEndingAt()->format('d/m/Y'), $suite['phrase']);
        $this->assertStringNotContainsString('AUCUN avenant', $suite['phrase']);
    }

    /**
     * LA RÉCIPROQUE, tout aussi importante : une opportunité dérivée SANS avenant
     * n'est pas un renouvellement acquis. Corriger le bug en affirmant l'inverse
     * serait le même défaut, à l'envers.
     */
    public function testPisteDeriveeSansAvenantNestPasRenouvelee(): void
    {
        $s = $this->seed();
        $this->deriver($s, Piste::AVENANT_RENOUVELLEMENT, false);

        $suite = $this->resolver->resoudre($s['base']);

        $this->assertSame(Avenant::RENEWAL_STATUS_RENEWING, $suite['code']);
        $this->assertSame('Renouvellement en cours', $suite['statut']);
        $this->assertSame([], $suite['avenantsIssus']);
        $this->assertStringContainsString('AUCUN avenant', $suite['phrase']);
        $this->assertStringContainsString('pas encore reconduite', $suite['phrase']);
    }

    /**
     * Aucune suite du tout : l'absence est affirmée comme le RÉSULTAT d'une
     * vérification de la chaîne — c'est ce qui autorise à répondre « non
     * renouvelée » sans deviner.
     */
    public function testPoliceExpireeSansAucuneSuite(): void
    {
        $s = $this->seed();

        $suite = $this->resolver->resoudre($s['base']);

        $this->assertSame(Avenant::RENEWAL_STATUS_LOST, $suite['code']);
        $this->assertSame('Non renouvelée', $suite['statut']);
        $this->assertNull($suite['pisteDeriveeId']);
        $this->assertStringContainsString('NON RENOUVELÉE', $suite['phrase']);
        $this->assertStringContainsString('chaîne vérifiée', $suite['phrase']);
    }

    /**
     * Lien à MOITIÉ posé (la piste pointe la police, la police ne pointe pas la
     * piste) : même verdict. Sans cette lecture à deux sens, une donnée ancienne
     * ou une dissociation partielle ferait réapparaître le bug.
     */
    public function testLienAMoitiePoseDonneLeMemeVerdict(): void
    {
        $s = $this->seed();
        $issu = $this->deriver($s, Piste::AVENANT_RENOUVELLEMENT, true, lienSurAvenant: false);

        $this->assertNull($s['base']->getPisteDeRenouvellement(), 'Le lien porté par l’avenant est bien absent.');

        $suite = $this->resolver->resoudre($s['base']);

        $this->assertSame(Avenant::RENEWAL_STATUS_RENEWED, $suite['code']);
        $this->assertSame($issu->getId(), $suite['avenantsIssus'][0]['id']);
    }

    public function testProrogationEtResiliation(): void
    {
        $s = $this->seed();
        $this->deriver($s, Piste::AVENANT_PROROGATION, true);
        $this->assertSame(Avenant::RENEWAL_STATUS_EXTENDED, $this->resolver->resoudre($s['base'])['code']);
        $this->assertSame('Prorogée', $this->resolver->resoudre($s['base'])['statut']);

        $this->cleanUp();
        $s = $this->seed();
        $this->deriver($s, Piste::AVENANT_RESILIATION, false);
        $suite = $this->resolver->resoudre($s['base']);
        $this->assertSame(Avenant::RENEWAL_STATUS_CANCELLED, $suite['code']);
        $this->assertSame('Résiliée / annulée', $suite['statut']);
    }

    /**
     * Piste dérivée typée « Souscription » : l'ancien match sans bras par défaut
     * levait \UnhandledMatchError et faisait tomber la page ET la réponse de Ket.
     */
    public function testPisteDeriveeTypeeSouscriptionNeLevePasDException(): void
    {
        $s = $this->seed();
        $this->deriver($s, Piste::AVENANT_SOUSCRIPTION, true);

        $suite = $this->resolver->resoudre($s['base']);

        $this->assertSame(Avenant::RENEWAL_STATUS_RENEWED, $suite['code']);
        $this->assertNotSame('', $suite['phrase']);
    }

    /**
     * Une police marquée « temporaire non renouvelable » qui a POURTANT été
     * reconduite est renouvelée : la réalité de la chaîne prime sur l'intention
     * enregistrée. Sans suite, en revanche, elle est « temporaire » et non
     * « perdue » — l'échéance était voulue.
     */
    public function testTemporaireNonRenouvelable(): void
    {
        $s = $this->seed(Piste::RENEWAL_CONDITION_ONCE_OFF_AND_EXTENDABLE);
        $suite = $this->resolver->resoudre($s['base']);
        $this->assertSame(Avenant::RENEWAL_STATUS_ONCE_OFF, $suite['code']);
        $this->assertStringContainsString('TEMPORAIRE', $suite['phrase']);

        $this->deriver($s, Piste::AVENANT_RENOUVELLEMENT, true);
        $this->assertSame(Avenant::RENEWAL_STATUS_RENEWED, $this->resolver->resoudre($s['base'])['code']);
    }

    /**
     * PARITÉ DES CHEMINS : la fiche que reçoit l'assistante porte le fait, et le
     * badge de la liste (Constante::Avenant_getRenewalStatus) est calculé par la
     * MÊME source. Un écran et une réponse de Ket ne peuvent plus se contredire.
     */
    public function testFicheDeLAssistanteEtBadgeDeListePartagentLeFait(): void
    {
        $s = $this->seed();
        $issu = $this->deriver($s, Piste::AVENANT_RENOUVELLEMENT, true);

        /** @var FicheNormaliseur $normaliseur */
        $normaliseur = static::getContainer()->get(FicheNormaliseur::class);
        $fiche = $normaliseur->ficheEnrichie($s['base']);

        $this->assertSame('Renouvelée', $fiche['statutRenouvellement']);
        $this->assertStringContainsString('#' . $issu->getId(), $fiche['suiteDeLaPolice']);
        // La fiche est COMPLÈTE : un indicateur tardif de la stratégie y figure.
        $this->assertArrayHasKey('reserve', $fiche);

        /** @var Constante $constante */
        $constante = static::getContainer()->get(Constante::class);
        $this->assertSame(Avenant::RENEWAL_STATUS_RENEWED, $constante->Avenant_getRenewalStatus($s['base'])['code']);
    }
}
