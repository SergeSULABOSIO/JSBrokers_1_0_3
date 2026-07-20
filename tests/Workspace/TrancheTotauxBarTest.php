<?php

namespace App\Tests\Workspace;

use App\Entity\Avenant;
use App\Entity\Bordereau;
use App\Entity\ChargementPourPrime;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Note;
use App\Entity\Paiement;
use App\Entity\Piste;
use App\Entity\RevenuPourCourtier;
use App\Entity\Tranche;
use App\Entity\TypeRevenu;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Barre des totaux de la rubrique Tranches : les indicateurs numériques exposés
 * (`data-list-manager-numeric-attributes-and-values-value` / réponse JSON de
 * dynamic-query) doivent refléter EXACTEMENT les mêmes valeurs que la liste et
 * l'assistant IA, y compris quand la commission est encaissée via un bordereau de
 * production SANS article (inférence dérivée, cf. IndicatorCalculationHelper).
 * Complète PortefeuilleFilterTest (labels + scoping) sur le volet valeurs.
 */
class TrancheTotauxBarTest extends WebTestCase
{
    private const OWNER_EMAIL = 'phpunit-totaux-owner@test.local';
    private const GUEST_EMAIL = 'phpunit-totaux-guest@test.local';
    private const PASSWORD = 'Test1234!';
    private const ENTREPRISE_NOM = 'PHPUnit TotauxBar SARL';

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

    private function user(string $email): Utilisateur
    {
        return $this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => $email]);
    }

    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();
        $nom = self::ENTREPRISE_NOM;

        $conn->executeStatement(
            "UPDATE utilisateur SET connected_to_id = NULL WHERE email IN (:emails)",
            ['emails' => [self::OWNER_EMAIL, self::GUEST_EMAIL]],
            ['emails' => \Doctrine\DBAL\ArrayParameterType::STRING]
        );

        foreach (['paiement', 'note', 'bordereau', 'avenant', 'tranche', 'revenu_pour_courtier', 'type_revenu', 'chargement_pour_prime', 'cotation', 'piste', 'invite'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :nom",
                ['nom' => $nom]
            );
        }
        $conn->executeStatement("DELETE FROM entreprise WHERE nom = :nom", ['nom' => $nom]);
        $conn->executeStatement(
            "DELETE FROM utilisateur WHERE email IN (:emails)",
            ['emails' => [self::OWNER_EMAIL, self::GUEST_EMAIL]],
            ['emails' => \Doctrine\DBAL\ArrayParameterType::STRING]
        );
    }

    /**
     * Une tranche (50 % de 1000 = 500 de prime) dont la commission (200 HT, addressée
     * à l'ASSUREUR, aucun référentiel de taxe seedé donc taxe nulle) est facturée par
     * une note SANS AUCUN ARTICLE, liée à un bordereau de production dont la ligne est
     * RÉCONCILIÉE (« match »). Reproduit le scénario réel : bordereau importé, ni note
     * client, ni signalement manuel.
     *
     * @return array{owner: Invite, entreprise: Entreprise, tranche: Tranche, bordereau: Bordereau}
     */
    private function seed(): array
    {
        $em = $this->em();
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $ownerUser = new Utilisateur();
        $ownerUser->setEmail(self::OWNER_EMAIL);
        $ownerUser->setNom('PHPUnit Totaux Owner');
        $ownerUser->setVerified(true);
        $ownerUser->setPassword($hasher->hashPassword($ownerUser, self::PASSWORD));
        $em->persist($ownerUser);

        $entreprise = new Entreprise();
        $entreprise->setNom(self::ENTREPRISE_NOM);
        $entreprise->setLicence('LIC-TOT');
        $entreprise->setAdresse('1 rue des Totaux');
        $entreprise->setTelephone('+243000000002');
        $entreprise->setRccm('RCCM-TOT');
        $entreprise->setIdnat('IDNAT-TOT');
        $entreprise->setNumimpot('IMP-TOT');
        $entreprise->setUtilisateur($ownerUser);
        $em->persist($entreprise);

        $owner = new Invite();
        $owner->setNom('Propriétaire');
        $owner->setUtilisateur($ownerUser);
        $owner->setEntreprise($entreprise);
        $owner->setProprietaire(true);
        $em->persist($owner);

        $piste = (new Piste())
            ->setNom('Piste Totaux')
            ->setTypeAvenant(0)
            ->setDescriptionDuRisque('Risque de test barre des totaux')
            ->setExercice(2026)
            ->setEntreprise($entreprise)
            ->setInvite($owner);
        $em->persist($piste);

        $cotation = (new Cotation())->setNom('Cotation Totaux')->setDuree(365);
        $cotation->setPiste($piste);
        $cotation->setEntreprise($entreprise);
        $em->persist($cotation);

        $chargement = (new ChargementPourPrime())->setNom('Prime Totaux')->setMontantFlatExceptionel(1000.0);
        $chargement->setEntreprise($entreprise);
        $cotation->addChargement($chargement);
        $em->persist($chargement);

        $typeRevenu = (new TypeRevenu())
            ->setNom('Commission Totaux')
            ->setMontantflat(200.0)
            ->setShared(false)
            ->setMultipayments(true)
            ->setRedevable(TypeRevenu::REDEVABLE_ASSUREUR);
        $typeRevenu->setEntreprise($entreprise);
        $em->persist($typeRevenu);

        $revenu = (new RevenuPourCourtier())->setNom('Revenu Totaux')->setTypeRevenu($typeRevenu);
        $revenu->setEntreprise($entreprise);
        // addRevenu() (pas seulement setCotation()) synchronise Cotation::getRevenus()
        // en mémoire — même piège que getAvenants()/getChargements() : sans lui, la
        // commission calculée (getCotationMontantCommissionHt) retombe à 0.
        $cotation->addRevenu($revenu);
        $em->persist($revenu);

        $tranche = new Tranche();
        $tranche->setNom('Tranche Totaux')
            ->setPourcentage(50.0)
            ->setPayableAt(new \DateTimeImmutable('-60 days'))
            ->setEcheanceAt(new \DateTimeImmutable('-10 days'));
        $tranche->setCotation($cotation);
        $tranche->setEntreprise($entreprise);
        $em->persist($tranche);

        $avenant = new Avenant();
        $avenant->setReferencePolice('POL-TOTAUX');
        $avenant->setNumero('0');
        $avenant->setDescription('Avenant Totaux');
        $avenant->setStartingAt(new \DateTimeImmutable('-60 days'));
        $avenant->setEndingAt(new \DateTimeImmutable('+305 days'));
        $avenant->setEntreprise($entreprise);
        $avenant->setInvite($owner);
        // addAvenant() (pas seulement setCotation()) synchronise Cotation::getAvenants()
        // en mémoire — sans quoi getCouvertureBordereaux()/isTrancheCouverteParBordereau()
        // ne verraient jamais l'avenant si le test réutilise le même EM après ce seed.
        $cotation->addAvenant($avenant);
        $em->persist($avenant);
        $em->flush();

        $bordereau = new Bordereau();
        $bordereau->setType(Bordereau::TYPE_BOREDERAU_PRODUCTION)
            ->setNom('Bordereau Totaux')->setReference('BRD-TOTAUX')
            ->setReceivedAt(new \DateTimeImmutable('-15 days'))
            ->setPeriodeDebut(new \DateTimeImmutable('-45 days'))
            ->setPeriodeFin(new \DateTimeImmutable('-15 days'))
            ->setMontantComHtPayableNow(200.0)
            ->setMontantTaxePayableNow(0.0)
            ->setAnalysisResults([
                ['type' => 'match', 'row_index' => 0, 'reference_police' => 'POL-TOTAUX', 'avenant_id' => $avenant->getId()],
            ])
            ->setInvite($owner)
            ->setEntreprise($entreprise);
        $em->persist($bordereau);

        $note = new Note();
        $note->setNom('Note Totaux')->setReference('NOTE-TOTAUX')
            ->setType(Note::TYPE_NOTE_DE_DEBIT)->setAddressedTo(Note::TO_ASSUREUR)
            ->setValidated(true)->setSignature('sig-test');
        $note->setEntreprise($entreprise);
        $note->setInvite($owner);
        // addNote() (pas seulement setBordereau()) synchronise Bordereau::getNotes() en
        // mémoire — même piège récurrent : sans lui, getBordereauMontantEncaisse() (donc
        // le calcul « bordereau soldé ») ne voit jamais cette note dans ce test.
        $bordereau->addNote($note);
        $em->persist($note);

        // Le bordereau est intégralement encaissé (200 = montantComHtPayableNow) :
        // la commission de la tranche doit être réputée encaissée (aucun article).
        // addPaiement() synchronise Note::getPaiements() en mémoire (même piège).
        $paiement = new Paiement();
        $paiement->setMontant(200.0)->setPaidAt(new \DateTimeImmutable('-1 day'))
            ->setReference('PAY-TOTAUX');
        $paiement->setEntreprise($entreprise);
        $note->addPaiement($paiement);
        $em->persist($paiement);

        $em->flush();

        return [
            'owner' => $owner,
            'entreprise' => $entreprise,
            'tranche' => $tranche,
            'bordereau' => $bordereau,
        ];
    }

    /**
     * La barre des totaux (chemin JSON de dynamic-query, `numericAttributesAndValues`)
     * doit refléter la MÊME réalité business que la liste et l'IA : commission
     * réputée intégralement encaissée (bordereau soldé sans article) → commission
     * exigible retombée à 0, montant payé = commission due, solde nul. Preuve
     * chiffrée que la barre des totaux consomme bien les indicateurs post-inférence,
     * pas une valeur stale ou une clé absente.
     */
    public function testNumericTotalsReflectBordereauInferenceWithoutArticles(): void
    {
        ['owner' => $owner, 'entreprise' => $e, 'tranche' => $tranche] = $this->seed();
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        // « Payées » : la tranche doit y remonter (prime dérivée + commission encaissée).
        $this->client->request(
            'POST',
            sprintf('/admin/tranche/api/dynamic-query/%d/%d', $owner->getId(), $e->getId()),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['criteria' => ['__statut_paiement__' => 'payees'], 'parentContext' => null, 'page' => 1])
        );
        $this->assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame(1, $payload['pagination']['totalItems'], 'La tranche doit remonter sous « Payées » (bordereau soldé sans article).');
        $numeric = $payload['numericAttributesAndValues'][$tranche->getId()] ?? null;
        $this->assertNotNull($numeric, 'La barre des totaux doit embarquer les indicateurs de la tranche visible.');

        // Prime (50 % de 1000) réputée payée par inférence bordereau : solde nul.
        $this->assertEqualsWithDelta(50000.0, $numeric['primeTranche']['value'], 1.0, 'Prime Tranche = 500 x 100.');
        $this->assertEqualsWithDelta(0.0, $numeric['commissionExigible']['value'], 1.0, 'Commission encaissée : plus rien à collecter.');
        // Commission de la TRANCHE (50 % de la cotation, 200 HT sans taxe) = 100.
        $this->assertEqualsWithDelta(10000.0, $numeric['montantCalculeTTC']['value'], 1.0, 'Montant TTC = 200 x 50 % x 100.');
        $this->assertEqualsWithDelta(10000.0, $numeric['montant_paye']['value'], 1.0, 'Montant Payé = Montant TTC : bordereau intégralement encaissé, réputé perçu sans article.');
        $this->assertEqualsWithDelta(0.0, $numeric['solde_restant_du']['value'], 1.0, 'Solde Restant Dû nul : bordereau intégralement encaissé.');
        $this->assertEqualsWithDelta(0.0, $numeric['primeDeclareePayee']['value'], 1.0, 'Fait dérivé : aucun PaiementPrime signalé n\'a été créé.');

        // « Impayées » : la tranche ne doit plus y remonter (tout est réputé réglé).
        $this->client->request(
            'POST',
            sprintf('/admin/tranche/api/dynamic-query/%d/%d', $owner->getId(), $e->getId()),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['criteria' => ['__statut_paiement__' => 'impayees'], 'parentContext' => null, 'page' => 1])
        );
        $this->assertResponseIsSuccessful();
        $payload2 = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame(0, $payload2['pagination']['totalItems'], 'Plus aucune tranche impayée après encaissement du bordereau.');
    }

    /**
     * Droit d'accès par rôle : un invité SANS lecture sur Tranche (aucun RolesEnFinance)
     * doit se voir opposer le panneau d'accès restreint, sans qu'aucune ligne ni aucun
     * indicateur numérique de la tranche ne transite — la barre des totaux ne doit
     * jamais fuiter de données hors périmètre.
     */
    public function testAccessDeniedInviteSeesNoNumericData(): void
    {
        ['entreprise' => $e, 'tranche' => $tranche] = $this->seed();

        $guestUser = new Utilisateur();
        $guestUser->setEmail(self::GUEST_EMAIL);
        $guestUser->setNom('PHPUnit Totaux Guest');
        $guestUser->setVerified(true);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $guestUser->setPassword($hasher->hashPassword($guestUser, self::PASSWORD));
        $guestUser->setConnectedTo($e);
        $this->em()->persist($guestUser);

        $guestInvite = new Invite();
        $guestInvite->setNom('Invité sans droit Finances');
        $guestInvite->setUtilisateur($guestUser);
        $guestInvite->setEntreprise($e);
        $guestInvite->setProprietaire(false);
        $this->em()->persist($guestInvite);
        $this->em()->flush();

        $this->client->loginUser($guestUser);

        $this->client->request('GET', sprintf('/admin/tranche/index/%d/%d', $guestInvite->getId(), $e->getId()));
        $this->assertResponseIsSuccessful('Le panneau d\'accès restreint doit être servi (pas une erreur).');
        $html = (string) $this->client->getResponse()->getContent();

        $this->assertStringNotContainsString($tranche->getNom(), $html, 'Aucune ligne ne doit transiter.');
        $this->assertStringNotContainsString('numeric-attributes-and-values', $html, 'Aucun indicateur numérique ne doit transiter hors périmètre.');
        $this->assertStringNotContainsString('Commission Exigible', $html, 'Aucun libellé d\'indicateur ne doit fuiter.');

        // Même verrou côté rafraîchissement de liste (403 JSON, pas de fuite non plus).
        $this->client->request(
            'POST',
            sprintf('/admin/tranche/api/dynamic-query/%d/%d', $guestInvite->getId(), $e->getId()),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['criteria' => [], 'parentContext' => null, 'page' => 1])
        );
        $this->assertResponseStatusCodeSame(403, 'Le rafraîchissement de liste doit aussi être bloqué (403).');
    }
}
