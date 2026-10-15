<?php

namespace App\Tests\Workspace;

use App\Ai\Mutation\MutationOperation;
use App\Ai\Scope\AiScope;
use App\Entity\Article;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Note;
use App\Entity\RevenuPourCourtier;
use App\Entity\Tranche;
use App\Entity\TypeRevenu;
use App\Entity\Utilisateur;
use App\Service\Note\EmissionDeNote;
use App\Service\Workspace\WorkspaceMutationService;
use App\Services\CanvasBuilder;
use App\Services\Note\SourceDeFacturation;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * UNE NOTE VALIDÉE NE SE MODIFIE PLUS ET NE SE SUPPRIME PAS (règle OHADA, décision de
 * Serge du 2026-10-15) — à l'écran, par Ket, et en dernier rempart au flush. Seuls ses
 * paiements et ses documents restent ouverts ; toute correction passe par un avoir.
 *
 * Et l'écart que laisse une correction de prime ou de taux APRÈS la facturation devient
 * visible et explicable : « à facturer », ou « à créditer » par un avoir.
 */
class IntangibiliteDesNotesTest extends WebTestCase
{
    use AffaireFacturableTrait;

    private const EMAIL = 'phpunit-intangible@test.local';
    private const CABINET = 'PHPUnit Intangible SARL';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        parent::tearDown();
    }

    private function cleanUp(): void
    {
        $this->effacerLesCabinets([self::CABINET], [self::EMAIL]);
    }

    /** Un cabinet connecté et une note validée (1 160 TTC). */
    private function noteValidee(): array
    {
        $c = $this->semerUneAffaire(self::CABINET, self::EMAIL);
        $this->client->loginUser($this->em()->find(Utilisateur::class, $c['owner']));
        $id = $this->brouillon($c, 'Émise');
        static::getContainer()->get(EmissionDeNote::class)->valider($this->note($id));

        return [$c, $id];
    }

    private function json(string $methode, string $url, array $champs = []): array
    {
        $this->client->request($methode, $url, $champs);
        $reponse = $this->client->getResponse();

        return ['statut' => $reponse->getStatusCode(), 'corps' => json_decode((string) $reponse->getContent(), true) ?? []];
    }

    public function testLEcranRefuseDeModifierUneNoteValideeEtSesLignes(): void
    {
        [$c, $id] = $this->noteValidee();
        $note = $this->note($id);

        $edition = $this->json('POST', '/admin/note/api/submit', [
            'id' => (string) $id, 'nom' => 'Renommée', 'type' => (string) Note::TYPE_NOTE_DE_DEBIT,
            'addressedTo' => (string) Note::TO_ASSUREUR, 'assureur' => (string) $c['assureur'],
        ]);
        self::assertSame(409, $edition['statut']);
        self::assertStringContainsString('est validée : une pièce comptable émise ne se modifie plus', $edition['corps']['message']);
        self::assertStringContainsString('note de crédit (avoir)', $edition['corps']['message']);

        $ajout = $this->json('POST', '/admin/article/api/submit', [
            'note' => (string) $id, 'revenuFacture' => (string) $c['revenu'], 'tranche' => (string) $c['tranche'], 'quantite' => '1',
        ]);
        self::assertSame(409, $ajout['statut'], json_encode($ajout['corps'], JSON_UNESCAPED_UNICODE));

        $this->em()->clear();
        self::assertSame('Émise', $this->note($id)->getNom());
        self::assertCount(1, $this->note($id)->getArticles());
        self::assertSame($note->getReference(), $this->note($id)->getReference());
    }

    /** Le dialogue d'une note validée s'ouvre en lecture, et dit pourquoi. */
    public function testLeDialogueDUneNoteValideeEstEnLecture(): void
    {
        [, $id] = $this->noteValidee();

        $this->client->request('GET', '/admin/note/api/get-form/' . $id);
        $html = (string) $this->client->getResponse()->getContent();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('ne se modifie plus', $html);
        self::assertStringNotContainsString('/admin/note/api/submit', $html, 'Aucun enregistrement possible.');
    }

    public function testUneNoteValideeNeSeSupprimePasMemeImpayee(): void
    {
        [$c, $id] = $this->noteValidee();

        $directe = $this->json('DELETE', '/admin/note/api/delete/' . $id);
        self::assertSame(409, $directe['statut']);
        self::assertStringContainsString('ne se modifie plus', $directe['corps']['message'] ?? '');

        // Emportée par la suppression de son échéance : refusée aussi.
        $parCascade = $this->json('DELETE', '/admin/tranche/api/delete/' . $c['tranche']);
        self::assertSame(409, $parCascade['statut']);

        self::assertNotNull($this->note($id));
    }

    public function testUnBrouillonResteModifiableEtSupprimable(): void
    {
        $c = $this->semerUneAffaire(self::CABINET, self::EMAIL);
        $this->client->loginUser($this->em()->find(Utilisateur::class, $c['owner']));
        $id = $this->brouillon($c, 'Brouillon');

        $edition = $this->json('POST', '/admin/note/api/submit', [
            'id' => (string) $id, 'nom' => 'Brouillon renommé', 'type' => (string) Note::TYPE_NOTE_DE_DEBIT,
            'addressedTo' => (string) Note::TO_ASSUREUR, 'assureur' => (string) $c['assureur'],
        ]);
        self::assertSame(200, $edition['statut'], json_encode($edition['corps'], JSON_UNESCAPED_UNICODE));
        self::assertSame(200, $this->json('DELETE', '/admin/note/api/delete/' . $id)['statut']);
    }

    /** Ket se heurte au même blocage — et garde le droit d'encaisser. */
    public function testKetEstBloqueMaisPeutEncaisser(): void
    {
        [$c, $id] = $this->noteValidee();
        $em = $this->em();
        $service = static::getContainer()->get(WorkspaceMutationService::class);
        $scope = new AiScope($em->find(Entreprise::class, $c['entreprise']), $em->find(Invite::class, $c['invite']));

        $edition = $service->analyserOperation(new MutationOperation('edit', 'Note', $id, ['nom' => 'Par Ket']), $scope);
        self::assertSame('bloque', $edition['statut']);
        self::assertStringContainsString('ne se modifie plus', json_encode($edition, JSON_UNESCAPED_UNICODE));

        // Une ligne n'entre chez Ket que par la collection de sa note (Article seul est hors
        // de son périmètre) : ajoutée à une note validée, elle est bloquée.
        $ligne = $service->analyserOperation(new MutationOperation(
            op: 'edit', entityShortName: 'Note', targetId: $id, fields: [],
            collections: ['articles' => [new MutationOperation(op: 'create', entityShortName: 'Article',
                fields: ['revenuFacture' => $c['revenu'], 'tranche' => $c['tranche'], 'quantite' => 1])]],
        ), $scope);
        self::assertSame('bloque', $ligne['statut']);

        $paiement = $service->analyserOperation(new MutationOperation('create', 'Paiement', null,
            ['note' => $id, 'montant' => 500, 'paidAt' => (new \DateTimeImmutable())->format('Y-m-d')]), $scope);
        self::assertNotSame('bloque', $paiement['statut'] ?? null, 'Un paiement s’ajoute toujours.');
    }

    /** LE DERNIER REMPART : une écriture directe (porte oubliée) est refusée au flush. */
    public function testLeFlushRefuseToutChangementDUneNoteValidee(): void
    {
        [, $id] = $this->noteValidee();

        foreach (['renommer' => fn (Note $n) => $n->setNom('Directe'), 'dévalider' => fn (Note $n) => $n->setValidated(false)] as $cas => $geste) {
            $note = $this->note($id);
            $geste($note);
            try {
                $this->em()->flush();
                self::fail('Le flush doit refuser : ' . $cas);
            } catch (\LogicException $refus) {
                self::assertStringContainsString('ne se modifie plus', $refus->getMessage(), $cas);
            }
            static::getContainer()->get('doctrine')->resetManager();
        }

        $note = $this->note($id);
        self::assertSame('Émise', $note->getNom());
        self::assertTrue($note->isValidated());
    }

    /**
     * L'ÉCART APRÈS FACTURATION : le taux baisse de 10 à 8 %, la note émise reste à 1 160 ;
     * le dû tombe à 928, et 232 sont « à créditer » — proposés par l'avoir, et dits sur la
     * fiche du revenu. L'avoir émis, l'écart disparaît.
     */
    public function testUnTauxBaisseApresFacturationFaitApparaitreUnEcartACrediter(): void
    {
        [$c, $id] = $this->noteValidee();
        $em = $this->em();
        $em->find(TypeRevenu::class, $c['type'])->setPourcentage(8.0);
        $em->flush();
        $em->clear();

        $source = static::getContainer()->get(SourceDeFacturation::class);
        $credit = $source->pesee($this->em()->find(Entreprise::class, $c['entreprise']), Note::TO_ASSUREUR, $c['assureur'],
            $this->em()->find(Tranche::class, $c['tranche']), Note::TYPE_NOTE_DE_CREDIT);
        self::assertCount(1, $credit['retenus']);
        self::assertEqualsWithDelta(232.0, $credit['retenus'][0]['montant'], 0.01, 'L’excédent facturé, pas toute la note.');
        // L'unité d'un avoir est ce qui a été ÉMIS (1 160), pas le dû du moment : la quantité
        // que l'écran ET Ket posent rend exactement l'excédent.
        self::assertEqualsWithDelta(1160.0, $credit['retenus'][0]['unitaire'], 0.01);
        self::assertEqualsWithDelta(0.2, $source->quantitePour(232.0, 1160.0, null), 0.0001);

        $revenu = $this->em()->find(RevenuPourCourtier::class, $c['revenu']);
        static::getContainer()->get(CanvasBuilder::class)->loadAllCalculatedValues($revenu);
        self::assertSame('À créditer : 232,00 par un avoir (facturé 1 160,00, dû aujourd’hui 928,00).', $revenu->ecartDeFacturation);

        // L'avoir de 232 (0,2 du facturé de 1 160) solde l'écart.
        $em = $this->em();
        $avoir = (new Note())->setNom('Avoir')->setType(Note::TYPE_NOTE_DE_CREDIT)->setAddressedTo(Note::TO_ASSUREUR)
            ->setAssureur($em->find(\App\Entity\Assureur::class, $c['assureur']))->setSignature('t')->setValidated(false);
        $avoir->setEntreprise($em->find(Entreprise::class, $c['entreprise']))->setInvite($em->find(Invite::class, $c['invite']));
        $ligne = (new Article())->setQuantite(0.2)->setRevenuFacture($em->find(RevenuPourCourtier::class, $c['revenu']))
            ->setTranche($em->find(Tranche::class, $c['tranche']));
        $ligne->setEntreprise($avoir->getEntreprise());
        $avoir->addArticle($ligne);
        static::getContainer()->get(EmissionDeNote::class)->valider($avoir);

        $revenu = $this->note($id) ? $this->em()->find(RevenuPourCourtier::class, $c['revenu']) : null;
        static::getContainer()->get(\App\Services\Canvas\Indicator\IndicatorCalculationHelper::class)->reset();
        static::getContainer()->get(CanvasBuilder::class)->loadAllCalculatedValues($revenu);
        self::assertNull($revenu->ecartDeFacturation, 'L’avoir émis, il ne reste aucun écart.');
    }

    /** Le dû tombé à ZÉRO (taux retiré) : l'avoir reste possible — il se mesure à l'émis. */
    public function testUnAvoirResteFaisableQuandLeDuTombeAZero(): void
    {
        [$c] = $this->noteValidee();
        $em = $this->em();
        $em->find(TypeRevenu::class, $c['type'])->setPourcentage(null);
        $em->flush();
        $em->clear();

        $credit = static::getContainer()->get(SourceDeFacturation::class)->pesee($this->em()->find(Entreprise::class, $c['entreprise']),
            Note::TO_ASSUREUR, $c['assureur'], $this->em()->find(Tranche::class, $c['tranche']), Note::TYPE_NOTE_DE_CREDIT);
        self::assertEqualsWithDelta(1160.0, $credit['retenus'][0]['montant'] ?? 0.0, 0.01, 'Tout le facturé est à créditer.');
        self::assertEqualsWithDelta(1160.0, $credit['retenus'][0]['unitaire'] ?? 0.0, 0.01, 'Et l’unité n’est pas nulle.');
    }

    /**
     * SUPPRIMER LE BORDEREAU d'une note validée la laisserait debout mais modifiée (sans
     * bordereau, sans montant) : refusé — le détachement passe hors du flush.
     */
    public function testLeBordereauDUneNoteValideeNeSeSupprimePas(): void
    {
        $c = $this->semerUneAffaire(self::CABINET, self::EMAIL);
        $this->client->loginUser($this->em()->find(Utilisateur::class, $c['owner']));
        $em = $this->em();
        $cabinet = $em->find(Entreprise::class, $c['entreprise']);
        $invite = $em->find(Invite::class, $c['invite']);
        $bordereau = (new \App\Entity\Bordereau())->setType(0)->setNom('Bordereau')->setReference('BRD-INT')
            ->setReceivedAt(new \DateTimeImmutable('-5 days'))->setPeriodeDebut(new \DateTimeImmutable('-40 days'))
            ->setPeriodeFin(new \DateTimeImmutable('-10 days'))->setMontantComHtPayableNow(500.0)->setMontantTaxePayableNow(80.0);
        $bordereau->setInvite($invite)->setEntreprise($cabinet);
        $em->persist($bordereau);
        $note = (new Note())->setNom('Note de bordereau')->setType(Note::TYPE_NOTE_DE_DEBIT)->setAddressedTo(Note::TO_ASSUREUR)
            ->setAssureur($em->find(\App\Entity\Assureur::class, $c['assureur']))->setSignature('t')->setValidated(false)->setBordereau($bordereau);
        $note->setEntreprise($cabinet)->setInvite($invite);
        $em->persist($note);
        $em->flush();
        static::getContainer()->get(EmissionDeNote::class)->valider($note);

        $refus = $this->json('DELETE', '/admin/bordereau/api/delete/' . $bordereau->getId());
        self::assertSame(409, $refus['statut'], json_encode($refus['corps'], JSON_UNESCAPED_UNICODE));
        self::assertStringContainsString('modifierait une note émise', $refus['corps']['message'] ?? '');
        self::assertNotNull($this->note((int) $note->getId())->getBordereau());
    }

    public function testUnTauxMonteApresFacturationLaisseUnEcartAFacturer(): void
    {
        [$c] = $this->noteValidee();
        $em = $this->em();
        $em->find(TypeRevenu::class, $c['type'])->setPourcentage(12.0);
        $em->flush();
        $em->clear();

        $revenu = $this->em()->find(RevenuPourCourtier::class, $c['revenu']);
        static::getContainer()->get(CanvasBuilder::class)->loadAllCalculatedValues($revenu);
        self::assertSame('À facturer : 232,00 (dû aujourd’hui 1 392,00, déjà facturé 1 160,00).', $revenu->ecartDeFacturation);
    }
}
