<?php

namespace App\Tests\Ai;

use App\Ai\Fournisseur\PolitiqueDesFournisseurs;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * LE DIAGNOSTIC DES FOURNISSEURS — l'outil qu'on lance quand Ket se tait.
 *
 * POURQUOI IL EST TESTÉ. Un diagnostic n'est utile que le jour où quelque chose
 * ne va pas : c'est précisément le jour où l'on n'a ni le temps ni l'envie de
 * découvrir qu'il ne démarre plus. Il dépend de quatre services (l'état, la
 * politique, la voix, les oreilles) dont les constructeurs bougent au fil des
 * lots — une seule signature changée et la commande tombe, en silence, jusqu'à
 * l'incident suivant.
 *
 * CE QUI EST VÉRIFIÉ ICI : qu'elle se construit, qu'elle rend les cinq familles,
 * et qu'elle ne sort JAMAIS du réseau sans qu'on le lui demande. Ce dernier point
 * est le plus important : une commande de diagnostic qu'on hésite à lancer parce
 * qu'elle coûte des jetons n'est pas lancée.
 */
class KetDiagnosticCommandTest extends KernelTestCase
{
    private function testeur(): CommandTester
    {
        $application = new Application(self::bootKernel());

        return new CommandTester($application->find('app:ket:diagnostic'));
    }

    /**
     * L'AUDIT PAR DÉFAUT NE COÛTE RIEN. Aucun `--reel` : aucun appel réseau ne
     * doit partir. En environnement de test le moteur est simulé et les clés sont
     * absentes — si la commande appelait quoi que ce soit, elle échouerait ou
     * traînerait, et l'on s'en apercevrait ici.
     */
    public function testLAuditSeLanceEtNeCoutRien(): void
    {
        $testeur = $this->testeur();
        $testeur->execute([]);

        self::assertSame(Command::SUCCESS, $testeur->getStatusCode());
        self::assertStringContainsString('Audit de configuration seulement', $testeur->getDisplay());
    }

    /** Les cinq familles sont rendues : en oublier une, c'est diagnostiquer à moitié. */
    public function testLesCinqFamillesSontRendues(): void
    {
        $testeur = $this->testeur();
        $testeur->execute([]);
        $sortie = $testeur->getDisplay();

        foreach (PolitiqueDesFournisseurs::FAMILLES as $famille) {
            self::assertStringContainsString(
                mb_strtoupper($famille),
                $sortie,
                sprintf('La famille « %s » manque au diagnostic.', $famille),
            );
        }
    }

    /**
     * LE MOTEUR DE TEXTE EST LE SEUL VITAL, et la sortie doit le refléter : en
     * test il répond (moteur simulé), donc la commande réussit. Confondre « la
     * voix payante est à sec » avec « Ket est en panne » ferait sonner l'alarme
     * pour le fonctionnement normal d'un palier gratuit.
     */
    public function testLeCodeDeSortieNeDependQueDuMoteur(): void
    {
        $testeur = $this->testeur();
        $testeur->execute([]);

        self::assertSame(Command::SUCCESS, $testeur->getStatusCode());
        self::assertStringContainsString('app:assistant:smoke', $testeur->getDisplay(), 'Le test réel du moteur doit être rappelé.');
    }
}
