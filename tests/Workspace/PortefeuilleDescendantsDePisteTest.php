<?php

namespace App\Tests\Workspace;

use App\Ai\Scope\AiScope;
use App\Ai\Tool\RechercherEntitesTool;
use App\Entity\Avenant;
use App\Entity\Client;
use App\Entity\Cotation;
use App\Entity\Document;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Piste;
use App\Entity\Portefeuille;
use App\Entity\Tache;
use App\Entity\Tranche;
use App\Entity\Utilisateur;
use App\Services\DashboardDataProvider;
use App\Services\JSBDynamicSearchService;
use App\Services\Search\PortefeuilleCritereFactory;
use App\Services\Tranche\TranchePaiementService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * CE QUI DESCEND D'UNE PISTE SUIT LE RESPONSABLE DE LA PISTE (décision du 2026-10-14).
 *
 * Cotations, polices, tranches, tâches et documents rattachés à une piste sont dans
 * « Mon portefeuille » de son RESPONSABLE (ResponsableDePiste : le gestionnaire du
 * portefeuille du client, sinon le créateur de la piste). Avant : le seul gestionnaire —
 * une piste montée pour un client sans portefeuille était dans « Mon portefeuille » de son
 * créateur, mais sa cotation et sa police n'y étaient pas.
 *
 * Parité opposable : la liste, Ket, la vigie (polices, tâches) et le suivi des paiements de
 * prime retiennent exactement les mêmes enregistrements. Et ce qui ne dépend que du CLIENT
 * (un document rattaché au client) garde la règle du portefeuille client.
 */
class PortefeuilleDescendantsDePisteTest extends WebTestCase
{
    private const EMAIL = 'phpunit-descendants-piste@test.local';
    private const EMAIL_AUTRE = 'phpunit-descendants-piste-autre@test.local';
    private const CABINET = 'PHPUnit Descendants Piste SARL';

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        static::createClient();
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
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email IN (?, ?)', [self::EMAIL, self::EMAIL_AUTRE]);
        $id = $conn->fetchOne('SELECT id FROM entreprise WHERE nom = ?', [self::CABINET]);
        if ($id !== false) {
            $tables = $conn->fetchFirstColumn(
                "SELECT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'entreprise_id' AND TABLE_NAME <> 'entreprise'"
            );
            $conn->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
            try {
                foreach ($tables as $table) {
                    $conn->executeStatement("DELETE FROM `$table` WHERE entreprise_id = ?", [$id]);
                }
                $conn->executeStatement('DELETE FROM entreprise WHERE id = ?', [$id]);
            } finally {
                $conn->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
            }
        }
        $conn->executeStatement('DELETE FROM utilisateur WHERE email IN (?, ?)', [self::EMAIL, self::EMAIL_AUTRE]);
        $this->em()->clear();
    }

    /**
     * Quatre pistes (mêmes cas que ResponsableDePistePariteTest), chacune avec sa cotation,
     * sa police, sa tranche, une tâche sur la piste, une tâche sur la cotation et un document
     * de la cotation. Plus un document rattaché au seul CLIENT sans portefeuille.
     *
     * @return array<string, mixed>
     */
    private function semer(): array
    {
        $em = $this->em();
        $maintenant = new \DateTimeImmutable('today');
        $utilisateur = fn (string $email, string $nom) => (new Utilisateur())->setEmail($email)->setNom($nom)->setVerified(true)->setPassword('x');
        $owner = $utilisateur(self::EMAIL, 'Moi');
        $em->persist($owner);
        $autreUser = $utilisateur(self::EMAIL_AUTRE, 'Autre');
        $em->persist($autreUser);
        $cabinet = (new Entreprise())->setNom(self::CABINET)->setLicence('L')->setAdresse('1 rue')
            ->setTelephone('+243000000062')->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($owner);
        $em->persist($cabinet);
        $owner->setConnectedTo($cabinet);
        $moi = (new Invite())->setNom('Moi Gestionnaire')->setUtilisateur($owner)->setEntreprise($cabinet)->setProprietaire(true);
        $em->persist($moi);
        $autre = (new Invite())->setNom('Autre Collègue')->setUtilisateur($autreUser)->setEntreprise($cabinet)->setProprietaire(false);
        $em->persist($autre);

        $portefeuille = function (Invite $gestionnaire, string $nom) use ($em, $cabinet): Portefeuille {
            $pf = (new Portefeuille())->setNom($nom)->setGestionnaire($gestionnaire);
            $pf->setEntreprise($cabinet);
            $em->persist($pf);

            return $pf;
        };
        $client = function (string $nom, ?Portefeuille $pf) use ($em, $cabinet): Client {
            $c = (new Client())->setNom($nom)->setExonere(false);
            $c->setEntreprise($cabinet)->setPortefeuille($pf);
            $em->persist($c);

            return $c;
        };
        $dossier = function (string $nom, Client $client, Invite $createur) use ($em, $cabinet, $maintenant): array {
            $piste = (new Piste())->setNom($nom)->setTypeAvenant(Piste::AVENANT_SOUSCRIPTION)->setDescriptionDuRisque('R')
                ->setExercice(2026)->setClient($client);
            $piste->setEntreprise($cabinet)->setInvite($createur);
            $em->persist($piste);
            $cotation = (new Cotation())->setNom('Cotation ' . $nom)->setDuree(12)->setPiste($piste);
            $cotation->setEntreprise($cabinet)->setInvite($createur);
            $em->persist($cotation);
            // Non renouvelable : la vigie la range dans ses polices « à ne pas renouveler ».
            $avenant = (new Avenant())->setStartingAt($maintenant->modify('-30 days'))->setEndingAt($maintenant->modify('+335 days'))
                ->setDescription('Police ' . $nom)->setReferencePolice('POL-' . $nom)->setNonRenouvelable(true)->setCotation($cotation);
            $avenant->setEntreprise($cabinet)->setInvite($createur);
            $em->persist($avenant);
            $tranche = (new Tranche())->setNom('Tranche ' . $nom)->setPourcentage(100.0)
                ->setPayableAt($maintenant->modify('-30 days'))->setEcheanceAt($maintenant->modify('-5 days'))->setCotation($cotation);
            $tranche->setEntreprise($cabinet)->setInvite($createur);
            $em->persist($tranche);
            $tachePiste = (new Tache())->setDescription('Tâche piste ' . $nom)->setToBeEndedAt($maintenant->modify('+7 days'))->setClosed(false)->setPiste($piste);
            $tachePiste->setEntreprise($cabinet)->setInvite($createur);
            $em->persist($tachePiste);
            $tacheCotation = (new Tache())->setDescription('Tâche cotation ' . $nom)->setToBeEndedAt($maintenant->modify('+7 days'))->setClosed(false)->setCotation($cotation);
            $tacheCotation->setEntreprise($cabinet)->setInvite($createur);
            $em->persist($tacheCotation);
            $document = (new Document())->setNom('Document ' . $nom)->setCotation($cotation);
            $document->setEntreprise($cabinet)->setInvite($createur);
            $em->persist($document);

            return compact('piste', 'cotation', 'avenant', 'tranche', 'tachePiste', 'tacheCotation', 'document');
        };

        $duMien = $client('Client de mon portefeuille', $portefeuille($moi, 'PF Moi'));
        $duSien = $client('Client de son portefeuille', $portefeuille($autre, 'PF Autre'));
        $libre = $client('Client sans portefeuille', null);

        $dossiers = [
            'mien' => $dossier('D1 mien', $duMien, $autre),
            'sien' => $dossier('D2 sien', $duSien, $moi),
            'libre moi' => $dossier('D3 libre moi', $libre, $moi),
            'libre autre' => $dossier('D4 libre autre', $libre, $autre),
        ];
        // Ce qui ne dépend que du CLIENT garde la règle du client : un document du client
        // sans portefeuille n'est à personne… donc VISIBLE de tous (orphelin toléré), et un
        // document du client d'un autre gestionnaire ne m'appartient pas.
        $docClientSien = (new Document())->setNom('Document client sien')->setClient($duSien);
        $docClientSien->setEntreprise($cabinet)->setInvite($moi);
        $em->persist($docClientSien);
        $em->flush();

        $ids = [
            'entreprise' => (int) $cabinet->getId(),
            'moi' => (int) $moi->getId(),
            'docClientSien' => (int) $docClientSien->getId(),
        ];
        foreach ($dossiers as $cas => $d) {
            foreach ($d as $cle => $entite) {
                $ids['dossiers'][$cas][$cle] = (int) $entite->getId();
            }
        }
        $em->clear();

        return $ids;
    }

    /** Les identifiants attendus : ceux des dossiers « mien » et « libre moi ». */
    private static function attendus(array $ids, string $cle): array
    {
        $attendus = [$ids['dossiers']['mien'][$cle], $ids['dossiers']['libre moi'][$cle]];
        sort($attendus);

        return $attendus;
    }

    private static function trier(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);

        return $ids;
    }

    public function testLaListeEtKetSuiventLeResponsableDeLaPiste(): void
    {
        $ids = $this->semer();
        $em = $this->em();
        $entreprise = $em->find(Entreprise::class, $ids['entreprise']);
        $moi = $em->find(Invite::class, $ids['moi']);
        // Le lecteur, comme sa session en production : la frontière des tâches le lit.
        static::getContainer()->get(\App\Service\Workspace\InviteCourant::class)->imposer($moi);
        $criteres = static::getContainer()->get(PortefeuilleCritereFactory::class);
        $moteur = static::getContainer()->get(JSBDynamicSearchService::class);
        $ket = static::getContainer()->get(RechercherEntitesTool::class);

        foreach (['Cotation' => 'cotation', 'Avenant' => 'avenant', 'Tranche' => 'tranche'] as $entite => $cle) {
            $liste = $moteur->search('App\\Entity\\' . $entite, $criteres->pour($entite, $moi), $entreprise, null, 1, 100);
            self::assertSame(self::attendus($ids, $cle), self::trier(array_map(static fn ($e) => $e->getId(), $liste['data'])), "La liste des {$entite}.");

            $resultat = $ket->execute(['entite' => $entite], new AiScope($entreprise, $moi));
            self::assertSame(self::attendus($ids, $cle), self::trier(array_column($resultat->data['items'] ?? [], 'id')), "Ket, {$entite}.");
        }

        // Les tâches : celles de la piste ET celles de la cotation.
        $taches = $moteur->search(Tache::class, $criteres->pour('Tache', $moi), $entreprise, null, 1, 100);
        self::assertSame(
            self::trier(array_merge(self::attendus($ids, 'tachePiste'), self::attendus($ids, 'tacheCotation'))),
            self::trier(array_map(static fn (Tache $t) => $t->getId(), $taches['data'])),
            'La liste des tâches.',
        );

        // Les documents : ceux des cotations à moi ; le document du client d'un autre
        // gestionnaire reste hors de mon portefeuille (règle du client).
        $documents = $moteur->search(Document::class, $criteres->pour('Document', $moi), $entreprise, null, 1, 100);
        $vus = self::trier(array_map(static fn (Document $d) => $d->getId(), $documents['data']));
        self::assertSame(self::attendus($ids, 'document'), $vus, 'La liste des documents.');
        self::assertNotContains($ids['docClientSien'], $vus, 'Le document du client d’un autre gestionnaire n’est pas à moi.');
    }

    public function testLaVigieEtLeSuiviDesPrimesDisentLaMemeChose(): void
    {
        $ids = $this->semer();
        $em = $this->em();
        $entreprise = $em->find(Entreprise::class, $ids['entreprise']);
        $moi = $em->find(Invite::class, $ids['moi']);
        // Le lecteur, comme sa session en production : la frontière des tâches le lit.
        static::getContainer()->get(\App\Service\Workspace\InviteCourant::class)->imposer($moi);
        $tableau = static::getContainer()->get(DashboardDataProvider::class);

        $polices = $tableau->getAvenantsNonRenouvelables($entreprise, $moi);
        self::assertSame(self::attendus($ids, 'avenant'), self::trier(array_map(static fn (Avenant $a) => $a->getId(), $polices)), 'Vigie : polices.');

        $taches = $tableau->getTachesNonCloses($entreprise, 100, $moi);
        self::assertSame(
            self::trier(array_merge(self::attendus($ids, 'tachePiste'), self::attendus($ids, 'tacheCotation'))),
            self::trier(array_map(static fn (Tache $t) => $t->getId(), $taches)),
            'Vigie : tâches.',
        );

        $tranches = static::getContainer()->get(TranchePaiementService::class)->lister($entreprise, [], null, null, 1, 100, $moi);
        self::assertSame(self::attendus($ids, 'tranche'), self::trier(array_map(static fn ($t) => $t->getId(), $tranches['items'])), 'Suivi des paiements de prime.');
    }
}
