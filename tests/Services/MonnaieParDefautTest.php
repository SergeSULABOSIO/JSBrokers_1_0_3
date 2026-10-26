<?php

namespace App\Tests\Services;

use App\Services\ServiceMonnaies;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * SANS MONNAIE CONFIGURÉE, UN CABINET COMPTE EN USD — partout, et par UNE source (décision de
 * Serge, 2026-10-26). USD est le défaut d'un cabinet : sa création le lui pose, Ket l'annonce.
 * Seize écrans le réécrivaient chacun (« ?? 'USD' ») pendant que les tranches et les tableaux
 * de Ket montraient le nombre seul : deux règles pour un même cas.
 */
class MonnaieParDefautTest extends TestCase
{
    public function testSansMonnaieConfigureeLeCodeEstLeDollar(): void
    {
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn(null);

        $service = new ServiceMonnaies($security, $this->createMock(EntityManagerInterface::class));

        $this->assertSame('USD', ServiceMonnaies::MONNAIE_PAR_DEFAUT);
        $this->assertSame(ServiceMonnaies::MONNAIE_PAR_DEFAUT, $service->getCodeMonnaieAffichage());
    }

    /** Le défaut n'est écrit qu'une fois : aucun appelant ne le réécrit à sa façon. */
    public function testAucunAppelantNeReecritLeDefaut(): void
    {
        $fautifs = [];
        $racine = __DIR__ . '/../../src';
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($racine, \FilesystemIterator::SKIP_DOTS)) as $fichier) {
            if ($fichier->getExtension() !== 'php' || $fichier->getFilename() === 'ServiceMonnaies.php') {
                continue;
            }
            $code = (string) file_get_contents($fichier->getPathname());
            if (preg_match('/(\?\?|\?:)\s*[\'"]USD[\'"]|===?\s*\'\'\)\s*\?\s*[\'"]USD[\'"]/', $code) === 1) {
                $fautifs[] = $fichier->getFilename();
            }
        }

        $this->assertSame([], $fautifs, 'Le défaut se lit dans ServiceMonnaies::MONNAIE_PAR_DEFAUT.');
    }
}
