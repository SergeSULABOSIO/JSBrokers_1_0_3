<?php

namespace App\Tests\Ai;

use App\Ai\Mutation\PlanEnAttente;
use App\Ai\Scope\AiScope;
use App\Ai\Tool\AiToolResult;
use App\Ai\Tool\ContrePasserReversementTool;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\ReversementRetroAgent;
use App\Entity\Utilisateur;
use App\Service\Retro\ContrePassationDeReversement;
use App\Service\Retro\ReversementIntangible;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * KET SAIT ANNULER UN REVERSEMENT — par contre-passation, comme l'écran (2026-10-30).
 *
 *  1. l'outil PRÉPARE un plan à valider (barre de décision), et n'écrit rien ;
 *  2. le plan porte l'ACTE (`contrePasser: true`), rien d'autre ;
 *  3. un virement déjà annulé est refusé AVANT tout plan, en `bloquant` ;
 *  4. un identifiant d'un autre cabinet est « introuvable », jamais nommé ;
 *  5. la référence désigne le VIREMENT : ses trois lignes font un seul candidat.
 */
class ContrePasserReversementToolTest extends WebTestCase
{
    private const ENT = 'PHPUnit-KetContrePassation';
    private const AUTRE = 'PHPUnit-KetContrePassation-Autre';
    private const OWNER = 'phpunit-ketcp-owner@test.local';
    private const LOT = 'VIR-KET-CP';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private ContrePasserReversementTool $outil;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->outil = static::getContainer()->get(ContrePasserReversementTool::class);
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
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email = :e', ['e' => self::OWNER]);
        foreach ([self::ENT, self::AUTRE] as $nom) {
            $conn->executeStatement(
                'UPDATE reversement_retro_agent t JOIN entreprise e ON t.entreprise_id = e.id SET t.contre_passation_de_id = NULL WHERE e.nom = :n',
                ['n' => $nom],
            );
            foreach (['reversement_retro_agent', 'invite'] as $table) {
                $conn->executeStatement(
                    "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :n",
                    ['n' => $nom],
                );
            }
            $conn->executeStatement('DELETE FROM entreprise WHERE nom = :n', ['n' => $nom]);
        }
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :e', ['e' => self::OWNER]);
        $this->em->clear();
    }

    /**
     * Le cabinet : un virement de trois lignes à un agent. Un autre cabinet : un versement.
     *
     * @return array{ent: Entreprise, inv: Invite, lot: int[], etranger: int}
     */
    private function seed(): array
    {
        $em = $this->em;
        $user = (new Utilisateur())->setEmail(self::OWNER)->setNom('PHPUnit KetCP')->setVerified(true)->setPassword('x');
        $em->persist($user);

        $cabinets = [];
        foreach ([self::ENT, self::AUTRE] as $nom) {
            $ent = (new Entreprise())->setNom($nom)->setLicence('LIC')->setAdresse('1 rue')
                ->setTelephone('+243000000014')->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($user);
            $em->persist($ent);
            $agent = (new Invite())->setNom('Agent ' . $nom)->setProprietaire(false)->setEntreprise($ent);
            $em->persist($agent);
            $cabinets[$nom] = [$ent, $agent];
        }
        [$ent, $agent] = $cabinets[self::ENT];
        $user->setConnectedTo($ent);
        $inv = (new Invite())->setNom('Jean Kabila')->setUtilisateur($user)->setEntreprise($ent)->setProprietaire(true);
        $em->persist($inv);
        $em->flush();

        $ecrire = static function (Entreprise $e, Invite $a, float $montant, ?string $lot, string $ref) use ($em): ReversementRetroAgent {
            $r = (new ReversementRetroAgent())->setAgent($a)->setMontant($montant)
                ->setPaidAt(new \DateTimeImmutable('2026-08-01'))->setReference($ref)->setLotReference($lot);
            $r->setEntreprise($e)->setInvite($a);
            $em->persist($r);

            return $r;
        };
        $lot = [
            $ecrire($ent, $agent, 10.0, self::LOT, self::LOT),
            $ecrire($ent, $agent, 20.0, self::LOT, self::LOT),
            $ecrire($ent, $agent, 30.0, self::LOT, self::LOT),
        ];
        $etranger = $ecrire($cabinets[self::AUTRE][0], $cabinets[self::AUTRE][1], 5.0, null, 'AILLEURS');
        $em->flush();

        return [
            'ent' => $ent,
            'inv' => $inv,
            'lot' => array_map(static fn (ReversementRetroAgent $r) => $r->getId(), $lot),
            'etranger' => $etranger->getId(),
        ];
    }

    private function scope(array $s): AiScope
    {
        return new AiScope($s['ent'], $s['inv']);
    }

    private function nombreDAnnulations(): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM reversement_retro_agent WHERE contre_passation_de_id IS NOT NULL AND reference LIKE 'CP-%'",
        );
    }

    public function testPrepareUnPlanQuiPorteLActeEtNEcritRien(): void
    {
        $s = $this->seed();
        $avant = $this->nombreDAnnulations();

        $resultat = $this->outil->execute(['reversementId' => $s['lot'][2]], $this->scope($s));

        $this->assertSame(AiToolResult::STATUS_OK, $resultat->status, json_encode($resultat->data, JSON_UNESCAPED_UNICODE));
        $this->assertTrue($resultat->data['pret'] ?? false, json_encode($resultat->data));
        $this->assertSame(PlanEnAttente::ACTION_REVUE, $resultat->uiAction['type'] ?? null, 'La barre de décision.');
        $this->assertSame(['contrePasser' => true], $resultat->uiAction['plan'][0]['fields'] ?? null, 'L’acte, et rien d’autre.');
        $this->assertSame(3, $resultat->data['virement']['echeances'] ?? null);
        $this->assertEqualsWithDelta(60.0, $resultat->data['virement']['total'] ?? 0, 0.001);
        $this->assertSame($avant, $this->nombreDAnnulations(), 'Préparer n’écrit rien.');
    }

    public function testLaReferenceDesigneLeVirementEntier(): void
    {
        $s = $this->seed();

        $resultat = $this->outil->execute(['reference' => self::LOT], $this->scope($s));

        $this->assertTrue($resultat->data['pret'] ?? false, 'Trois lignes, un seul virement : aucune ambiguïté.');
    }

    public function testUnVirementDejaAnnuleEstRefuseAvantToutPlan(): void
    {
        $s = $this->seed();
        static::getContainer()->get(ContrePassationDeReversement::class)
            ->contrePasser($this->em->find(ReversementRetroAgent::class, $s['lot'][0]), $s['inv']);

        $resultat = $this->outil->execute(['reversementId' => $s['lot'][1]], $this->scope($s));

        $this->assertFalse($resultat->data['pret'] ?? true);
        $this->assertSame(ReversementIntangible::DEJA_CONTRE_PASSE, $resultat->data['bloquant'] ?? null);
        $this->assertNull($resultat->uiAction, 'Sans plan, aucun bouton.');
    }

    public function testUnReversementDUnAutreCabinetEstIntrouvable(): void
    {
        $s = $this->seed();

        $resultat = $this->outil->execute(['reversementId' => $s['etranger']], $this->scope($s));

        $this->assertFalse($resultat->data['pret'] ?? true);
        $this->assertStringContainsString('Aucun reversement', (string) ($resultat->data['bloquant'] ?? ''));
        $this->assertStringNotContainsString('AILLEURS', json_encode($resultat->data), 'Rien de l’autre cabinet n’est nommé.');
    }
}
