<?php

namespace App\Tests\Workspace;

use App\Comptabilite\CourtierEcritureComptableService;
use App\Comptabilite\PlanComptable;
use App\Entity\Article;
use App\Entity\Assureur;
use App\Entity\Avenant;
use App\Entity\Chargement;
use App\Entity\ChargementPourPrime;
use App\Entity\Client;
use App\Entity\CompteBancaire;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Note;
use App\Entity\Piste;
use App\Entity\Portefeuille;
use App\Entity\RevenuPourCourtier;
use App\Entity\Tranche;
use App\Entity\TypeRevenu;
use App\Entity\Utilisateur;
use App\Services\Canvas\Indicator\IndicatorCalculationHelper;
use App\Services\Note\NoteRecouvrementService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * FACTURER UNE COMMISSION DEPUIS L'ÉCRAN : la note ET ses lignes, en un seul geste.
 *
 * ── CE QUE CE TEST PROTÈGE ──────────────────────────────────────────────────
 * L'écran ne savait poser que l'EN-TÊTE d'une note : la collection `articles` est
 * `mapped: false`, et ses enfants naissent dans un dialogue séparé, après que le
 * parent existe. Le courtier obtenait donc une note vide dont il composait les
 * lignes une à une, là où l'assistant posait les deux en un seul plan.
 *
 * Ce qui se vérifie ici est donc précisément ce qui manquait : que la LIGNE existe,
 * rattachée au bon revenu et à la bonne échéance, et que la note parte VALIDÉE —
 * sans quoi elle n'entrerait jamais au suivi du recouvrement.
 *
 * ── ET LE DOUBLON, QUI EST LE VRAI RISQUE DE CET ÉCRAN ──────────────────────
 * La fenêtre coche tout d'office. Si « facturable » continuait de vouloir dire
 * « impayé », un second passage refacturerait ce qui vient de l'être — une double
 * facturation en un clic. Le dernier cas de ce fichier est là pour cela.
 */
class FacturationEcritureTest extends WebTestCase
{
    private const OWNER_EMAIL = 'phpunit-facturation-ecran@test.local';
    private const ENTREPRISE_NOM = 'PHPUnit Facturation Ecran SARL';
    private const PASSWORD = 'Test1234!';

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

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();

        // La table de jonction d'abord : elle ne porte pas d'entreprise, elle se vide
        // par la note qu'elle rattache.
        $conn->executeStatement(
            'DELETE nc FROM note_compte_bancaire nc
             JOIN note n ON nc.note_id = n.id
             JOIN entreprise e ON n.entreprise_id = e.id
             WHERE e.nom = :nom',
            ['nom' => self::ENTREPRISE_NOM],
        );

        $tables = [
            'article', 'note', 'revenu_pour_courtier', 'tranche', 'chargement_pour_prime',
            'avenant', 'cotation', 'piste', 'client', 'portefeuille', 'type_revenu',
            'chargement', 'assureur', 'compte_bancaire', 'invite',
        ];
        foreach ($tables as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :nom",
                ['nom' => self::ENTREPRISE_NOM],
            );
        }
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email = :email', ['email' => self::OWNER_EMAIL]);
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :nom', ['nom' => self::ENTREPRISE_NOM]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :email', ['email' => self::OWNER_EMAIL]);
    }

    /**
     * Une affaire souscrite chez ACTIVA, commission de 10 points jamais facturée, et
     * un compte bancaire pour que la note dise où virer.
     *
     * @return array{entreprise: Entreprise, trancheId: int, revenuId: int, assureurId: int, compteId: int}
     */
    private function seed(): array
    {
        $em = $this->em();
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $owner = (new Utilisateur())
            ->setEmail(self::OWNER_EMAIL)
            ->setNom('PHPUnit Facturation Ecran')
            ->setVerified(true);
        $owner->setPassword($hasher->hashPassword($owner, self::PASSWORD));
        $em->persist($owner);

        $entreprise = (new Entreprise())
            ->setNom(self::ENTREPRISE_NOM)
            ->setLicence('LIC-FE')
            ->setAdresse('1 rue de la Facture')
            ->setTelephone('+243000000007')
            ->setRccm('RCCM-FE')
            ->setIdnat('IDNAT-FE')
            ->setNumimpot('IMP-FE')
            ->setUtilisateur($owner);
        $em->persist($entreprise);
        $owner->setConnectedTo($entreprise);

        $invite = (new Invite())->setNom('Proprietaire FE');
        $invite->setUtilisateur($owner)->setEntreprise($entreprise)->setProprietaire(true);
        $em->persist($invite);

        $compte = (new CompteBancaire())
            ->setIntitule('Compte principal FE')
            ->setNumero('CD-0001')
            ->setBanque('Banque de test')
            ->setCodeSwift('TESTCDKI');
        $compte->setEntreprise($entreprise);
        $em->persist($compte);

        $assureur = (new Assureur())->setNom('ACTIVA FE');
        $assureur->setEntreprise($entreprise);
        $em->persist($assureur);

        $portefeuille = (new Portefeuille())->setNom('Portefeuille FE')->setGestionnaire($invite);
        $portefeuille->setEntreprise($entreprise);
        $em->persist($portefeuille);

        $client = (new Client())->setNom('Client FE')->setExonere(false);
        $client->setEntreprise($entreprise)->setPortefeuille($portefeuille);
        $em->persist($client);

        $piste = (new Piste())
            ->setNom('Piste FE')
            ->setTypeAvenant(Piste::AVENANT_SOUSCRIPTION)
            ->setDescriptionDuRisque('Risque de test facturation écran')
            ->setExercice((int) (new \DateTimeImmutable('now'))->format('Y'))
            ->setClient($client);
        $piste->setEntreprise($entreprise)->setInvite($invite);
        $em->persist($piste);

        $cotation = (new Cotation())->setNom('Cotation FE')->setDuree(12);
        $cotation->setPiste($piste)->setAssureur($assureur);
        $cotation->setEntreprise($entreprise);
        $em->persist($cotation);

        // L'assiette : sans type de chargement, la commission reste à 0.
        $primeNette = (new Chargement())->setNom('Prime nette FE')->setFonction(Chargement::FONCTION_PRIME_NETTE);
        $primeNette->setEntreprise($entreprise);
        $em->persist($primeNette);

        $chargement = (new ChargementPourPrime())
            ->setNom('Prime FE')
            ->setMontantFlatExceptionel(1000.0)
            ->setType($primeNette)
            ->setCotation($cotation);
        $chargement->setEntreprise($entreprise);
        $em->persist($chargement);
        $cotation->addChargement($chargement);

        $typeRevenu = (new TypeRevenu())
            ->setNom('Commission FE')
            ->setShared(false)
            ->setMultipayments(false)
            ->setTypeChargement($primeNette)
            ->setRedevable(TypeRevenu::REDEVABLE_ASSUREUR);
        $typeRevenu->setEntreprise($entreprise);
        $em->persist($typeRevenu);

        // 10 POINTS de la prime — convention du projet : tous les taux sont des points.
        $revenu = (new RevenuPourCourtier())->setNom('Revenu FE')->setTauxExceptionel(10.0);
        $revenu->setCotation($cotation)->setTypeRevenu($typeRevenu);
        $revenu->setEntreprise($entreprise);
        $em->persist($revenu);
        $cotation->addRevenu($revenu);

        // Dates CALCULÉES : une date figée ferait mentir ce test le mois prochain.
        $avenant = (new Avenant())
            ->setDescription('Avenant FE')
            ->setReferencePolice('POL-FE-001')
            ->setStartingAt(new \DateTimeImmutable('-1 month'))
            ->setEndingAt(new \DateTimeImmutable('+11 months'))
            ->setCotation($cotation);
        $avenant->setEntreprise($entreprise);
        $em->persist($avenant);
        $cotation->addAvenant($avenant);

        $tranche = (new Tranche())
            ->setNom('Tranche FE')
            ->setPourcentage(100.0)
            ->setPayableAt(new \DateTimeImmutable('-30 days'))
            ->setEcheanceAt(new \DateTimeImmutable('-5 days'));
        $tranche->setCotation($cotation);
        $tranche->setEntreprise($entreprise);
        $em->persist($tranche);
        $cotation->addTranche($tranche);

        $em->flush();

        $ids = [
            'entrepriseId' => $entreprise->getId(),
            'trancheId' => $tranche->getId(),
            'revenuId' => $revenu->getId(),
            'assureurId' => $assureur->getId(),
            'compteId' => $compte->getId(),
            'ownerId' => $owner->getId(),
        ];
        $em->clear();

        $this->client->loginUser($em->getRepository(Utilisateur::class)->find($ids['ownerId']));

        return [
            'entreprise' => $em->getRepository(Entreprise::class)->find($ids['entrepriseId']),
            'trancheId' => $ids['trancheId'],
            'revenuId' => $ids['revenuId'],
            'assureurId' => $ids['assureurId'],
            'compteId' => $ids['compteId'],
        ];
    }

    /** @return array{0: int, 1: array} le code HTTP et la charge décodée */
    private function facturer(array $lignes, array $surcharges = []): array
    {
        $this->client->request(
            'POST',
            '/admin/note/facturation',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($surcharges + [
                'lignes' => $lignes,
                'destinataire' => 'assureur',
                'objet' => 'Commission — Police POL-FE-001',
                'comptes' => [],
                'signataire' => 'Le gérant',
            ]),
        );

        return [
            $this->client->getResponse()->getStatusCode(),
            json_decode((string) $this->client->getResponse()->getContent(), true) ?? [],
        ];
    }

    private function notes(): array
    {
        return $this->em()->getRepository(Note::class)
            ->createQueryBuilder('n')
            ->join('n.entreprise', 'e')->where('e.nom = :nom')->setParameter('nom', self::ENTREPRISE_NOM)
            ->getQuery()->getResult();
    }

    /**
     * LE CAS NOMINAL, ET CE QUI MANQUAIT : la ligne. Sans elle, la note serait vide,
     * son total nul, et le PDF réclamerait zéro.
     */
    public function testUneEcheanceProduitLaNoteEtSaLigne(): void
    {
        $seed = $this->seed();

        [$code, $charge] = $this->facturer(
            [['trancheId' => $seed['trancheId'], 'revenuId' => $seed['revenuId']]],
            ['comptes' => [$seed['compteId']]],
        );

        self::assertSame(200, $code, 'La facturation doit aboutir.');
        self::assertArrayHasKey('noteId', $charge);
        self::assertStringContainsString('download=1', (string) $charge['pdfUrl'],
            'La réponse doit porter l\'URL que le cerveau sait ouvrir en PDF.',
        );

        $notes = $this->notes();
        self::assertCount(1, $notes, 'Une seule note pour cette sélection.');

        $note = $notes[0];
        self::assertSame(Note::TYPE_NOTE_DE_DEBIT, $note->getType());
        self::assertSame(Note::TO_ASSUREUR, $note->getAddressedTo());
        self::assertSame($seed['assureurId'], $note->getAssureur()?->getId());
        self::assertCount(1, $note->getArticles(), 'La LIGNE est ce qui manquait à l\'écran.');
        self::assertSame(
            $seed['revenuId'],
            $note->getArticles()->first()->getRevenuFacture()?->getId(),
        );
        self::assertSame(
            $seed['trancheId'],
            $note->getArticles()->first()->getTranche()?->getId(),
            'La ligne se rattache à l\'échéance : c\'est à cette maille que le recouvrement se suit.',
        );
        self::assertCount(1, $note->getComptes(),
            'Sans compte bancaire, le PDF ne dirait pas à l\'assureur où virer.',
        );
        self::assertSame('Le gérant', $note->getSignedBy());
    }

    /**
     * ÉMETTRE, C'EST VALIDER — et c'est la première fois que le suivi du recouvrement
     * peut rendre quelque chose : aucune note n'était jamais validée nulle part.
     */
    public function testLaNoteEmiseEstValideeEtEntreAuRecouvrement(): void
    {
        $seed = $this->seed();

        $this->facturer([['trancheId' => $seed['trancheId'], 'revenuId' => $seed['revenuId']]]);

        $note = $this->notes()[0] ?? null;
        self::assertNotNull($note);
        self::assertTrue($note->isValidated(), 'Une note émise part validée.');

        $recouvrement = static::getContainer()->get(NoteRecouvrementService::class);
        $impayees = $recouvrement->lister($seed['entreprise']);

        $references = array_map(
            static fn (Note $n): ?string => $n->getReference(),
            $impayees['items'],
        );
        self::assertContains($note->getReference(), $references,
            'La note doit remonter au suivi du recouvrement : c\'est ce que la validation ouvre.',
        );
    }

    /**
     * REFACTURER NE DOIT RIEN AJOUTER. La fenêtre coche tout d'office : si
     * « facturable » voulait encore dire « impayé », un second passage émettrait une
     * seconde pièce pour le même argent.
     */
    public function testRefacturerLaMemeEcheanceNeProposePlusRien(): void
    {
        $seed = $this->seed();
        $ligne = [['trancheId' => $seed['trancheId'], 'revenuId' => $seed['revenuId']]];

        $this->facturer($ligne);
        self::assertCount(1, $this->notes());

        // La fenêtre rouverte sur la même échéance ne doit plus rien proposer.
        $this->client->request('GET', '/admin/note/facturation-picker?ids=' . $seed['trancheId']);
        self::assertResponseIsSuccessful();

        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Déjà facturé', $html,
            'La fenêtre doit dire que l\'échéance est déjà facturée.',
        );
        self::assertStringContainsString((string) $this->notes()[0]->getReference(), $html,
            'Elle doit NOMMER la note qui bloque : « rien à facturer » sans la pièce est une énigme.',
        );
    }

    /**
     * LE RESTE À FACTURER TEL QUE LA FENÊTRE LE PROPOSE — lu sur la fenêtre elle-même,
     * et non recalculé ici : un test qui refait le calcul ne prouve que lui-même.
     */
    private function resteProposé(int $trancheId): float
    {
        $this->client->request('GET', '/admin/note/facturation-picker?ids=' . $trancheId);
        self::assertResponseIsSuccessful();

        $html = (string) $this->client->getResponse()->getContent();
        self::assertSame(1, preg_match('/data-montant="([0-9.]+)"/', $html, $m),
            'La fenêtre doit proposer exactement une ligne facturable.',
        );

        return (float) $m[1];
    }

    private function montantDe(Note $note): float
    {
        return round(
            static::getContainer()->get(IndicatorCalculationHelper::class)->getNoteMontantPayable($note),
            2,
        );
    }

    /**
     * ON NE FACTURE PAS TOUJOURS TOUT LE DÛ.
     *
     * Un assureur peut n'en reconnaître qu'une part, ou un acompte avoir été convenu.
     * La note ne porte alors que ce qu'on réclame — et, ce qui compte autant, LE
     * RELIQUAT RESTE FACTURABLE : sans cela, réclamer la moitié ferait perdre l'autre.
     *
     * Rien de neuf ne le permet : `Article` dérive son montant de sa QUANTITÉ, et la
     * règle du reste à facturer relit ces mêmes montants. Une quantité fractionnaire
     * suffit, et le reliquat se rouvre tout seul.
     */
    public function testFacturerUnePartLaisseLeResteFacturable(): void
    {
        $seed = $this->seed();
        $reste = $this->resteProposé($seed['trancheId']);
        self::assertGreaterThan(1.0, $reste, 'Le jeu d\'essai doit avoir une commission à facturer.');

        $part = round($reste / 2, 2);
        [$code] = $this->facturer([[
            'trancheId' => $seed['trancheId'],
            'revenuId' => $seed['revenuId'],
            'montant' => $part,
        ]]);

        self::assertSame(200, $code);
        $note = $this->notes()[0];
        self::assertEqualsWithDelta($part, $this->montantDe($note), 0.02,
            'La note ne porte que ce qui a été réclamé.',
        );
        self::assertLessThan(1.0, (float) $note->getArticles()->first()->getQuantite(),
            'Une part se facture par une QUANTITÉ fractionnaire : c\'est le seul champ '
            . 'qui porte le montant d\'une ligne.',
        );

        self::assertEqualsWithDelta($reste - $part, $this->resteProposé($seed['trancheId']), 0.02,
            'Le reliquat doit rester facturable — sinon réclamer la moitié perdrait l\'autre.',
        );
    }

    /**
     * ⚠ LE MONTANT VENU DU NAVIGATEUR N'EST PAS CRU SUR PAROLE.
     *
     * Le champ est libre, et rien n'empêche d'y poster mille là où il reste onze. Le
     * plafond est posé côté serveur, par la MÊME pesée qui a rempli la fenêtre : sans
     * lui, l'écran émettrait des créances que le portefeuille ne justifie pas.
     */
    public function testUnMontantSuperieurAuResteEstRameneAuReste(): void
    {
        $seed = $this->seed();
        $reste = $this->resteProposé($seed['trancheId']);

        [$code] = $this->facturer([[
            'trancheId' => $seed['trancheId'],
            'revenuId' => $seed['revenuId'],
            'montant' => $reste * 100,
        ]]);

        self::assertSame(200, $code);
        self::assertEqualsWithDelta($reste, $this->montantDe($this->notes()[0]), 0.02,
            'Le serveur facture le reste, pas ce qu\'on lui a demandé.',
        );
    }

    /**
     * LE GESTE VA JUSQU'À LA COMPTABILITÉ — c'est le point de tout le chantier.
     *
     * Émettre une note ne produisait aucune écriture : une commission réclamée à un
     * assureur n'apparaissait ni au journal, ni au grand livre, ni au bilan. Depuis le
     * passage à l'engagement, la facture EST le fait générateur du produit. Ce test
     * boucle la chaîne depuis le bouton de l'écran, et non depuis le service : c'est le
     * seul endroit qui prouve que les deux bouts sont reliés.
     *
     * Le détail des écritures — l'avoir, le brouillon, l'extinction de la créance — vit
     * dans {@see FacturationComptabiliteTest}, qui n'a pas besoin de tout ce dossier.
     */
    public function testLaNoteEmiseEntreAuJournalEtPorteLaCreance(): void
    {
        $seed = $this->seed();

        $this->facturer([['trancheId' => $seed['trancheId'], 'revenuId' => $seed['revenuId']]]);
        $note = $this->notes()[0];

        $documents = static::getContainer()->get(CourtierEcritureComptableService::class)
            ->documents($seed['entreprise'], (int) $note->getSentAt()->format('Y'));

        $emission = null;
        foreach ($documents['journal']['ecritures'] as $ecriture) {
            if ($ecriture['piece'] === $note->getReference()) {
                $emission = $ecriture;
            }
        }
        self::assertNotNull($emission, 'La note émise depuis l\'écran doit entrer au journal.');
        self::assertSame('facturation', $emission['type']);

        $creance = 0.0;
        foreach ($emission['lignes'] as $ligne) {
            if ((string) $ligne['compte'] === PlanComptable::CLIENTS) {
                $creance = $ligne['debit'];
            }
        }
        self::assertGreaterThan(0.0, $creance,
            'La commission facturée doit naître en créance : c\'est ce qui la rend lisible au bilan.',
        );
    }

    /** Une échéance d'un autre cabinet est ignorée, et rien ne s'écrit à vide. */
    public function testUneCommissionHorsPerimetreNEcritRien(): void
    {
        $this->seed();

        [$code] = $this->facturer([['trancheId' => 999999999, 'revenuId' => 999999999]]);

        self::assertSame(422, $code, 'Aucune ligne exploitable : on refuse au lieu d\'écrire une note vide.');
        self::assertCount(0, $this->notes());
    }

    /** Sans aucune ligne, on refuse — et on le dit. */
    public function testSansLigneOnRefuse(): void
    {
        $this->seed();

        [$code, $charge] = $this->facturer([]);

        self::assertSame(422, $code);
        self::assertStringContainsString('Cochez au moins', (string) ($charge['message'] ?? ''));
        self::assertCount(0, $this->notes());
    }
}
