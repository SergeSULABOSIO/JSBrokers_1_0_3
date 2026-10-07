<?php

namespace App\Tests\Workspace;

use App\Entity\Avenant;
use App\Entity\Client;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\NotificationSinistre;
use App\Entity\Piste;
use App\Entity\Risque;
use App\Entity\Utilisateur;
use App\Services\ReferencesDePolice;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * UN SINISTRE PORTE SUR UNE AFFAIRE RÉELLEMENT SOUSCRITE.
 *
 * ── CE QUE CE LOT FERME ─────────────────────────────────────────────────────────────
 * La référence de police était un champ de texte libre : on pouvait y écrire n'importe
 * quoi, et rien ne le relevait jamais. Un sinistre finissait rattaché à une police qui
 * n'existait pas — on s'en apercevait en réclamant à l'assureur.
 *
 * ── CE QUE CE BANC PROTÈGE ──────────────────────────────────────────────────────────
 *   1. une référence inconnue du cabinet est refusée ;
 *   2. une référence d'un AUTRE cabinet l'est aussi — et elle n'est même pas proposée ;
 *   3. une référence d'un AUTRE client de ce cabinet est refusée, en nommant les deux ;
 *   4. une référence valide passe ;
 *   5. la liste ne propose QUE les polices de l'assuré quand il est connu, et une police
 *      portée par trois avenants n'y figure qu'une fois ;
 *   6. ⚠ un sinistre ANCIEN à référence introuvable reste modifiable tant qu'on ne touche
 *      pas à sa référence — sans quoi sa fiche deviendrait inéditable.
 */
class ReferenceDePoliceSinistreTest extends WebTestCase
{
    private const OWNER_EMAIL = 'phpunit-refpolice@test.local';
    private const VOISIN_EMAIL = 'phpunit-refpolice-voisin@test.local';
    private const ENTREPRISE_NOM = 'PHPUnit RefPolice SARL';
    private const ENTREPRISE_VOISINE = 'PHPUnit RefPolice Voisine SARL';

    private const CLIENT_A = 'ZZ-REFPOL-CLIENT-A';
    private const CLIENT_B = 'ZZ-REFPOL-CLIENT-B';

    private const POLICE_A = 'ZZ-POL-CLIENT-A';
    private const POLICE_B = 'ZZ-POL-CLIENT-B';
    private const POLICE_VOISINE = 'ZZ-POL-DU-VOISIN';

    private KernelBrowser $navigateur;

    protected function setUp(): void
    {
        $this->navigateur = static::createClient();
        $this->cleanUp();
    }

    private function connecter(string $email): void
    {
        $this->navigateur->loginUser(
            $this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => $email]),
        );
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
        $emails = [self::OWNER_EMAIL, self::VOISIN_EMAIL];
        $noms = [self::ENTREPRISE_NOM, self::ENTREPRISE_VOISINE];

        $conn->executeStatement(
            'UPDATE utilisateur SET connected_to_id = NULL WHERE email IN (:emails)',
            ['emails' => $emails],
            ['emails' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );
        foreach (['notification_sinistre', 'avenant', 'cotation', 'piste', 'client', 'risque'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t
                 JOIN entreprise e ON t.entreprise_id = e.id
                 WHERE e.nom IN (:noms)",
                ['noms' => $noms],
                ['noms' => \Doctrine\DBAL\ArrayParameterType::STRING],
            );
        }
        $conn->executeStatement(
            'DELETE i FROM invite i
             LEFT JOIN utilisateur u ON i.utilisateur_id = u.id
             LEFT JOIN entreprise e ON i.entreprise_id = e.id
             WHERE u.email IN (:emails) OR e.nom IN (:noms)',
            ['emails' => $emails, 'noms' => $noms],
            ['emails' => \Doctrine\DBAL\ArrayParameterType::STRING, 'noms' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );
        $conn->executeStatement(
            'DELETE FROM entreprise WHERE nom IN (:noms)',
            ['noms' => $noms],
            ['noms' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );
        $conn->executeStatement(
            'DELETE FROM utilisateur WHERE email IN (:emails)',
            ['emails' => $emails],
            ['emails' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );
    }

    /** @return array{0: Entreprise, 1: Invite, 2: Client, 3: Client, 4: Entreprise, 5: Client} */
    private function seed(): array
    {
        $em = $this->em();

        [$entreprise, $invite] = $this->cabinet(self::OWNER_EMAIL, self::ENTREPRISE_NOM);
        [$voisine, $inviteVoisin] = $this->cabinet(self::VOISIN_EMAIL, self::ENTREPRISE_VOISINE);

        $clientA = $this->client(self::CLIENT_A, $entreprise, $invite);
        $clientB = $this->client(self::CLIENT_B, $entreprise, $invite);
        $clientVoisin = $this->client('ZZ-REFPOL-VOISIN', $voisine, $inviteVoisin);

        // La police de A porte TROIS avenants : la liste ne doit la montrer qu'une fois.
        $this->police(self::POLICE_A, $clientA, $entreprise, $invite, 3);
        $this->police(self::POLICE_B, $clientB, $entreprise, $invite, 1);
        $this->police(self::POLICE_VOISINE, $clientVoisin, $voisine, $inviteVoisin, 1);

        $em->flush();

        return [$entreprise, $invite, $clientA, $clientB, $voisine, $clientVoisin];
    }

    private function client(string $nom, Entreprise $entreprise, Invite $invite): Client
    {
        $c = (new Client())->setNom($nom);
        $c->setEntreprise($entreprise)->setInvite($invite);
        $this->em()->persist($c);

        return $c;
    }

    /** Une police = une piste, une cotation, et N avenants qui partagent la référence. */
    private function police(
        string $reference,
        Client $client,
        Entreprise $entreprise,
        Invite $invite,
        int $nombreAvenants,
    ): void {
        $em = $this->em();

        // Le code d'un risque est UNIQUE par cabinet : deux polices du meme cabinet ne
        // peuvent pas porter deux risques homonymes.
        $risque = (new Risque())
            ->setNomComplet('Incendie ' . $reference)
            ->setCode(substr('R' . md5($reference), 0, 10))
            ->setBranche(0)
            ->setImposable(true);
        $risque->setEntreprise($entreprise)->setInvite($invite);
        $em->persist($risque);

        $piste = new Piste();
        $piste->setNom('Souscription — ' . $reference)
            ->setClient($client)
            ->setRisque($risque)
            ->setExercice((int) date('Y'))
            ->setTypeAvenant(Piste::AVENANT_SOUSCRIPTION)
            ->setRenewalCondition(Piste::RENEWAL_CONDITION_RENEWABLE)
            // Colonne NOT NULL qu'aucun defaut ne pose : sans elle, le semis casse en base.
            ->setDescriptionDuRisque('Couverture standard.');
        $piste->setEntreprise($entreprise)->setInvite($invite);
        $em->persist($piste);

        // `duree` est NOT NULL sans defaut : une « valeur de naissance » qu'aucun
        // formulaire ne pose, et que le semis doit donc poser lui-meme.
        $cotation = (new Cotation())->setNom('Cotation ' . $reference)->setDuree(12)->setPiste($piste);
        $cotation->setEntreprise($entreprise)->setInvite($invite);
        $em->persist($cotation);

        for ($i = 1; $i <= $nombreAvenants; $i++) {
            $avenant = new Avenant();
            $avenant->setReferencePolice($reference)
                ->setNumero((string) $i)
                ->setDescription('Avenant ' . $i . ' de ' . $reference)
                ->setCotation($cotation)
                // Dates CALCULÉES : un test arrimé à une date écrite en dur ment un jour.
                ->setStartingAt(new \DateTimeImmutable('-1 month'))
                ->setEndingAt(new \DateTimeImmutable('+11 months'));
            $avenant->setEntreprise($entreprise)->setInvite($invite);
            $em->persist($avenant);
        }
    }

    /** @return array{0: Entreprise, 1: Invite} */
    private function cabinet(string $email, string $nom): array
    {
        $em = $this->em();
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new Utilisateur();
        $user->setEmail($email)->setNom('PHPUnit')->setVerified(true);
        $user->setPassword($hasher->hashPassword($user, 'Test1234!'));
        $em->persist($user);

        $entreprise = (new Entreprise())
            ->setNom($nom)
            ->setLicence('LIC-RP')->setAdresse('1 rue du Test')->setTelephone('+243000000000')
            ->setRccm('RCCM-RP')->setIdnat('IDNAT-RP')->setNumimpot('IMP-RP');
        $entreprise->setUtilisateur($user);
        $user->setConnectedTo($entreprise);
        $em->persist($entreprise);

        $invite = (new Invite())->setNom('Administrateur')->setProprietaire(true);
        $invite->setUtilisateur($user)->setEntreprise($entreprise);
        $em->persist($invite);

        return [$entreprise, $invite];
    }

    private function sinistre(string $reference, Client $assure, Entreprise $entreprise, Invite $invite): NotificationSinistre
    {
        $s = new NotificationSinistre();
        $s->setReferencePolice($reference);
        $s->setReferenceSinistre('SIN-' . $reference);
        $s->setDescriptionDeFait('RAS');
        $s->setOccuredAt(new \DateTimeImmutable('-5 days'));
        $s->setNotifiedAt(new \DateTimeImmutable('-1 day'));
        $s->setAssure($assure);
        $s->setEntreprise($entreprise);
        $s->setInvite($invite);

        return $s;
    }

    /** @return list<string> les messages de violation portés par referencePolice */
    private function violations(NotificationSinistre $sinistre): array
    {
        /** @var ValidatorInterface $validateur */
        $validateur = static::getContainer()->get(ValidatorInterface::class);

        $messages = [];
        foreach ($validateur->validate($sinistre) as $violation) {
            if ($violation->getPropertyPath() === 'referencePolice') {
                $messages[] = (string) $violation->getMessage();
            }
        }

        return $messages;
    }

    // ────────────────────────────────────────────────────────────────────────────────

    public function testUneReferenceInconnueEstRefusee(): void
    {
        [$entreprise, $invite, $clientA] = $this->seed();

        $messages = $this->violations($this->sinistre('POLICE-QUI-N-EXISTE-PAS', $clientA, $entreprise, $invite));

        self::assertNotSame([], $messages, 'Une police inventée ne doit pas passer.');
        self::assertStringContainsString('ne correspond à aucune affaire', $messages[0]);
    }

    public function testUneReferenceDUnAutreCabinetEstRefuseeEtNonProposee(): void
    {
        [$entreprise, $invite, $clientA] = $this->seed();

        $messages = $this->violations($this->sinistre(self::POLICE_VOISINE, $clientA, $entreprise, $invite));
        self::assertNotSame([], $messages, 'Une police d\'un autre cabinet ne doit pas passer.');

        /** @var ReferencesDePolice $service */
        $service = static::getContainer()->get(ReferencesDePolice::class);
        self::assertNotContains(
            self::POLICE_VOISINE,
            $service->pourLeCabinet($entreprise),
            'Elle ne doit pas même être proposée : refuser après coup est le deuxième rempart, pas le premier.',
        );
    }

    public function testUneReferenceDUnAutreClientEstRefuseeEnNommantLesDeux(): void
    {
        [$entreprise, $invite, $clientA] = $this->seed();

        // La police de B, posée sur un sinistre de A.
        $messages = $this->violations($this->sinistre(self::POLICE_B, $clientA, $entreprise, $invite));

        self::assertNotSame([], $messages);
        self::assertStringContainsString(self::CLIENT_B, $messages[0], 'Le message nomme le titulaire réel.');
        self::assertStringContainsString(self::CLIENT_A, $messages[0], 'Et l\'assuré du sinistre.');
    }

    public function testUneReferenceValidePasse(): void
    {
        [$entreprise, $invite, $clientA] = $this->seed();

        self::assertSame([], $this->violations($this->sinistre(self::POLICE_A, $clientA, $entreprise, $invite)));
    }

    public function testLaListeEstFiltreeParAssureEtSansDoublon(): void
    {
        [$entreprise, , $clientA, $clientB] = $this->seed();

        /** @var ReferencesDePolice $service */
        $service = static::getContainer()->get(ReferencesDePolice::class);

        $duCabinet = array_values($service->pourLeCabinet($entreprise));
        self::assertContains(self::POLICE_A, $duCabinet);
        self::assertContains(self::POLICE_B, $duCabinet);
        self::assertSame(
            1,
            count(array_keys($duCabinet, self::POLICE_A, true)),
            'Trois avenants, une seule police : on désigne une affaire, pas l\'un de ses actes.',
        );

        $deA = array_values($service->pourLeCabinet($entreprise, $clientA));
        self::assertSame([self::POLICE_A], $deA, 'Proposer la police d\'un autre client invite à l\'erreur.');

        $deB = array_values($service->pourLeCabinet($entreprise, $clientB));
        self::assertSame([self::POLICE_B], $deB);
    }

    /**
     * LE FORMULAIRE NE PROPOSE QUE LES POLICES DE L'ASSURÉ.
     *
     * Testé par HTTP, et non en construisant le formulaire à la main : la liste dépend du
     * CABINET OUVERT, que seul un utilisateur connecté définit. Un test hors requête
     * mesurerait une liste vide et ne prouverait rien.
     */
    public function testLeFormulaireNePropopseQueLesPolicesDeLAssure(): void
    {
        [, , $clientA] = $this->seed();
        $this->connecter(self::OWNER_EMAIL);

        $this->navigateur->request('GET', sprintf(
            '/admin/notificationsinistre/api/get-form?idClient=%d', $clientA->getId(),
        ));
        self::assertResponseIsSuccessful();

        $html = (string) $this->navigateur->getResponse()->getContent();
        self::assertStringContainsString(self::POLICE_A, $html, "La police de l'assuré doit être proposée.");
        self::assertStringNotContainsString(self::POLICE_B, $html,
            "Proposer la police d'un autre client invite à une erreur que l'enregistrement refusera.");
        self::assertStringNotContainsString(self::POLICE_VOISINE, $html,
            "Et une police d'un autre cabinet ne doit jamais sortir.");
    }

    /**
     * ⚠ UNE FICHE ANCIENNE S'OUVRE ENCORE — et son défaut se voit.
     *
     * Un ChoiceType refuse une valeur hors de ses choix : sans cette précaution, le
     * dialogue d'un vieux sinistre s'ouvrirait en erreur. La valeur enregistrée est donc
     * remise dans la liste, marquée pour ce qu'elle est.
     */
    public function testUneFicheAncienneSOuvreAvecSaReferenceMarquee(): void
    {
        [$entreprise, $invite, $clientA] = $this->seed();
        $em = $this->em();

        $ancien = $this->sinistre('REFERENCE-HISTORIQUE-INTROUVABLE', $clientA, $entreprise, $invite);
        $em->persist($ancien);
        $em->flush();

        $this->connecter(self::OWNER_EMAIL);
        $this->navigateur->request('GET', sprintf(
            '/admin/notificationsinistre/api/get-form/%d', $ancien->getId(),
        ));
        self::assertResponseIsSuccessful();

        $html = (string) $this->navigateur->getResponse()->getContent();
        self::assertStringContainsString('REFERENCE-HISTORIQUE-INTROUVABLE', $html,
            'La fiche doit rester ouvrable : sa référence est remise dans la liste.');
        self::assertStringContainsString('référence introuvable', $html,
            'Et le défaut doit se voir, plutôt que de passer pour une police valide.');
    }

    /**
     * ⚠ LE DOSSIER ANCIEN RESTE MODIFIABLE.
     *
     * Des sinistres portent une référence saisie à la main, d'avant cette règle. La refuser
     * en bloc rendrait leur fiche inéditable : on ne pourrait même plus y corriger une
     * faute de frappe ailleurs. La règle ne porte donc que sur ce qu'on ÉCRIT.
     */
    public function testUnSinistreAncienResteModifiableTantQuOnNeTouchePasALaReference(): void
    {
        [$entreprise, $invite, $clientA] = $this->seed();
        $em = $this->em();

        // On écrit directement en base, comme l'aurait fait l'ancien champ de texte libre.
        $ancien = $this->sinistre('REFERENCE-HISTORIQUE-INTROUVABLE', $clientA, $entreprise, $invite);
        $em->persist($ancien);
        $em->flush();
        $em->clear();

        /** @var NotificationSinistre $recharge */
        $recharge = $em->getRepository(NotificationSinistre::class)->find($ancien->getId());
        $recharge->setLieu('Lubumbashi'); // on modifie AUTRE CHOSE que la référence

        self::assertSame([], $this->violations($recharge),
            'Sa référence est introuvable, mais on n\'y touche pas : la fiche doit rester modifiable.');

        // En revanche, dès qu'on remplace la référence par une autre invalide, c'est refusé.
        $recharge->setReferencePolice('UNE-AUTRE-QUI-N-EXISTE-PAS');
        self::assertNotSame([], $this->violations($recharge),
            'Qui touche à la référence doit en choisir une valide.');
    }
}
