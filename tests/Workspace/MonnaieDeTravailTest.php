<?php

namespace App\Tests\Workspace;

use App\Ai\Mutation\MutationOperation;
use App\Ai\Scope\AiScope;
use App\Entity\Client;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Monnaie;
use App\Entity\Piste;
use App\Entity\Utilisateur;
use App\Service\Workspace\WorkspaceMutationService;
use App\Services\ServiceInitialisationEntreprise;
use App\Services\ServiceMonnaies;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * UN CABINET TRAVAILLE DANS UNE SEULE MONNAIE (décisions du 2026-10-14 et du 2026-10-15).
 *
 * Saisie = affichage = calcul. Aucun montant ne porte sa monnaie et aucun n'est jamais
 * converti : l'écran affichait la monnaie d'affichage pendant la saisie, l'existant y est
 * donc déjà. La monnaie du pays reste une RÉFÉRENCE (capital social, taux, mentions
 * légales). Un nouveau cabinet travaille en USD ; il peut en changer tant qu'il n'a
 * enregistré aucun montant — après, ses montants se liraient dans une autre monnaie.
 */
class MonnaieDeTravailTest extends WebTestCase
{
    private const EMAIL = 'phpunit-monnaie-travail@test.local';
    private const CABINET = 'PHPUnit Monnaie Travail SARL';

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
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email = ?', [self::EMAIL]);
        foreach ($conn->fetchFirstColumn('SELECT id FROM entreprise WHERE nom = ?', [self::CABINET]) as $id) {
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
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = ?', [self::EMAIL]);
        $this->em()->clear();
    }

    /**
     * Un cabinet neuf, semé comme en production (pays 180 : hors USD), propriétaire connecté.
     *
     * @return array{entreprise: int, invite: int}
     */
    private function semer(): array
    {
        $em = $this->em();
        $owner = (new Utilisateur())->setEmail(self::EMAIL)->setNom('Propriétaire')->setVerified(true)->setPassword('x');
        $em->persist($owner);
        $cabinet = (new Entreprise())->setNom(self::CABINET)->setLicence('L')->setAdresse('1 rue')
            ->setTelephone('+243000000063')->setRccm('R')->setIdnat('I')->setNumimpot('N')->setPays(180)->setUtilisateur($owner);
        $em->persist($cabinet);
        $owner->setConnectedTo($cabinet);
        $invite = (new Invite())->setNom('Propriétaire')->setUtilisateur($owner)->setEntreprise($cabinet)->setProprietaire(true);
        $em->persist($invite);
        $em->flush();
        static::getContainer()->get(ServiceInitialisationEntreprise::class)->initialiser($cabinet, $invite);
        $em->flush();

        $this->client->loginUser($owner);

        return ['entreprise' => (int) $cabinet->getId(), 'invite' => (int) $invite->getId()];
    }

    private function monnaie(int $entreprise, string $code): ?Monnaie
    {
        $this->em()->clear();

        return $this->em()->getRepository(Monnaie::class)->findOneBy(['entreprise' => $entreprise, 'code' => $code]);
    }

    /** POST du dialogue Monnaie ; rend le statut et le corps décodé. */
    private function soumettre(array $s, array $champs): array
    {
        $this->client->request('POST', '/admin/monnaie/api/submit', $champs + [
            'idEntreprise' => $s['entreprise'],
            'idInvite' => $s['invite'],
        ]);
        $reponse = $this->client->getResponse();

        return ['statut' => $reponse->getStatusCode(), 'texte' => json_encode(json_decode((string) $reponse->getContent(), true), JSON_UNESCAPED_UNICODE)];
    }

    /** Un montant enregistré : la prime potentielle d'une piste. */
    private function enregistrerUnMontant(int $entreprise, int $invite): void
    {
        $em = $this->em();
        $cabinet = $em->find(Entreprise::class, $entreprise);
        $client = (new Client())->setNom('Client à prime')->setExonere(false);
        $client->setEntreprise($cabinet);
        $em->persist($client);
        $piste = (new Piste())->setNom('Piste à prime')->setTypeAvenant(Piste::AVENANT_SOUSCRIPTION)->setDescriptionDuRisque('R')
            ->setExercice(2026)->setClient($client)->setPrimePotentielle(1000.0);
        $piste->setEntreprise($cabinet)->setInvite($em->find(Invite::class, $invite));
        $em->persist($piste);
        $em->flush();
    }

    public function testUnNouveauCabinetTravailleEnUsdEtGardeSaMonnaieLocaleEnReference(): void
    {
        $s = $this->semer();

        $monnaies = $this->em()->getRepository(Monnaie::class)->findBy(['entreprise' => $s['entreprise']]);
        $deTravail = array_values(array_filter($monnaies, static fn (Monnaie $m) => $m->getFonction() === Monnaie::FONCTION_SAISIE_ET_AFFICHAGE));
        self::assertCount(1, $deTravail, 'Une seule monnaie de travail.');
        self::assertSame('USD', $deTravail[0]->getCode());

        $locales = array_values(array_filter($monnaies, static fn (Monnaie $m) => $m->isLocale() === true));
        self::assertCount(1, $locales, 'La monnaie du pays reste, pour le capital et les mentions légales.');
        self::assertSame(Monnaie::FONCTION_AUCUNE, $locales[0]->getFonction(), 'Elle n’est plus une monnaie « de saisie ».');
    }

    public function testUneSecondeMonnaieDeTravailEstRefusee(): void
    {
        $s = $this->semer();

        $refus = $this->soumettre($s, ['nom' => 'Euro', 'code' => 'EUR', 'tauxusd' => '0.92', 'fonction' => (string) Monnaie::FONCTION_SAISIE_ET_AFFICHAGE, 'locale' => '0']);
        self::assertSame(422, $refus['statut']);
        self::assertStringContainsString('Le cabinet travaille déjà en USD', $refus['texte']);

        $reference = $this->soumettre($s, ['nom' => 'Euro', 'code' => 'EUR', 'tauxusd' => '0.92', 'fonction' => (string) Monnaie::FONCTION_AUCUNE, 'locale' => '0']);
        self::assertSame(200, $reference['statut'], $reference['texte']);
    }

    /** Les fonctions « saisie seule » et « affichage seul » n'existent plus : saisie = affichage. */
    public function testLesFonctionsSeparementSaisieOuAffichageSontRefusees(): void
    {
        $s = $this->semer();

        foreach ([Monnaie::FONCTION_SAISIE_UNIQUEMENT, Monnaie::FONCTION_AFFICHAGE_UNIQUEMENT] as $fonction) {
            $refus = $this->soumettre($s, ['nom' => 'Euro', 'code' => 'EUR', 'tauxusd' => '0.92', 'fonction' => (string) $fonction, 'locale' => '0']);
            self::assertSame(422, $refus['statut'], (string) $fonction);
        }
    }

    /** Sans montant enregistré, le cabinet change de monnaie de travail : il n'y a rien à relire. */
    public function testSansMontantLeCabinetChangeDeMonnaieDeTravail(): void
    {
        $s = $this->semer();
        $usd = $this->monnaie($s['entreprise'], 'USD');
        $locale = $this->em()->getRepository(Monnaie::class)->findOneBy(['entreprise' => $s['entreprise'], 'locale' => true]);

        $retrait = $this->soumettre($s, ['id' => (string) $usd->getId(), 'fonction' => (string) Monnaie::FONCTION_AUCUNE]);
        self::assertSame(200, $retrait['statut'], $retrait['texte']);
        $promotion = $this->soumettre($s, ['id' => (string) $locale->getId(), 'fonction' => (string) Monnaie::FONCTION_SAISIE_ET_AFFICHAGE]);
        self::assertSame(200, $promotion['statut'], $promotion['texte']);

        self::assertSame($locale->getCode(), static::getContainer()->get(ServiceMonnaies::class)
            ->getMonnaieAffichagePourEntreprise($this->em()->find(Entreprise::class, $s['entreprise']))?->getCode());
    }

    /** Avec des montants, changer la monnaie de travail est refusé — ni sa fonction, ni son code. */
    public function testAvecDesMontantsLaMonnaieDeTravailNeChangePlus(): void
    {
        $s = $this->semer();
        $this->enregistrerUnMontant($s['entreprise'], $s['invite']);
        $usd = $this->monnaie($s['entreprise'], 'USD');

        foreach ([['fonction' => (string) Monnaie::FONCTION_AUCUNE], ['code' => 'CDF']] as $changement) {
            $refus = $this->soumettre($s, ['id' => (string) $usd->getId()] + $changement);
            self::assertSame(422, $refus['statut'], json_encode($changement));
            self::assertStringContainsString('Vos montants sont enregistrés en USD', $refus['texte']);
        }
        self::assertSame(Monnaie::FONCTION_SAISIE_ET_AFFICHAGE, $this->monnaie($s['entreprise'], 'USD')?->getFonction());

        // Son nom et son taux, eux, restent éditables.
        $renomme = $this->soumettre($s, ['id' => (string) $usd->getId(), 'nom' => 'Dollar US']);
        self::assertSame(200, $renomme['statut'], $renomme['texte']);
    }

    /** La monnaie de travail d'un cabinet qui a des montants ne se supprime pas (409 qui le dit). */
    public function testLaMonnaieDeTravailAvecDesMontantsNeSeSupprimePas(): void
    {
        $s = $this->semer();
        $this->enregistrerUnMontant($s['entreprise'], $s['invite']);
        $usd = $this->monnaie($s['entreprise'], 'USD');

        $this->client->request('DELETE', '/admin/monnaie/api/delete/' . $usd->getId());

        self::assertSame(409, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('La monnaie USD est la monnaie de travail du cabinet',
            json_encode(json_decode((string) $this->client->getResponse()->getContent(), true), JSON_UNESCAPED_UNICODE));
        self::assertNotNull($this->monnaie($s['entreprise'], 'USD'));
    }

    /**
     * DEUX MONNAIES DE TRAVAIL (une course entre deux créations, que rien en base n'interdit)
     * ne forment pas une impasse : la SURNUMÉRAIRE se retire et se supprime, la retenue — la
     * première, celle que l'écran affiche — reste verrouillée mais se renomme.
     */
    public function testDeuxMonnaiesDeTravailNeFormentPasUneImpasse(): void
    {
        $s = $this->semer();
        $this->enregistrerUnMontant($s['entreprise'], $s['invite']);
        $conn = $this->em()->getConnection();
        $conn->executeStatement(
            'INSERT INTO monnaie (entreprise_id, nom, code, tauxusd, fonction, locale, created_at) VALUES (?, ?, ?, 0.92, 0, 0, NOW())',
            [$s['entreprise'], 'Euro', 'EUR'],
        );
        $usd = $this->monnaie($s['entreprise'], 'USD');
        $eur = $this->monnaie($s['entreprise'], 'EUR');

        $renomme = $this->soumettre($s, ['id' => (string) $usd->getId(), 'nom' => 'Dollar US']);
        self::assertSame(200, $renomme['statut'], 'La retenue se renomme : ' . $renomme['texte']);

        $retire = $this->soumettre($s, ['id' => (string) $eur->getId(), 'fonction' => (string) Monnaie::FONCTION_AUCUNE]);
        self::assertSame(200, $retire['statut'], 'La surnuméraire se retire : ' . $retire['texte']);

        $refus = $this->soumettre($s, ['id' => (string) $usd->getId(), 'fonction' => (string) Monnaie::FONCTION_AUCUNE]);
        self::assertSame(422, $refus['statut'], 'La retenue, elle, reste verrouillée.');
    }

    /** Ket suit la même règle, dès le contrôle à blanc. */
    public function testKetNeCreePasUneSecondeMonnaieDeTravail(): void
    {
        $s = $this->semer();
        $em = $this->em();
        $scope = new AiScope($em->find(Entreprise::class, $s['entreprise']), $em->find(Invite::class, $s['invite']));

        $analyse = static::getContainer()->get(WorkspaceMutationService::class)->analyserOperation(
            new MutationOperation('create', 'Monnaie', null, [
                'nom' => 'Euro', 'code' => 'EUR', 'tauxusd' => '0.92', 'fonction' => Monnaie::FONCTION_SAISIE_ET_AFFICHAGE, 'locale' => false,
            ]),
            $scope,
        );
        self::assertFalse($analyse['ok']);
        self::assertStringContainsString('Le cabinet travaille déjà en USD', json_encode($analyse, JSON_UNESCAPED_UNICODE));
    }
}
