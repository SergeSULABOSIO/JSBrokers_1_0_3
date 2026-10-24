<?php

namespace App\Tests\Workspace;

use App\Entity\Utilisateur;
use App\Tests\Security\SemisDeDeuxCabinetsTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * CHAQUE INFOBULLE DU TABLEAU DE BORD SE REND, AVEC DES DONNÉES (chantier 3).
 *
 * Les neuf infobulles sont déclarées par le serveur en JSON. Le lint Twig ne prouve que la
 * syntaxe : ce test rend chaque bloc sur un cabinet COMPLET (dépense, encaissement, sinistre,
 * bordereau, note, piste, tâche et son retour…) et décode chaque `data-infobulle` — JSON
 * valide, jamais vide, et les sections attendues présentes.
 */
class InfobulleDuTableauDeBordRenduTest extends WebTestCase
{
    use SemisDeDeuxCabinetsTrait;

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

    public function testChaqueInfobulleSeRendAvecDesDonnees(): void
    {
        $seed = $this->semerLesDeuxCabinets();
        $em = $this->em();
        $entreprise = $em->find(\App\Entity\Entreprise::class, $seed['a']['entreprise']);
        $invite = $em->getRepository(\App\Entity\Invite::class)->findOneBy(['entreprise' => $entreprise]);
        // Le bloc Sinistres lit les notifications d'un invité du cabinet ; le semis n'en rattache pas.
        foreach ($em->getRepository(\App\Entity\NotificationSinistre::class)->findBy(['entreprise' => $entreprise]) as $notification) {
            $notification->setInvite($invite)->setNotifiedAt(new \DateTimeImmutable('-3 days'));
        }
        // Une piste SANS police : le bloc Pistes ne montre que les pistes en cours.
        $client = $em->getRepository(\App\Entity\Client::class)->findOneBy(['entreprise' => $entreprise]);
        $piste = (new \App\Entity\Piste())->setNom('Piste en cours A')->setClient($client)->setTypeAvenant(\App\Entity\Piste::AVENANT_SOUSCRIPTION)
            ->setDescriptionDuRisque('r')->setExercice((int) date('Y'));
        $piste->setEntreprise($entreprise);
        $piste->setInvite($invite);
        $em->persist($piste);
        $em->flush();
        $em->clear();
        $this->client->loginUser($this->em()->getRepository(Utilisateur::class)->find($seed['a']['owner']));

        $titres = [];
        $nombre = 0;
        foreach (['kpis', 'compta', 'renewals', 'encaissements', 'depenses', 'sinistres', 'tasks', 'users', 'pistes', 'bordereaux', 'notes'] as $bloc) {
            $crawler = $this->client->request('GET', sprintf('/admin/entreprise_dashbord/block/%s/%d', $bloc, $seed['a']['entreprise']));
            self::assertResponseIsSuccessful("Le bloc « $bloc » se rend.");
            foreach ($crawler->filter('[data-infobulle]') as $noeud) {
                $contenu = json_decode($noeud->getAttribute('data-infobulle'), true, 512, \JSON_THROW_ON_ERROR);
                self::assertNotEmpty($contenu, "Une infobulle vide dans le bloc « $bloc ».");
                ++$nombre;
                foreach (\is_array($contenu) ? $contenu : [] as $section) {
                    if (!empty($section['titre'])) {
                        $titres[$section['titre']] = true;
                    }
                }
            }
        }

        self::assertGreaterThan(10, $nombre);
        foreach (['Dépense', 'Montants', 'Suivi', 'Encaissement', 'Note liée', 'Sinistre', 'Bordereau', 'Période', 'Note', 'Finances', 'Piste', 'Potentiel', 'Calcul'] as $attendu) {
            self::assertArrayHasKey($attendu, $titres, "La section « $attendu » manque : titres rendus = " . implode(', ', array_keys($titres)));
        }
    }
}
