<?php

namespace App\Tests\Services;

use App\Entity\Client;
use App\Entity\Entreprise;
use App\Entity\Tache;
use App\Entity\Taxe;
use App\Entity\Groupe;
use App\Entity\Invite;
use App\Entity\Utilisateur;
use App\Services\JSBDynamicSearchService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * LA RECHERCHE PORTE SUR LE TEXTE SAISI, PAS SUR LE HTML (chantier 11, commit 2).
 *
 * Un champ riche est stocké en HTML (<p>Relancer <strong>le client</strong></p>) : la
 * recherche faisait son LIKE sur ce HTML. « strong » trouvait la fiche ; « Relancer le
 * client » ne la trouvait pas (une balise coupait la phrase) ; « A & B » non plus (stocké
 * &amp;). La recherche lit désormais le texte (TEXTE_DU_HTML), aux trois portes du service
 * et dans les autocomplétions qui cherchent une description.
 */
class RechercheTexteRicheTest extends WebTestCase
{
    private const EMAIL = 'phpunit-recherche-riche@test.local';
    private const ENT = 'PHPUnit Recherche Riche SARL';
    private const ENT_VOISIN = 'PHPUnit Recherche Riche Voisin SARL';

    private KernelBrowser $client;
    private Entreprise $entreprise;
    /** @var array<string, int> */
    private array $ids = [];

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->cleanUp();
        $em = $this->em();

        $user = (new Utilisateur())->setEmail(self::EMAIL)->setNom('Recherche')->setVerified(true)->setPassword('x');
        $em->persist($user);
        $this->entreprise = $this->cabinet(self::ENT, $user);
        $voisin = $this->cabinet(self::ENT_VOISIN, $user);
        $user->setConnectedTo($this->entreprise);
        $inviteA = (new Invite())->setNom('Proprio')->setProprietaire(true)->setUtilisateur($user)->setEntreprise($this->entreprise);
        $inviteB = (new Invite())->setNom('Voisin')->setProprietaire(true)->setUtilisateur($user)->setEntreprise($voisin);
        $em->persist($inviteA);
        $em->persist($inviteB);

        $this->groupe('relance', '<p>Relancer <strong>le client</strong> A &amp; B</p>', $inviteA);
        $this->groupe('tache', '<p>Tâche urgente</p>', $inviteA);
        $this->groupe('paragraphes', '<p>premier</p><p>second</p>', $inviteA);
        $this->groupe('partiel', '<p>Re<strong>prise</strong> partielle</p>', $inviteA);
        $this->groupe('vide', '<p><br></p>', $inviteA);
        $this->groupe('simple', 'délai < 10 jours', $inviteA);
        $this->groupe('voisin', '<p>Relancer <strong>le client</strong> du voisin</p>', $inviteB);
        $em->flush();

        // Porte « relation » : un client cherché par la description (riche) de son groupe.
        $client = (new Client())->setNom('Client du groupe relance')->setExonere(false);
        $client->setEntreprise($this->entreprise);
        $client->setGroupe($em->getRepository(Groupe::class)->find($this->ids['relance']));
        $em->persist($client);
        // Autocomplétion de la Taxe : description riche.
        $taxe = (new Taxe())->setCode('TX-RICHE')->setDescription('<p>Taxe <strong>riche</strong> &amp; claire</p>')
            ->setRedevable(Taxe::REDEVABLE_COURTIER)->setTauxIARD('1')->setTauxVIE('1');
        $taxe->setEntreprise($this->entreprise);
        $em->persist($taxe);
        $em->flush();
        $this->ids['client'] = $client->getId();
        $this->ids['taxe'] = $taxe->getId();

        // Héritage d'avant l'assainissement (C6) : une image base64 collée, écrite par SQL.
        $conn = $em->getConnection();
        $conn->executeStatement('UPDATE groupe SET description = ? WHERE id = ?', ['<p>photo<img src="data:image/png;base64,QUJDREVGR0g="></p>', $this->groupeImage($inviteA)]);

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

    private function groupe(string $cle, string $description, Invite $invite): void
    {
        $g = (new Groupe())->setNom('G ' . $cle)->setDescription($description);
        $g->setEntreprise($invite->getEntreprise())->setInvite($invite);
        $this->em()->persist($g);
        $this->em()->flush();
        $this->ids[$cle] = $g->getId();
    }

    private function groupeImage(Invite $invite): int
    {
        $this->groupe('image', '<p>photo</p>', $invite);

        return $this->ids['image'];
    }

    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email = ?', [self::EMAIL]);
        $conn->executeStatement('DELETE t FROM tache t JOIN entreprise e ON e.id = t.entreprise_id WHERE e.nom IN (?, ?)', [self::ENT, self::ENT_VOISIN]);
        $conn->executeStatement('DELETE c FROM client c JOIN entreprise e ON e.id = c.entreprise_id WHERE e.nom IN (?, ?)', [self::ENT, self::ENT_VOISIN]);
        $conn->executeStatement('DELETE t FROM taxe t JOIN entreprise e ON e.id = t.entreprise_id WHERE e.nom IN (?, ?)', [self::ENT, self::ENT_VOISIN]);
        $conn->executeStatement('DELETE g FROM groupe g JOIN entreprise e ON e.id = g.entreprise_id WHERE e.nom IN (?, ?)', [self::ENT, self::ENT_VOISIN]);
        $conn->executeStatement('DELETE i FROM invite i JOIN utilisateur u ON u.id = i.utilisateur_id WHERE u.email = ?', [self::EMAIL]);
        $conn->executeStatement('DELETE FROM entreprise WHERE nom IN (?, ?)', [self::ENT, self::ENT_VOISIN]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = ?', [self::EMAIL]);
    }

    /** @return list<string> clés des groupes trouvés */
    private function trouve(array $criteres, string $classe = Groupe::class): array
    {
        $r = static::getContainer()->get(JSBDynamicSearchService::class)->search($classe, $criteres, $this->entreprise);
        // La page ET son comptage : `data` est rempli avant le comptage, une erreur de celui-ci
        // (paramètre manquant, fonction mal parsée) passerait sinon pour un succès.
        self::assertNull($r['status']['error'] ?? null, 'La recherche a échoué : ' . json_encode($r['status'] ?? null, JSON_UNESCAPED_UNICODE));
        self::assertSame(\count($r['data'] ?? []), (int) ($r['totalItems'] ?? -1), 'Le comptage ne dit pas la même chose que la page.');
        $cles = array_map(fn (object $g): string => (string) array_search($g->getId(), $this->ids, true), $r['data'] ?? []);
        sort($cles);

        return $cles;
    }

    private function contient(string $terme, string $mode = 'contains'): array
    {
        return $this->trouve(['description' => ['operator' => 'LIKE', 'value' => $terme, 'mode' => $mode]]);
    }

    /**
     * UN CRITÈRE QUE LA RUBRIQUE NE CONNAÎT PAS EST IGNORÉ, IL NE FAIT PAS PLANTER LA LISTE.
     *
     * Production, 2026-10-20 (supervision, 7 occurrences) : « Class App\Entity\Tranche has no
     * field or association named __echeance_avenant__ ». Le chip d'échéance des Avenants était
     * parti dans une requête sur les Tranches, et la porte « champ simple » le passait tel quel
     * à Doctrine — 500, liste vide. Le serveur garantit : un nom absent des métadonnées de
     * l'entité est écarté, comme un filtre « Tous ». Le cabinet, lui, reste filtré quoi qu'il arrive.
     */
    public function testUnCritereInconnuDeLaRubriqueEstIgnore(): void
    {
        $tache = ['description' => ['operator' => 'LIKE', 'value' => 'Tâche urgente', 'mode' => 'contains']];

        self::assertSame(['tache'], $this->trouve(['__echeance_avenant__' => ['operator' => '=', 'value' => 'echus', 'label' => 'Échus']] + $tache), 'Critère synthétique d\'une autre rubrique (le chip).');
        self::assertSame(['tache'], $this->trouve(['champInexistant' => ['operator' => 'LIKE', 'value' => 'x']] + $tache), 'Champ inconnu, opérateur LIKE.');
        self::assertSame(['tache'], $this->trouve(['champInexistant' => 'x'] + $tache), 'Champ inconnu, valeur simple.');
    }

    public function testUnePhraseCoupeeParUneBaliseSeRetrouve(): void
    {
        self::assertSame(['relance'], $this->contient('Relancer le client'));
        self::assertSame(['relance'], $this->contient('le client A'));
    }

    public function testUneEntiteSeCherchePourCeQuElleAffiche(): void
    {
        self::assertSame(['relance'], $this->contient('A & B'));
    }

    public function testLeBalisageNeSeCherchePas(): void
    {
        self::assertSame([], $this->contient('strong'));
        self::assertSame([], $this->contient('<p>'));
        self::assertSame([], $this->contient('base64'), 'Le contenu d\'une image n\'est pas du texte.');
        self::assertSame([], $this->contient('QUJDREVG'));
    }

    public function testCasseEtAccentsCommeAujourdhui(): void
    {
        self::assertSame(['tache'], $this->contient('TACHE URGENTE'));
        self::assertSame(['relance'], $this->contient('relancer'));
    }

    public function testAcrossDeuxParagraphesEtGrasPartiel(): void
    {
        self::assertSame(['paragraphes'], $this->contient('premier second'));
        self::assertSame(['partiel'], $this->contient('Reprise partielle'));
    }

    public function testBlancsDuTermeReduitsSurUnChampRiche(): void
    {
        self::assertSame(['relance'], $this->contient("Relancer\u{00A0}  le   client"));
    }

    public function testCommenceParPorteSurLeTexte(): void
    {
        self::assertSame(['relance'], $this->contient('Relancer le', 'starts'));
        self::assertSame([], $this->contient('<p>Relancer', 'starts'));
    }

    public function testUnContenuVideNeRepondPasAUnTexte(): void
    {
        self::assertNotContains('vide', $this->contient('br'));
    }

    public function testUnTexteSimpleHeriteSeCherchePourCeQuIlEst(): void
    {
        self::assertSame(['simple'], $this->contient('délai < 10'));
    }

    public function testLaRechercheMultiColonnesLitLeTexte(): void
    {
        $cles = $this->trouve([JSBDynamicSearchService::OU_TEXTE_LIBRE => ['champs' => ['nom', 'description'], 'valeur' => 'Relancer le client']]);

        self::assertSame(['relance'], $cles, 'Et le groupe du voisin reste invisible.');
    }

    /**
     * LA ROUTE DE L'ÉCRAN, PAS SEULEMENT LE SERVICE : la barre de recherche de la rubrique
     * Tâches poste ses critères à `/admin/tache/api/dynamic-query/…`, qui ajoute ses propres
     * critères (portefeuille, vue) avant d'appeler le service.
     */
    public function testLaRouteDeLaRubriqueTachesLitLeTexte(): void
    {
        $em = $this->em();
        $invite = $em->getRepository(Invite::class)->findOneBy(['entreprise' => $this->entreprise]);
        foreach (['<p>Relancer <strong>le client</strong> pour la prime</p>', '<p>Classer le dossier</p>'] as $description) {
            $tache = (new Tache())->setDescription($description)->setToBeEndedAt(new \DateTimeImmutable('+5 days'))->setClosed(false);
            $tache->setEntreprise($this->entreprise);
            $tache->setInvite($invite);
            $em->persist($tache);
        }
        $em->flush();

        $poster = function (string $terme) use ($invite): string {
            $this->client->request('POST', sprintf('/admin/tache/api/dynamic-query/%d/%d', $invite->getId(), $this->entreprise->getId()), [], [], ['CONTENT_TYPE' => 'application/json'],
                (string) json_encode(['criteria' => ['description' => ['operator' => 'LIKE', 'value' => $terme]]]));
            self::assertResponseIsSuccessful();

            return (string) $this->client->getResponse()->getContent();
        };

        $reponse = $poster('Relancer le client');
        self::assertStringContainsString('Relancer le client pour la prime', $reponse);
        self::assertStringNotContainsString('Classer le dossier', $reponse);
        self::assertStringNotContainsString('Relancer le client pour la prime', $poster('strong'));
    }

    /** Porte « relation » : le targetField riche d'une entité liée est lu en texte. */
    public function testUneRelationVersUnChampRicheLitLeTexte(): void
    {
        $cles = $this->trouve(['groupe' => ['operator' => 'LIKE', 'value' => 'Relancer le client', 'targetField' => 'description']], Client::class);

        self::assertSame(['client'], $cles);
        self::assertSame([], $this->trouve(['groupe' => ['operator' => 'LIKE', 'value' => 'strong', 'targetField' => 'description']], Client::class));
    }

    /** « Exact » (opérateur =) sur un champ riche : le texte, terme normalisé comme lui. */
    public function testLEgaliteSurUnChampRicheComparLeTexte(): void
    {
        self::assertSame(['relance'], $this->trouve(['description' => ['operator' => '=', 'value' => 'Relancer  le client A & B']]));
        self::assertNotContains('relance', $this->trouve(['description' => ['operator' => '!=', 'value' => 'Relancer le client A & B']]));
    }

    public function testLAutocompletionDeLaTaxeLitLeTexteEtLeMontre(): void
    {
        $this->client->request('GET', '/autocomplete/taxe_autocomplete_field', ['query' => 'riche & claire']);
        self::assertResponseIsSuccessful();
        $charge = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertSame([$this->ids['taxe']], array_map(static fn (array $o): int => (int) $o['value'], $charge['results'] ?? []));
        self::assertStringNotContainsString('&lt;p&gt;', (string) ($charge['results'][0]['text'] ?? ''));
    }

    /** @return list<int> */
    private function proposes(string $saisie): array
    {
        $this->client->request('GET', '/autocomplete/groupe_autocomplete_field', ['query' => $saisie]);
        self::assertResponseIsSuccessful();
        $charge = json_decode((string) $this->client->getResponse()->getContent(), true);

        return array_map(static fn (array $o): int => (int) $o['value'], $charge['results'] ?? []);
    }

    public function testLAutocompletionLitLeTexteEtResteAuCabinet(): void
    {
        self::assertSame([$this->ids['relance']], $this->proposes('Relancer le client'));
        self::assertSame([], $this->proposes('strong'));
    }

    public function testLeLibelleDeLAutocompletionMontreLeTexte(): void
    {
        $this->client->request('GET', '/autocomplete/groupe_autocomplete_field', ['query' => 'Relancer']);
        $charge = json_decode((string) $this->client->getResponse()->getContent(), true);
        $libelle = (string) ($charge['results'][0]['text'] ?? '');

        self::assertStringContainsString('Relancer le client A &amp; B', $libelle, 'Le texte de la description, échappé une fois.');
        self::assertStringNotContainsString('&lt;p&gt;', $libelle, 'Pas de balise affichée dans la proposition.');
    }
}
