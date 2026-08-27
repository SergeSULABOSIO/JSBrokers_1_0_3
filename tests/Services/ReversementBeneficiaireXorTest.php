<?php

namespace App\Tests\Services;

use App\Entity\Avenant;
use App\Entity\Client;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Partenaire;
use App\Entity\Piste;
use App\Entity\ReversementRetroAgent;
use App\Entity\Risque;
use App\Entity\Tranche;
use App\Entity\Utilisateur;
use App\Service\Workspace\ChampsObligatoiresInspector;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * UN VERSEMENT VA À UN BÉNÉFICIAIRE, ET À UN SEUL.
 *
 * Le bénéficiaire est un AGENT interne OU un PARTENAIRE externe. Les deux familles tiennent
 * désormais sur le même enregistrement — le partenaire envoie sa note de débit, le cabinet
 * lui reverse et garde la pièce, exactement comme pour un agent.
 *
 * ── POURQUOI CETTE GARDE EST LA SEULE ───────────────────────────────────────────────
 *
 * `agent_id` a dû devenir NULLABLE pour qu'une ligne puisse désigner un partenaire. Doctrine
 * ne réclame donc plus l'agent, et la contrainte « l'un OU l'autre » ne s'exprime pas en SQL
 * portable. Sans ce refus applicatif, on pourrait enregistrer un versement SANS bénéficiaire
 * — un décaissement qui n'éteint aucune dette — ou avec les DEUX, et l'on ne saurait alors
 * ni quelle dette il solde ni quelle écriture il produit : 6611 pour un salarié, 632 pour un
 * intermédiaire externe.
 *
 * On tient aussi la COHÉRENCE DE LA MAILLE. `Tranche` et `Avenant` sont tous deux enfants de
 * `Cotation` : rien dans le schéma n'empêche de les prendre dans deux affaires différentes.
 * Le versement porterait sur l'une et s'imputerait à l'échéance de l'autre — les deux soldes
 * seraient faux, sans qu'aucune erreur ne le dise.
 */
class ReversementBeneficiaireXorTest extends KernelTestCase
{
    private const OWNER_EMAIL = 'phpunit-xor-reversement@test.local';
    private const ENT = 'PHPUnit XOR Reversement SARL';

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

    private function inspecteur(): ChampsObligatoiresInspector
    {
        return static::getContainer()->get(ChampsObligatoiresInspector::class);
    }

    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email = :e', ['e' => self::OWNER_EMAIL]);
        $tables = [
            'reversement_retro_agent', 'tranche', 'avenant', 'cotation', 'piste',
            'client', 'risque', 'partenaire', 'invite',
        ];
        foreach ($tables as $table) {
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
     * DEUX affaires, pour pouvoir croiser une échéance de l'une avec l'avenant de l'autre.
     *
     * @return array{agent: Invite, partenaire: Partenaire, a1: Avenant, t1: Tranche, a2: Avenant, t2: Tranche, entreprise: Entreprise}
     */
    private function semer(): array
    {
        $em = $this->em();

        $owner = (new Utilisateur())->setEmail(self::OWNER_EMAIL)->setNom('Xor')->setVerified(true)->setPassword('x');
        $em->persist($owner);

        $ent = (new Entreprise())->setNom(self::ENT)->setLicence('LIC')->setAdresse('1 rue')
            ->setTelephone('+2430000')->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($owner);
        $em->persist($ent);
        $owner->setConnectedTo($ent);

        $gestionnaire = (new Invite())->setNom('Gestionnaire')->setProprietaire(true);
        $gestionnaire->setUtilisateur($owner)->setEntreprise($ent);
        $em->persist($gestionnaire);

        $agent = (new Invite())->setNom('Alice')->setProprietaire(false);
        $agent->setEntreprise($ent);
        $em->persist($agent);

        $partenaire = (new Partenaire())->setNom('SUNU Courtage')->setPart(20.0);
        $partenaire->setEntreprise($ent);
        $em->persist($partenaire);

        $risque = (new Risque())->setCode('XOR')->setNomComplet('Risque xor')
            ->setBranche(Risque::BRANCHE_IARD_OU_NON_VIE)->setImposable(true);
        $risque->setEntreprise($ent);
        $em->persist($risque);

        $faireAffaire = function (string $nom) use ($em, $ent, $gestionnaire, $risque): array {
            $client = (new Client())->setNom('Client ' . $nom)->setExonere(false);
            $client->setEntreprise($ent);
            $em->persist($client);

            $piste = (new Piste())->setNom('Affaire ' . $nom)->setTypeAvenant(Piste::AVENANT_SOUSCRIPTION)
                ->setDescriptionDuRisque('x')->setExercice((int) date('Y'))->setClient($client)->setRisque($risque);
            $piste->setEntreprise($ent)->setInvite($gestionnaire);
            $em->persist($piste);

            $cotation = (new Cotation())->setNom('Cotation ' . $nom)->setDuree(365);
            $cotation->setPiste($piste)->setEntreprise($ent);
            $em->persist($cotation);

            $avenant = (new Avenant())->setReferencePolice('POL-' . $nom)->setNumero('0')->setDescription('Police')
                ->setStartingAt(new \DateTimeImmutable('-30 days'))->setEndingAt(new \DateTimeImmutable('+335 days'));
            $avenant->setEntreprise($ent)->setInvite($gestionnaire);
            $cotation->addAvenant($avenant);
            $em->persist($avenant);

            $tranche = (new Tranche())->setNom('Échéance ' . $nom)->setPourcentage(100.0)
                ->setPayableAt(new \DateTimeImmutable('-30 days'))
                ->setEcheanceAt(new \DateTimeImmutable('+30 days'));
            $tranche->setCotation($cotation)->setEntreprise($ent);
            $em->persist($tranche);

            return [$avenant, $tranche];
        };

        [$a1, $t1] = $faireAffaire('UNE');
        [$a2, $t2] = $faireAffaire('DEUX');

        $em->flush();

        return [
            'agent' => $agent, 'partenaire' => $partenaire, 'entreprise' => $ent,
            'a1' => $a1, 't1' => $t1, 'a2' => $a2, 't2' => $t2,
        ];
    }

    private function reversement(array $s): ReversementRetroAgent
    {
        $r = (new ReversementRetroAgent())->setMontant(100.0)
            ->setPaidAt(new \DateTimeImmutable('now'))->setReference('VIR-XOR');
        $r->setEntreprise($s['entreprise'])->setInvite($s['agent']);

        return $r;
    }

    /** @return array<string, string[]> */
    private function refus(ReversementRetroAgent $r): array
    {
        return $this->inspecteur()->champsManquants($r, ['agent', 'partenaire', 'tranche', 'avenant', 'montant']);
    }

    // ===================== Le XOR du bénéficiaire =====================

    /** Un agent seul : accepté, et c'est le cas historique. */
    public function testUnAgentSeulEstAccepte(): void
    {
        $s = $this->semer();
        $r = $this->reversement($s)->setAgent($s['agent'])->setTranche($s['t1'])->setAvenant($s['a1']);

        self::assertTrue($r->estValide());
        self::assertSame([], $this->refus($r));
    }

    /** Un partenaire seul : accepté, et c'est ce que ce lot ouvre. */
    public function testUnPartenaireSeulEstAccepte(): void
    {
        $s = $this->semer();
        $r = $this->reversement($s)->setPartenaire($s['partenaire'])->setTranche($s['t1'])->setAvenant($s['a1']);

        self::assertTrue($r->estValide());
        self::assertSame([], $this->refus($r));
    }

    /**
     * LES DEUX À LA FOIS : refusé.
     *
     * On ne saurait ni quelle dette ce versement éteint, ni quelle écriture il produit.
     */
    public function testLesDeuxEnsembleSontRefuses(): void
    {
        $s = $this->semer();
        $r = $this->reversement($s)->setAgent($s['agent'])->setPartenaire($s['partenaire'])
            ->setTranche($s['t1'])->setAvenant($s['a1']);

        self::assertFalse($r->estValide());
        $refus = $this->refus($r);
        self::assertNotSame([], $refus, 'Deux bénéficiaires doivent être refusés.');
        self::assertStringContainsString('pas aux deux', implode(' ', $refus['agent'] ?? $refus['partenaire'] ?? []));
    }

    /**
     * AUCUN DES DEUX : refusé aussi — et cette garde est la seule, depuis que la colonne
     * `agent_id` tolère NULL pour laisser place au partenaire.
     */
    public function testAucunBeneficiaireEstRefuse(): void
    {
        $s = $this->semer();
        $r = $this->reversement($s)->setTranche($s['t1'])->setAvenant($s['a1']);

        self::assertFalse($r->estValide());
        $refus = $this->refus($r);
        self::assertNotSame([], $refus, 'Un versement sans bénéficiaire ne verse à personne.');
        self::assertStringContainsString('Désignez', implode(' ', $refus['agent'] ?? $refus['partenaire'] ?? []));
    }

    // ===================== La cohérence de la maille =====================

    /** Une échéance et un avenant de la MÊME affaire : accepté. */
    public function testMemeAffaireEstAcceptee(): void
    {
        $s = $this->semer();
        $r = $this->reversement($s)->setAgent($s['agent'])->setTranche($s['t1'])->setAvenant($s['a1']);

        self::assertTrue($r->mailleCoherente());
        self::assertSame([], $this->refus($r));
    }

    /**
     * DEUX AFFAIRES DIFFÉRENTES : refusé. Le versement porterait sur l'une et s'imputerait
     * à l'échéance de l'autre — les deux soldes seraient faux.
     */
    public function testCroiserDeuxAffairesEstRefuse(): void
    {
        $s = $this->semer();
        $r = $this->reversement($s)->setAgent($s['agent'])->setTranche($s['t1'])->setAvenant($s['a2']);

        self::assertFalse($r->mailleCoherente());
        self::assertNotSame([], $this->refus($r), 'Une échéance et une affaire étrangères doivent être refusées.');
    }

    /**
     * UNE SEULE MAILLE RENSEIGNÉE reste cohérente : la précision manque, la contradiction
     * non. C'est le cas des lignes antérieures au passage à l'échéance.
     */
    public function testUneSeuleMailleResteCoherente(): void
    {
        $s = $this->semer();

        $sansTranche = $this->reversement($s)->setAgent($s['agent'])->setAvenant($s['a1']);
        $sansAvenant = $this->reversement($s)->setAgent($s['agent'])->setTranche($s['t1']);

        self::assertTrue($sansTranche->mailleCoherente());
        self::assertTrue($sansAvenant->mailleCoherente());
    }

    // ===================== Le nom du bénéficiaire, source unique =====================

    /** Le nom se lit d'un seul appel, quelle que soit la famille. */
    public function testLeNomDuBeneficiaireSeLitSansConnaitreSaFamille(): void
    {
        $s = $this->semer();

        self::assertSame('Alice', $this->reversement($s)->setAgent($s['agent'])->beneficiaireNom());
        self::assertSame('SUNU Courtage', $this->reversement($s)->setPartenaire($s['partenaire'])->beneficiaireNom());
    }
}
