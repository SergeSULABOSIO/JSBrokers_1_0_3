<?php

namespace App\Tests\Workspace;

use App\Entity\Avenant;
use App\Entity\Client;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Piste;
use App\Entity\Risque;
use App\Entity\Utilisateur;
use App\Services\CanvasBuilder;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Tests fonctionnels des actions spéciales « piste dérivée » de la rubrique Avenant
 * (pattern Invité → Portefeuille) :
 *  - attribut calculé hasPisteDerivee qui pilote la visibilité des actions ;
 *  - exposition des actions conditionnelles dans le canevas (data-condition-*) ;
 *  - endpoint de contexte (mode edit/create selon l'état réel de l'avenant) ;
 *  - suppression de la piste dérivée via l'avenant (l'avenant de base est CONSERVÉ,
 *    malgré la cascade remove de Piste::avenantDeBase ; 404 sans piste dérivée).
 *
 * On agit en tant que PROPRIÉTAIRE de l'entreprise (bypass du contrôle d'accès) pour
 * isoler la logique testée. Chaque test crée ses données et les nettoie.
 */
class AvenantPisteDeriveeActionsTest extends WebTestCase
{
    private const OWNER_EMAIL = 'phpunit-apda-owner@test.local';
    private const PASSWORD = 'Test1234!';
    private const ENTREPRISE_NOM = 'PHPUnit AvenantPisteDerivee SARL';

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

        $conn->executeStatement("UPDATE utilisateur SET connected_to_id = NULL WHERE email = :e", ['e' => self::OWNER_EMAIL]);

        // Cycles de FK Avenant↔Piste : on dissocie les deux liens croisés avant toute
        // suppression (avenant.piste_de_renouvellement_id et piste.avenant_de_base_id).
        $conn->executeStatement("UPDATE avenant a JOIN entreprise e ON a.entreprise_id = e.id SET a.piste_de_renouvellement_id = NULL WHERE e.nom = :nom", ['nom' => $nom]);
        $conn->executeStatement("UPDATE piste p JOIN entreprise e ON p.entreprise_id = e.id SET p.avenant_de_base_id = NULL WHERE e.nom = :nom", ['nom' => $nom]);

        // Ordre des FK : avenant → cotation → piste → risque/client → invite → entreprise → utilisateur.
        $conn->executeStatement("DELETE a FROM avenant a JOIN entreprise e ON a.entreprise_id = e.id WHERE e.nom = :nom", ['nom' => $nom]);
        $conn->executeStatement("DELETE co FROM cotation co JOIN entreprise e ON co.entreprise_id = e.id WHERE e.nom = :nom", ['nom' => $nom]);
        $conn->executeStatement("DELETE p FROM piste p JOIN entreprise e ON p.entreprise_id = e.id WHERE e.nom = :nom", ['nom' => $nom]);
        $conn->executeStatement("DELETE r FROM risque r JOIN entreprise e ON r.entreprise_id = e.id WHERE e.nom = :nom", ['nom' => $nom]);
        $conn->executeStatement("DELETE c FROM client c JOIN entreprise e ON c.entreprise_id = e.id WHERE e.nom = :nom", ['nom' => $nom]);
        $conn->executeStatement("DELETE i FROM invite i JOIN entreprise e ON i.entreprise_id = e.id WHERE e.nom = :nom", ['nom' => $nom]);
        $conn->executeStatement("DELETE FROM entreprise WHERE nom = :nom", ['nom' => $nom]);
        $conn->executeStatement("DELETE FROM utilisateur WHERE email = :e", ['e' => self::OWNER_EMAIL]);
    }

    private function makeRisque(EntityManagerInterface $em, Entreprise $e, Invite $inv, string $code): Risque
    {
        $r = (new Risque())
            ->setNomComplet('Risque ' . $code)
            ->setCode($code)
            ->setDescription('Description risque ' . $code)
            ->setBranche(Risque::BRANCHE_IARD_OU_NON_VIE)
            ->setImposable(true);
        $r->setEntreprise($e);
        $r->setInvite($inv);
        $em->persist($r);
        return $r;
    }

    private function makePiste(EntityManagerInterface $em, Entreprise $e, Invite $inv, Client $c, Risque $r, int $type, int $exercice, string $nom): Piste
    {
        $p = (new Piste())
            ->setNom($nom)
            ->setClient($c)
            ->setRisque($r)
            ->setTypeAvenant($type)
            ->setDescriptionDuRisque('Description ' . $nom)
            ->setExercice($exercice);
        $p->setEntreprise($e);
        $p->setInvite($inv);
        $em->persist($p);
        return $p;
    }

    /**
     * Jeu de données : entreprise + propriétaire connecté, un avenant AVEC piste dérivée
     * (via pisteDeRenouvellement ↔ avenantDeBase) et un avenant SANS piste dérivée.
     *
     * @return array{owner: Invite, entreprise: Entreprise, avenantWithPiste: Avenant, avenantWithout: Avenant, pisteDerivee: Piste, basePiste: Piste, baseCotation: Cotation}
     */
    private function seed(): array
    {
        $em = $this->em();
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $ownerUser = new Utilisateur();
        $ownerUser->setEmail(self::OWNER_EMAIL);
        $ownerUser->setNom('PHPUnit Owner');
        $ownerUser->setVerified(true);
        $ownerUser->setPassword($hasher->hashPassword($ownerUser, self::PASSWORD));
        $em->persist($ownerUser);

        $entreprise = new Entreprise();
        $entreprise->setNom(self::ENTREPRISE_NOM);
        $entreprise->setLicence('LIC-TEST');
        $entreprise->setAdresse('1 rue du Test');
        $entreprise->setTelephone('+243000000000');
        $entreprise->setRccm('RCCM-TEST');
        $entreprise->setIdnat('IDNAT-TEST');
        $entreprise->setNumimpot('IMP-TEST');
        $entreprise->setUtilisateur($ownerUser);
        $ownerUser->setConnectedTo($entreprise);
        $em->persist($entreprise);

        $owner = new Invite();
        $owner->setNom('Administrateur');
        $owner->setUtilisateur($ownerUser);
        $owner->setEntreprise($entreprise);
        $owner->setProprietaire(true);
        $em->persist($owner);

        $client = (new Client())->setNom('PHPUNIT-APDA-CLIENT')->setExonere(false);
        $client->setEntreprise($entreprise);
        $em->persist($client);
        $risque = $this->makeRisque($em, $entreprise, $owner, 'APDA-RQ');

        // Avenant AVEC piste dérivée : base piste → cotation → avenant, plus une piste
        // dérivée liée dans les deux sens (pisteDeRenouvellement ↔ avenantDeBase).
        $basePiste = $this->makePiste($em, $entreprise, $owner, $client, $risque, Piste::AVENANT_SOUSCRIPTION, 2025, 'Base APDA');
        $baseCotation = (new Cotation())->setNom('Cotation APDA')->setDuree(365);
        $baseCotation->setPiste($basePiste);
        $baseCotation->setEntreprise($entreprise);
        $baseCotation->setInvite($owner);
        $em->persist($baseCotation);

        $avenantWithPiste = (new Avenant())
            ->setReferencePolice('POL-APDA-1')
            ->setDescription('Avenant APDA 1')
            ->setStartingAt(new DateTimeImmutable('2025-01-01'))
            ->setEndingAt(new DateTimeImmutable('2025-12-31'))
            ->setCotation($baseCotation);
        $avenantWithPiste->setEntreprise($entreprise);
        $avenantWithPiste->setInvite($owner);
        $em->persist($avenantWithPiste);

        $pisteDerivee = $this->makePiste($em, $entreprise, $owner, $client, $risque, Piste::AVENANT_RENOUVELLEMENT, 2026, 'Renouvellement — Base APDA');
        $avenantWithPiste->setPisteDeRenouvellement($pisteDerivee);
        $pisteDerivee->setAvenantDeBase($avenantWithPiste);

        // Avenant SANS piste dérivée.
        $piste2 = $this->makePiste($em, $entreprise, $owner, $client, $risque, Piste::AVENANT_SOUSCRIPTION, 2025, 'Base APDA 2');
        $cotation2 = (new Cotation())->setNom('Cotation APDA 2')->setDuree(365);
        $cotation2->setPiste($piste2);
        $cotation2->setEntreprise($entreprise);
        $cotation2->setInvite($owner);
        $em->persist($cotation2);
        $avenantWithout = (new Avenant())
            ->setReferencePolice('POL-APDA-2')
            ->setDescription('Avenant APDA 2')
            ->setStartingAt(new DateTimeImmutable('2025-01-01'))
            ->setEndingAt(new DateTimeImmutable('2025-12-31'))
            ->setCotation($cotation2);
        $avenantWithout->setEntreprise($entreprise);
        $avenantWithout->setInvite($owner);
        $em->persist($avenantWithout);

        $em->flush();

        $ids = [
            'owner' => $owner->getId(),
            'entreprise' => $entreprise->getId(),
            'avenantWithPiste' => $avenantWithPiste->getId(),
            'avenantWithout' => $avenantWithout->getId(),
            'pisteDerivee' => $pisteDerivee->getId(),
            'basePiste' => $basePiste->getId(),
            'baseCotation' => $baseCotation->getId(),
            'client' => $client->getId(),
            'risque' => $risque->getId(),
        ];
        $em->clear();

        return [
            'owner' => $em->getRepository(Invite::class)->find($ids['owner']),
            'entreprise' => $em->getRepository(Entreprise::class)->find($ids['entreprise']),
            'avenantWithPiste' => $em->getRepository(Avenant::class)->find($ids['avenantWithPiste']),
            'avenantWithout' => $em->getRepository(Avenant::class)->find($ids['avenantWithout']),
            'pisteDerivee' => $em->getRepository(Piste::class)->find($ids['pisteDerivee']),
            'basePiste' => $em->getRepository(Piste::class)->find($ids['basePiste']),
            'baseCotation' => $em->getRepository(Cotation::class)->find($ids['baseCotation']),
            'client' => $em->getRepository(Client::class)->find($ids['client']),
            'risque' => $em->getRepository(Risque::class)->find($ids['risque']),
        ];
    }

    public function testHasPisteDeriveeCalculatedIndicator(): void
    {
        ['avenantWithPiste' => $withP, 'avenantWithout' => $without] = $this->seed();
        $canvasBuilder = static::getContainer()->get(CanvasBuilder::class);

        $canvasBuilder->loadAllCalculatedValues($withP);
        $this->assertTrue($withP->hasPisteDerivee, "L'avenant lié à une piste dérivée doit exposer hasPisteDerivee = true.");

        $canvasBuilder->loadAllCalculatedValues($without);
        $this->assertFalse($without->hasPisteDerivee, "L'avenant sans piste dérivée doit exposer hasPisteDerivee = false (booléen, jamais null).");
    }

    public function testAvenantFormCanvasExposesConditionalPisteDeriveeActions(): void
    {
        ['avenantWithPiste' => $withP] = $this->seed();
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        // Le formulaire d'édition d'un avenant rend la barre d'outils des attributs avec
        // les trois actions « piste dérivée » et leurs conditions (data-condition-*),
        // filtrées côté JS contre l'entité (dialog-instance#initializeAttributeToolbar).
        $this->client->request('GET', '/admin/avenant/api/get-form/' . $withP->getId());
        $this->assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();

        $this->assertStringContainsString('get-piste-derivee-context/%id%', $html, 'Les actions Ajouter/Éditer doivent pointer le contexte piste dérivée.');
        $this->assertStringContainsString('delete-piste-derivee', $html, "L'action Supprimer doit pointer la route de suppression.");
        $this->assertStringContainsString('data-condition-field="hasPisteDerivee"', $html, 'Les actions doivent porter leur condition pour le filtrage côté dialogue.');
    }

    public function testContextReturnsEditModeWhenPisteDeriveeExists(): void
    {
        ['avenantWithPiste' => $withP, 'pisteDerivee' => $pd, 'entreprise' => $e] = $this->seed();
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        $this->client->request('GET', sprintf(
            '/admin/avenant/api/get-piste-derivee-context/%d?idEntreprise=%d',
            $withP->getId(),
            $e->getId()
        ));
        $this->assertResponseIsSuccessful('Le contexte piste dérivée doit répondre 200.');
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);

        $this->assertSame('edit', $payload['mode'], "L'avenant a une piste dérivée : mode édition.");
        $this->assertSame($withP->getId(), $payload['avenantId']);
        $this->assertSame($pd->getId(), $payload['piste']['id'] ?? null, 'La piste sérialisée doit être la piste dérivée.');
        // Le canevas retourné est bien celui de la Piste (le dialogue soumettra là-bas).
        $this->assertSame(
            '/admin/piste/api/submit',
            $payload['formCanvas']['parametres']['endpoint_submit_url'] ?? null,
            "Le canevas doit être celui de l'entité Piste."
        );
    }

    public function testContextReturnsCreateModeWhenNoPisteDerivee(): void
    {
        ['avenantWithout' => $without, 'entreprise' => $e] = $this->seed();
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        $this->client->request('GET', sprintf(
            '/admin/avenant/api/get-piste-derivee-context/%d?idEntreprise=%d',
            $without->getId(),
            $e->getId()
        ));
        $this->assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);

        $this->assertSame('create', $payload['mode'], "L'avenant sans piste dérivée : mode création.");
        $this->assertSame($without->getId(), $payload['avenantId']);
        $this->assertNull($payload['piste']);
        $this->assertSame(
            '/admin/piste/api/submit',
            $payload['formCanvas']['parametres']['endpoint_submit_url'] ?? null
        );
    }

    public function testDerivedPisteFormWiresTypePrefixSync(): void
    {
        ['avenantWithout' => $without] = $this->seed();
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        // Formulaire de création de la piste dérivée (préremplissage riche via ?idAvenant=).
        $this->client->request('GET', '/admin/piste/api/get-form?idAvenant=' . $without->getId());
        $this->assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();

        // Le préfixe initial du nom reflète le type par défaut (Renouvellement).
        $this->assertStringContainsString('Renouvellement ', $html, 'Le nom pré-rempli doit porter le préfixe du type par défaut.');
        // Le <form> porte le contrôleur Stimulus + la table des libellés (source unique
        // TYPE_AVENANT_LABELS) qui pilotent la réécriture dynamique du préfixe.
        $this->assertStringContainsString('data-controller="piste-name-sync"', $html, 'Le formulaire doit activer la synchro du préfixe.');
        $this->assertStringContainsString('data-piste-name-sync-labels-value', $html, 'Les libellés de type doivent être transmis au contrôleur.');
        $this->assertStringContainsString('Prorogation', $html, 'Le libellé « Prorogation » doit être disponible pour la réécriture.');
    }

    public function testSubmitDerivedPisteLinksAvenantWithoutCascadeError(): void
    {
        ['avenantWithout' => $avenant, 'entreprise' => $e, 'owner' => $owner, 'client' => $client, 'risque' => $risque] = $this->seed();
        $avenantId = $avenant->getId();
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        // Reproduit la soumission du formulaire de création de la piste dérivée
        // (dialog-instance réinjecte idAvenant). Avant le correctif, le métrage de
        // tokens déclenchait un flush trouvant la piste « nouvelle » via
        // Avenant::pisteDeRenouvellement (sans cascade persist) → 500.
        $this->client->request('POST', '/admin/piste/api/submit', [
            'nom' => 'Renouvellement — Base APDA',
            'client' => $client->getId(),
            'risque' => $risque->getId(),
            'descriptionDuRisque' => 'Desc renouvellement',
            'typeAvenant' => Piste::AVENANT_RENOUVELLEMENT,
            'renewalCondition' => Piste::RENEWAL_CONDITION_RENEWABLE,
            'exercice' => 2026,
            'idAvenant' => $avenantId,
            'idEntreprise' => $e->getId(),
            'idInvite' => $owner->getId(),
        ]);

        $this->assertResponseIsSuccessful("La soumission de la piste dérivée doit réussir (aucune erreur de cascade).");

        $this->em()->clear();
        $reloaded = $this->em()->getRepository(Avenant::class)->find($avenantId);
        $this->assertNotNull($reloaded->getPisteDeRenouvellement(), "L'avenant doit être lié à la piste dérivée créée.");
        $this->assertSame(
            Piste::AVENANT_RENOUVELLEMENT,
            $reloaded->getPisteDeRenouvellement()->getTypeAvenant(),
            'La piste dérivée doit porter le type soumis.'
        );
    }

    public function testDeletePisteDeriveeKeepsAvenantAndBaseChainThen404(): void
    {
        ['avenantWithPiste' => $withP, 'pisteDerivee' => $pd, 'basePiste' => $bp, 'baseCotation' => $bc] = $this->seed();
        $avenantId = $withP->getId();
        $pdId = $pd->getId();
        $bpId = $bp->getId();
        $bcId = $bc->getId();
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        // Suppression via l'AVENANT (l'id de l'URL est celui de l'avenant, pas de la piste).
        $this->client->request('DELETE', '/admin/avenant/api/delete-piste-derivee/' . $avenantId);
        $this->assertResponseIsSuccessful('La suppression de la piste dérivée via l\'avenant doit répondre 200.');

        $this->em()->clear();
        $this->assertNull($this->em()->getRepository(Piste::class)->find($pdId), 'La piste dérivée doit être supprimée.');
        // Malgré la cascade remove de Piste::avenantDeBase, l'avenant de base est conservé.
        $survivor = $this->em()->getRepository(Avenant::class)->find($avenantId);
        $this->assertNotNull($survivor, "L'avenant de base ne doit PAS être supprimé avec sa piste dérivée.");
        $this->assertNull($survivor->getPisteDeRenouvellement(), "L'avenant ne référence plus de piste dérivée.");
        $this->assertNotNull($this->em()->getRepository(Piste::class)->find($bpId), 'La piste de base doit être conservée.');
        $this->assertNotNull($this->em()->getRepository(Cotation::class)->find($bcId), 'La cotation de base doit être conservée.');

        // Second appel : l'avenant n'a plus de piste dérivée → 404.
        $this->client->request('DELETE', '/admin/avenant/api/delete-piste-derivee/' . $avenantId);
        $this->assertResponseStatusCodeSame(404, 'Sans piste dérivée, la suppression doit répondre 404.');
    }
}
