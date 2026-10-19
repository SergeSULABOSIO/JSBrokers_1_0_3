<?php

namespace App\Tests\Services;

use App\Entity\Entreprise;
use App\Entity\Groupe;
use App\Entity\Invite;
use App\Entity\Utilisateur;
use App\Services\JSBDynamicSearchService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * UN `%` OU UN `_` TAPÉ PAR L'UTILISATEUR EST DU TEXTE (chantier 11, commit 1).
 *
 * Le terme saisi entrait tel quel dans le motif du LIKE : « 100% » trouvait « 1000 »,
 * « A_B » trouvait « AxB ». Le service échappe désormais le terme (ESCAPE '!') avant d'y
 * poser SES jokers — sur tous les champs, simples comme riches, et dans les autocomplétions
 * qui cherchent une description (Groupe, Taxe).
 */
class RechercheJokersTest extends WebTestCase
{
    private const EMAIL = 'phpunit-jokers@test.local';
    private const EMAIL_SECOND = 'phpunit-jokers-second@test.local';
    private const ENT = 'PHPUnit Jokers SARL';
    private const ENT_VOISIN = 'PHPUnit Jokers Voisin SARL';

    private KernelBrowser $client;
    private Entreprise $entreprise;
    /** @var array<string, int> nom => id */
    private array $groupes = [];

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->cleanUp();
        $em = $this->em();

        $user = (new Utilisateur())->setEmail(self::EMAIL)->setNom('Jokers')->setVerified(true)->setPassword('x');
        $em->persist($user);
        $this->entreprise = $this->cabinet(self::ENT, $user);
        $voisin = $this->cabinet(self::ENT_VOISIN, $user);
        $user->setConnectedTo($this->entreprise);
        $inviteA = (new Invite())->setNom('Agent 100%')->setProprietaire(true)->setUtilisateur($user)->setEntreprise($this->entreprise);
        $inviteB = (new Invite())->setNom('Voisin')->setProprietaire(true)->setUtilisateur($user)->setEntreprise($voisin);
        // Un second collaborateur, pour la recherche À TRAVERS une relation (porte `targetField`).
        $second = (new Utilisateur())->setEmail(self::EMAIL_SECOND)->setNom('Second')->setVerified(true)->setPassword('x');
        $em->persist($second);
        $inviteA2 = (new Invite())->setNom('Agent 1000')->setUtilisateur($second)->setEntreprise($this->entreprise);
        $em->persist($inviteA);
        $em->persist($inviteB);
        $em->persist($inviteA2);

        foreach ([
            'Remise 100% clients' => '<p>Taux 5% fixe</p>',
            'Remise 1000 clients' => '<p>Taux 50 fixe</p>',
            'Code A_B' => '<p>Rang A_B</p>',
            'Code AxB' => '<p>Rang AxB</p>',
            'Alerte! urgent' => '<p>Signal!</p>',
            'Alerte urgent' => '<p>Signal</p>',
            'Double  espace' => '<p>Deux blancs</p>',
        ] as $nom => $description) {
            $groupe = (new Groupe())->setNom($nom)->setDescription($description);
            $groupe->setEntreprise($this->entreprise)->setInvite($inviteA);
            $em->persist($groupe);
            $em->flush();
            $this->groupes[$nom] = $groupe->getId();
        }
        $duSecond = (new Groupe())->setNom('Groupe du second agent')->setDescription('<p>Second</p>');
        $duSecond->setEntreprise($this->entreprise)->setInvite($inviteA2);
        $em->persist($duSecond);
        $em->flush();
        $etranger = (new Groupe())->setNom('Remise 100% voisin')->setDescription('<p>Taux 5% voisin</p>');
        $etranger->setEntreprise($voisin)->setInvite($inviteB);
        $em->persist($etranger);
        $em->flush();
        $this->groupes['voisin'] = $etranger->getId();

        $this->client->loginUser($user);
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

    private function cabinet(string $nom, Utilisateur $user): Entreprise
    {
        $e = (new Entreprise())->setNom($nom)->setLicence('L')->setAdresse('A')->setTelephone('+243')
            ->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($user);
        $this->em()->persist($e);

        return $e;
    }

    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email = ?', [self::EMAIL]);
        $conn->executeStatement('DELETE g FROM groupe g JOIN entreprise e ON e.id = g.entreprise_id WHERE e.nom IN (?, ?)', [self::ENT, self::ENT_VOISIN]);
        $conn->executeStatement('DELETE i FROM invite i JOIN utilisateur u ON u.id = i.utilisateur_id WHERE u.email IN (?, ?)', [self::EMAIL, self::EMAIL_SECOND]);
        $conn->executeStatement('DELETE FROM entreprise WHERE nom IN (?, ?)', [self::ENT, self::ENT_VOISIN]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email IN (?, ?)', [self::EMAIL, self::EMAIL_SECOND]);
    }

    /** @return list<string> noms trouvés par la recherche du service */
    private function chercher(string $champ, string $terme, string $mode = 'contains'): array
    {
        return $this->noms([$champ => ['operator' => 'LIKE', 'value' => $terme, 'mode' => $mode]]);
    }

    /** @return list<string> */
    private function noms(array $criteres): array
    {
        $resultat = static::getContainer()->get(JSBDynamicSearchService::class)->search(
            Groupe::class,
            $criteres,
            $this->entreprise,
        );
        $noms = array_map(static fn (Groupe $g): string => (string) $g->getNom(), $resultat['data'] ?? []);
        sort($noms);

        return $noms;
    }

    public function testUnPourcentTapeEstDuTexteSurUnChampSimple(): void
    {
        self::assertSame(['Remise 100% clients'], $this->chercher('nom', '100%'));
    }

    public function testUnSouligneTapeEstDuTexteSurUnChampSimple(): void
    {
        self::assertSame(['Code A_B'], $this->chercher('nom', 'A_B'));
    }

    public function testLeCaractereDEchappementLuiMemeEstDuTexte(): void
    {
        self::assertSame(['Alerte! urgent'], $this->chercher('nom', 'Alerte!'));
    }

    public function testLesJokersTapesSontDuTexteSurUnChampRiche(): void
    {
        self::assertSame(['Remise 100% clients'], $this->chercher('description', '5%'));
        self::assertSame(['Code A_B'], $this->chercher('description', 'A_B'));
    }

    public function testCommenceParResteUnPrefixe(): void
    {
        self::assertSame(['Remise 100% clients', 'Remise 1000 clients'], $this->chercher('nom', 'Remise 100', 'starts'));
        self::assertSame(['Remise 100% clients'], $this->chercher('nom', 'Remise 100%', 'starts'));
    }

    /** Porte « relation » : LIKE sur le `targetField` du collaborateur lié. */
    public function testLesJokersTapesSontDuTexteATraversUneRelation(): void
    {
        $noms = $this->noms(['invite' => ['operator' => 'LIKE', 'value' => '100%', 'targetField' => 'nom']]);

        self::assertNotContains('Groupe du second agent', $noms, '« Agent 1000 » ne répond pas à « 100% ».');
        self::assertContains('Remise 100% clients', $noms);
    }

    /** Porte « un texte, plusieurs colonnes en OU ». */
    public function testLesJokersTapesSontDuTexteDansLaRechercheMultiColonnes(): void
    {
        $noms = $this->noms([JSBDynamicSearchService::OU_TEXTE_LIBRE => ['champs' => ['nom', 'description'], 'valeur' => 'A_B']]);

        self::assertSame(['Code A_B'], $noms);
    }

    /** Un terme réduit à rien (espaces insécables) ne trouve RIEN — et non tout. */
    public function testUnTermeReduitARienNeTrouveRien(): void
    {
        self::assertSame([], $this->chercher('nom', "\u{00A0}\u{00A0}"));
        self::assertSame([], $this->noms([JSBDynamicSearchService::OU_TEXTE_LIBRE => ['champs' => ['nom'], 'valeur' => "\u{00A0}"]]));
    }

    /** Les blancs intérieurs ne sont pas réduits : un texte collé tel quel se retrouve. */
    public function testUneValeurADoubleEspaceSeRetrouveParSonTexteExact(): void
    {
        self::assertSame(['Double  espace'], $this->chercher('nom', 'Double  espace'));
    }

    /** @return list<int> identifiants proposés par l'endpoint réel d'autocomplétion */
    private function proposes(string $alias, string $saisie): array
    {
        $this->client->request('GET', '/autocomplete/' . $alias, ['query' => $saisie]);
        self::assertResponseIsSuccessful();
        $charge = json_decode((string) $this->client->getResponse()->getContent(), true);

        return array_map(static fn (array $o): int => (int) $o['value'], $charge['results'] ?? []);
    }

    /**
     * GARDE-FOU, pas preuve du changement : ux-autocomplete 2.36 échappait déjà les jokers. Depuis
     * que l'autocomplétion du Groupe passe par NOTRE filter_query (RechercheAutocomplete), c'est
     * notre échappement — et le cloisonnement du query_builder — que ce test tient.
     */
    public function testLAutocompletionDuGroupeEchappeLesJokersEtResteAuCabinet(): void
    {
        $proposes = $this->proposes('groupe_autocomplete_field', '100%');
        self::assertSame([$this->groupes['Remise 100% clients']], $proposes, 'Le « % » tapé doit être du texte, et le voisin rester invisible.');

        self::assertSame([$this->groupes['Code A_B']], $this->proposes('groupe_autocomplete_field', 'A_B'));
        // La description est cherchée aussi, avec le même échappement.
        self::assertSame([$this->groupes['Remise 100% clients']], $this->proposes('groupe_autocomplete_field', '5%'));
    }
}
