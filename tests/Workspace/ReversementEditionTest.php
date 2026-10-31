<?php

namespace App\Tests\Workspace;

use App\Entity\Avenant;
use App\Entity\Client;
use App\Entity\Cotation;
use App\Entity\Document;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Piste;
use App\Entity\ReversementRetroAgent;
use App\Entity\Risque;
use App\Entity\Utilisateur;
use App\Service\Retro\LotDeVersement;
use App\Service\Retro\ReversementIntangible;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * ROUVRIR UN VIREMENT DÉJÀ ENREGISTRÉ — une PIÈCE COMPTABLE (Serge, 2026-10-30).
 *
 * ── POURQUOI CETTE FENÊTRE ET PAS LE DIALOGUE GÉNÉRIQUE ─────────────────────────────
 * Depuis que la rubrique replie chaque lot sur son porteur, une ligne REPRÉSENTE un
 * virement entier. Le dialogue générique n'en aurait montré qu'une échéance sur six.
 *
 * ── LES RÈGLES QUE CE TEST TIENT (ReversementIntangible) ────────────────────────────
 *  1. LA PARTIE COMPTABLE NE BOUGE PLUS : retirer une échéance, changer un montant, la date
 *     ou le compte est refusé en 409 qui explique — et RIEN n'est écrit ni retiré.
 *  2. RESTENT CORRIGEABLES : la référence, la description, les pièces.
 *  3. LA PREUVE : retirer la dernière pièce sans en annoncer une autre est refusé, et aucune
 *     pièce n'est détruite par une requête refusée.
 *  4. LA FENÊTRE LE DIT : en lecture pour la partie comptable, elle oriente vers la
 *     contre-passation.
 */
class ReversementEditionTest extends WebTestCase
{
    private const OWNER_EMAIL = 'phpunit-vir-owner@test.local';
    private const ENTREPRISE_NOM = 'PHPUnit Virement SARL';
    private const LOT = 'VIR-2026-EDIT';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
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
        // Une contre-passation retient l'original (RESTRICT) : le lien se dénoue d'abord.
        $conn->executeStatement(
            'UPDATE reversement_retro_agent t JOIN entreprise e ON t.entreprise_id = e.id
             SET t.contre_passation_de_id = NULL WHERE e.nom = :nom',
            ['nom' => self::ENTREPRISE_NOM],
        );
        foreach ([
            'document', 'reversement_retro_agent', 'avenant', 'cotation', 'piste',
            'client', 'risque', 'invite',
        ] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :nom",
                ['nom' => self::ENTREPRISE_NOM],
            );
        }
        // LE WORKSPACE ACTIF POINTE SUR L'ENTREPRISE : on dénoue le lien avant de la
        // supprimer, sinon la contrainte de clé étrangère refuse.
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
     * Un virement de trois échéances — 10, 20, 30 — dont le PORTEUR tient le bordereau.
     * Chaque ligne désigne une affaire distincte : le rapprochement se fait sur le couple
     * (échéance, affaire).
     *
     * @return array{ids: int[], avenantIds: int[], entrepriseId: int}
     */
    private function semer(): array
    {
        $em = $this->em();

        $owner = (new Utilisateur())->setEmail(self::OWNER_EMAIL)->setNom('Virement')
            ->setVerified(true)->setPassword('x');
        $em->persist($owner);

        $entreprise = (new Entreprise())->setNom(self::ENTREPRISE_NOM)->setLicence('LIC')
            ->setAdresse('1 rue')->setTelephone('+2430000')->setRccm('R')->setIdnat('I')->setNumimpot('N')
            ->setUtilisateur($owner);
        $em->persist($entreprise);
        $owner->setConnectedTo($entreprise);

        $gestionnaire = (new Invite())->setNom('Gestionnaire')->setProprietaire(true);
        $gestionnaire->setUtilisateur($owner)->setEntreprise($entreprise);
        $em->persist($gestionnaire);

        $agent = (new Invite())->setNom('Alice Virement')->setProprietaire(false)->setEntreprise($entreprise);
        $em->persist($agent);
        $em->flush();

        $risque = (new Risque())->setCode('VIR')->setNomComplet('Risque Virement')
            ->setDescription('Risque du virement')->setBranche(Risque::BRANCHE_IARD_OU_NON_VIE)
            ->setImposable(true);
        $risque->setEntreprise($entreprise);
        $em->persist($risque);

        $clientAssure = (new Client())->setNom('Client Virement')->setExonere(false);
        $clientAssure->setEntreprise($entreprise);
        $em->persist($clientAssure);

        $avenants = [];
        for ($i = 0; $i < 3; ++$i) {
            $piste = (new Piste())->setNom('Piste Virement ' . $i)->setTypeAvenant(0)
                ->setDescriptionDuRisque('Risque virement')->setExercice((int) date('Y'))
                ->setClient($clientAssure)->setRisque($risque);
            $piste->setEntreprise($entreprise)->setInvite($gestionnaire);
            $em->persist($piste);

            $cotation = (new Cotation())->setNom('Cotation Virement ' . $i)->setDuree(365);
            $cotation->setPiste($piste)->setEntreprise($entreprise);
            $em->persist($cotation);

            $avenant = (new Avenant())->setReferencePolice('POL-VIR-' . $i)->setNumero('0')
                ->setDescription('Police virement')
                ->setStartingAt(new \DateTimeImmutable('-30 days'))
                ->setEndingAt(new \DateTimeImmutable('+335 days'));
            $avenant->setEntreprise($entreprise)->setInvite($gestionnaire);
            $cotation->addAvenant($avenant);
            $em->persist($avenant);
            $avenants[] = $avenant;
        }
        $em->flush();

        $membres = [];
        foreach ([10.0, 20.0, 30.0] as $i => $montant) {
            $r = (new ReversementRetroAgent())
                ->setAgent($agent)
                ->setAvenant($avenants[$i])
                ->setMontant($montant)
                ->setPaidAt(new \DateTimeImmutable('2026-08-01'))
                ->setReference(self::LOT)
                ->setLotReference(self::LOT)
                ->setDescription('Virement de septembre, convenu par téléphone.');
            $r->setEntreprise($entreprise)->setInvite($agent);
            $em->persist($r);
            $membres[] = $r;
        }
        $em->flush();

        $ids = [];
        $avenantIds = [];
        foreach ($membres as $membre) {
            $ids[] = $membre->getId();
            $avenantIds[] = $membre->getAvenant()->getId();
        }

        // LE BORDEREAU EST SUR LE PORTEUR — le plus petit id.
        $piece = (new Document())->setNom('bordereau.pdf');
        $piece->setReversementRetroAgent($em->getRepository(ReversementRetroAgent::class)->find($ids[0]));
        $piece->setEntreprise($entreprise)->setInvite($agent);
        $em->persist($piece);
        $em->flush();

        return ['ids' => $ids, 'avenantIds' => $avenantIds, 'entrepriseId' => $entreprise->getId()];
    }

    /** Les trois lignes du virement, telles qu'enregistrées — ce que la fenêtre renvoie. */
    private function lignesEnregistrees(array $s): array
    {
        return [
            ['trancheId' => 0, 'avenantId' => $s['avenantIds'][0], 'montant' => 10.0],
            ['trancheId' => 0, 'avenantId' => $s['avenantIds'][1], 'montant' => 20.0],
            ['trancheId' => 0, 'avenantId' => $s['avenantIds'][2], 'montant' => 30.0],
        ];
    }

    /**
     * @param array<string, mixed> $surcharges clés du corps à remplacer ou ajouter
     */
    private function poster(int $id, array $s, array $surcharges = []): array
    {
        $corps = $surcharges + [
            'lignes' => $this->lignesEnregistrees($s),
            'paidAt' => '2026-08-01',
            'reference' => self::LOT,
            'piecesRetirees' => [],
            'avecPiece' => false,
        ];
        $this->client->request(
            'POST',
            '/admin/retro-agent/reversement/' . $id . '/editer',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($corps),
        );

        $reponse = $this->client->getResponse();
        $decode = json_decode((string) $reponse->getContent(), true);

        return is_array($decode) ? $decode : ['__statut' => $reponse->getStatusCode()];
    }

    private function connecter(): void
    {
        $utilisateur = $this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => self::OWNER_EMAIL]);
        $this->client->loginUser($utilisateur);
    }

    /** @return array<int, ReversementRetroAgent> */
    private function relire(array $s): array
    {
        $this->em()->clear();

        return array_map(
            fn (int $id) => $this->em()->getRepository(ReversementRetroAgent::class)->find($id),
            $s['ids'],
        );
    }

    /** LES MEMBRES DU LOT se lisent depuis n'importe laquelle de ses lignes ; le porteur est le plus petit id. */
    public function testLeLotSeLitDeNimporteLequelDeSesMembres(): void
    {
        $s = $this->semer();
        /** @var LotDeVersement $lots */
        $lots = static::getContainer()->get(LotDeVersement::class);
        $repo = $this->em()->getRepository(ReversementRetroAgent::class);

        foreach ($s['ids'] as $id) {
            $membres = $lots->membresDuLot($repo->find($id));
            self::assertCount(3, $membres, 'Le virement se lit entier depuis chacune de ses lignes.');
            self::assertSame($s['ids'][0], $membres[0]->getId(), 'Le porteur est le plus petit id.');
        }
    }

    /** RETIRER UNE ÉCHÉANCE DU VIREMENT est refusé : les trois lignes et le bordereau restent. */
    public function testRetirerUneEcheanceDuVirementEstRefuse(): void
    {
        $s = $this->semer();
        $this->connecter();

        $reponse = $this->poster($s['ids'][0], $s, [
            'lignes' => [['trancheId' => 0, 'avenantId' => $s['avenantIds'][1], 'montant' => 20.0]],
        ]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(ReversementIntangible::MOTIF, $reponse['message'] ?? null, 'Le refus explique et oriente vers la contre-passation.');
        self::assertNotContains(null, $this->relire($s), 'Aucune ligne du virement n’a disparu.');
        self::assertCount(1, $this->em()->getRepository(Document::class)->findBy(['nom' => 'bordereau.pdf']));
    }

    /** CHANGER UN MONTANT est refusé. */
    public function testChangerUnMontantEstRefuse(): void
    {
        $s = $this->semer();
        $this->connecter();
        $lignes = $this->lignesEnregistrees($s);
        $lignes[0]['montant'] = 42.0;

        $this->poster($s['ids'][0], $s, ['lignes' => $lignes]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(10.0, $this->relire($s)[0]->getMontant());
    }

    /**
     * CHANGER LA DATE est refusé — et la pièce marquée pour retrait dans la MÊME requête
     * n'est pas détruite : un refus n'écrit rien.
     */
    public function testChangerLaDateEstRefuseEtRienNEstRetire(): void
    {
        $s = $this->semer();
        $this->connecter();
        $pieceId = $this->em()->getRepository(Document::class)->findOneBy(['nom' => 'bordereau.pdf'])->getId();

        $this->poster($s['ids'][0], $s, ['paidAt' => '2026-09-15', 'piecesRetirees' => [$pieceId], 'avecPiece' => true]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('2026-08-01', $this->relire($s)[0]->getPaidAt()?->format('Y-m-d'));
        self::assertNotNull($this->em()->getRepository(Document::class)->find($pieceId), 'La pièce est intacte.');
    }

    /** LA RÉFÉRENCE ET LA DESCRIPTION SE CORRIGENT, sur toutes les lignes du virement. */
    public function testLaReferenceEtLaDescriptionSeCorrigent(): void
    {
        $s = $this->semer();
        $this->connecter();

        $this->poster($s['ids'][1], $s, ['reference' => 'VIR-CORRIGEE', 'description' => 'Référence bancaire corrigée.']);

        self::assertResponseIsSuccessful();
        foreach ($this->relire($s) as $ligne) {
            self::assertSame('VIR-CORRIGEE', $ligne->getReference());
            self::assertSame('Référence bancaire corrigée.', $ligne->getDescription());
            self::assertSame(self::LOT, $ligne->getLotReference(), 'Le lot, lui, ne bouge pas : il fait le virement.');
        }
    }

    /** SANS DESCRIPTION ENVOYÉE, la description reste : on n'écrit pas ce qu'on ne reçoit pas. */
    public function testSansDescriptionEnvoyeeLaDescriptionReste(): void
    {
        $s = $this->semer();
        $this->connecter();

        $this->poster($s['ids'][0], $s);

        self::assertResponseIsSuccessful();
        self::assertSame('Virement de septembre, convenu par téléphone.', $this->relire($s)[0]->getDescription());
    }

    /** Une description trop longue (ou qui n'est pas un texte) est refusée en 422, et rien n'est écrit. */
    public function testUneDescriptionTropLongueEstRefusee(): void
    {
        $s = $this->semer();
        $this->connecter();

        $this->poster($s['ids'][0], $s, ['description' => str_repeat('x', 256), 'reference' => 'VIR-NE-DOIT-PAS-PASSER']);
        self::assertResponseStatusCodeSame(422);
        $this->poster($s['ids'][0], $s, ['description' => ['pas', 'un', 'texte']]);
        self::assertResponseStatusCodeSame(422);

        self::assertSame(self::LOT, $this->relire($s)[0]->getReference(), 'Le refus n’a rien écrit.');
    }

    /** La fenêtre s'ouvre en LECTURE pour la partie comptable, sur les seules échéances du virement. */
    public function testLaFenetreSOuvreEnLecture(): void
    {
        $s = $this->semer();
        $this->connecter();

        $this->client->request('GET', '/admin/retro-agent/reversement/' . $s['ids'][0] . '/editer');

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('data-controller="reversement-retro-picker"', $html);
        self::assertStringContainsString('data-reversement-retro-picker-edition-value="true"', $html);
        self::assertStringContainsString(self::LOT, $html);
        self::assertStringContainsString('Contre-passer le virement', $html, 'Elle dit comment corriger le reste.');
        self::assertStringNotContainsString('Solde exigible', $html, 'Rien à verser : on relit un virement.');
        self::assertSame(3, substr_count($html, 'data-reversement-retro-picker-target="ligne"'), 'Les trois échéances, et elles seules.');
        self::assertMatchesRegularExpression('/type="date"[^>]*readonly/s', $html, 'La date est en lecture.');
        self::assertMatchesRegularExpression('/<select id="rra-compte"[^>]*disabled/s', $html, 'Le compte est en lecture.');
        self::assertStringContainsString('data-reversement-retro-picker-target="description"', $html, 'La description se corrige.');
        // La pièce déjà déposée est nommée, avec de quoi la retirer.
        self::assertStringContainsString('bordereau.pdf', $html);
        self::assertStringContainsString('click->reversement-retro-picker#basculerPiece', $html);
    }

    /** REMPLACER UNE PIÈCE : la retirer et en annoncer une autre dans le même geste. */
    public function testUnePieceSeRetirePourEtreRemplacee(): void
    {
        $s = $this->semer();
        $this->connecter();
        $pieceId = $this->em()->getRepository(Document::class)->findOneBy(['nom' => 'bordereau.pdf'])->getId();

        $this->poster($s['ids'][0], $s, ['piecesRetirees' => [$pieceId], 'avecPiece' => true]);

        self::assertResponseIsSuccessful();
        $this->em()->clear();
        self::assertNull($this->em()->getRepository(Document::class)->find($pieceId), 'La pièce marquée est supprimée.');
    }

    /** ⚠ RETIRER LA DERNIÈRE PREUVE SANS EN DÉPOSER UNE AUTRE est refusé, et la pièce reste. */
    public function testRetirerLaDernierePreuveSansLaRemplacerEstRefuse(): void
    {
        $s = $this->semer();
        $this->connecter();
        $pieceId = $this->em()->getRepository(Document::class)->findOneBy(['nom' => 'bordereau.pdf'])->getId();

        $this->poster($s['ids'][0], $s, ['piecesRetirees' => [$pieceId]]);

        self::assertResponseStatusCodeSame(422, 'Un virement ne reste pas sans preuve.');
        $this->em()->clear();
        self::assertNotNull($this->em()->getRepository(Document::class)->find($pieceId), 'Rien n’a été retiré avant le refus.');
    }
}
