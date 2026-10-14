<?php

namespace App\Tests\Workspace;

use App\Ai\Scope\AiScope;
use App\Ai\Tool\RechercherEntitesTool;
use App\Entity\Client;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Piste;
use App\Entity\Portefeuille;
use App\Entity\Utilisateur;
use App\Services\CanvasBuilder;
use App\Services\DashboardDataProvider;
use App\Services\JSBDynamicSearchService;
use App\Services\Search\PortefeuilleCritereFactory;
use App\Services\Search\ResponsableDePiste;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * « MON PORTEFEUILLE » DES PISTES = LES PISTES DONT JE SUIS LE RESPONSABLE — partout.
 *
 * Le responsable : le gestionnaire du portefeuille du client, sinon le créateur de la piste
 * (ResponsableDePiste). Quatre lectures doivent dire la même chose sur les quatre cas :
 * la carte (son responsable affiché), la liste (« Mon portefeuille »), Ket
 * (rechercher_entites) et la vigie (getPistesEnCours).
 */
class ResponsableDePistePariteTest extends WebTestCase
{
    private const EMAIL = 'phpunit-responsable-piste@test.local';
    private const EMAIL_AUTRE = 'phpunit-responsable-piste-autre@test.local';
    private const CABINET = 'PHPUnit Responsable Piste SARL';

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
     * Les cinq cas, et ce que « Mon portefeuille » doit en retenir.
     *
     * @return array{entreprise: int, moi: int, autre: int, pistes: array<string, int>}
     */
    private function semer(): array
    {
        $em = $this->em();
        $utilisateur = fn (string $email, string $nom) => (new Utilisateur())->setEmail($email)->setNom($nom)->setVerified(true)->setPassword('x');
        $owner = $utilisateur(self::EMAIL, 'Moi');
        $em->persist($owner);
        $autreUser = $utilisateur(self::EMAIL_AUTRE, 'Autre');
        $em->persist($autreUser);
        $cabinet = (new Entreprise())->setNom(self::CABINET)->setLicence('L')->setAdresse('1 rue')
            ->setTelephone('+243000000061')->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($owner);
        $em->persist($cabinet);
        $owner->setConnectedTo($cabinet);
        $moi = (new Invite())->setNom('Moi Gestionnaire')->setUtilisateur($owner)->setEntreprise($cabinet)->setProprietaire(true);
        $em->persist($moi);
        $autre = (new Invite())->setNom('Autre Collègue')->setUtilisateur($autreUser)->setEntreprise($cabinet)->setProprietaire(false);
        $em->persist($autre);

        // Un portefeuille a TOUJOURS un gestionnaire (portefeuille.gestionnaire_id NOT NULL) :
        // le repli sur le créateur ne sert qu'aux clients sans portefeuille.
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
        $piste = function (string $nom, Client $client, Invite $createur) use ($em, $cabinet): Piste {
            $p = (new Piste())->setNom($nom)->setTypeAvenant(Piste::AVENANT_SOUSCRIPTION)->setDescriptionDuRisque('R')
                ->setExercice(2026)->setClient($client);
            $p->setEntreprise($cabinet)->setInvite($createur);
            $em->persist($p);

            return $p;
        };

        $duMien = $client('Client de mon portefeuille', $portefeuille($moi, 'PF Moi'));
        $duSien = $client('Client de son portefeuille', $portefeuille($autre, 'PF Autre'));
        $libre = $client('Client sans portefeuille', null);

        $pistes = [
            'mon portefeuille, créée par un autre' => $piste('P1 mien', $duMien, $autre),
            'son portefeuille, créée par moi'      => $piste('P2 sien', $duSien, $moi),
            'sans portefeuille, créée par moi'     => $piste('P3 libre moi', $libre, $moi),
            'sans portefeuille, créée par un autre' => $piste('P4 libre autre', $libre, $autre),
        ];
        $em->flush();

        $ids = [
            'entreprise' => (int) $cabinet->getId(), 'moi' => (int) $moi->getId(), 'autre' => (int) $autre->getId(),
            'pistes' => array_map(static fn (Piste $p) => (int) $p->getId(), $pistes),
        ];
        $em->clear();

        return $ids;
    }

    public function testLesQuatreLecturesDisentLeMemeResponsable(): void
    {
        $ids = $this->semer();
        $em = $this->em();
        $entreprise = $em->find(Entreprise::class, $ids['entreprise']);
        $moi = $em->find(Invite::class, $ids['moi']);
        $attendues = [
            $ids['pistes']['mon portefeuille, créée par un autre'],
            $ids['pistes']['sans portefeuille, créée par moi'],
        ];
        sort($attendues);
        $trier = static function (array $ids): array {
            sort($ids);

            return array_values(array_unique($ids));
        };

        // 1. LA CARTE : le responsable qu'elle affiche.
        $canvas = static::getContainer()->get(CanvasBuilder::class);
        $carte = [];
        foreach ($ids['pistes'] as $cas => $id) {
            $piste = $em->find(Piste::class, $id);
            $canvas->loadAllCalculatedValues($piste);
            self::assertSame(ResponsableDePiste::de($piste)?->getNom(), $piste->responsableNom, "Carte : « $cas ».");
            if (ResponsableDePiste::de($piste)?->getId() === $ids['moi']) {
                $carte[] = $id;
            }
        }
        self::assertSame($attendues, $trier($carte), 'La carte.');

        // 2. LA LISTE : « Mon portefeuille ».
        $criteres = static::getContainer()->get(PortefeuilleCritereFactory::class)->pour('Piste', $moi);
        self::assertNotSame([], $criteres, 'Les pistes restent soumises au périmètre « Mon portefeuille ».');
        $liste = static::getContainer()->get(JSBDynamicSearchService::class)->search(Piste::class, $criteres, $entreprise, null, 1, 100);
        self::assertSame($attendues, $trier(array_map(static fn (Piste $p) => (int) $p->getId(), $liste['data'])), 'La liste.');

        // 3. KET : rechercher_entites, au périmètre par défaut.
        $ket = static::getContainer()->get(RechercherEntitesTool::class)->execute(['entite' => 'Piste'], new AiScope($entreprise, $moi));
        self::assertSame($attendues, $trier(array_map('intval', array_column($ket->data['items'] ?? [], 'id'))), 'Ket.');

        // 4. LA VIGIE : les pistes en cours de mon portefeuille.
        $vigie = static::getContainer()->get(DashboardDataProvider::class)->getPistesEnCours($entreprise, 60, $moi);
        self::assertSame($attendues, $trier(array_map(static fn (Piste $p) => (int) $p->getId(), $vigie)), 'La vigie.');
    }
}
