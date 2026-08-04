<?php

namespace App\Tests\Workspace;

use App\Entity\Avenant;
use App\Entity\Client;
use App\Entity\Contact;
use App\Entity\Cotation;
use App\Entity\Piste;
use App\Entity\Tranche;
use App\Service\Workspace\CascadeImpactAnalyzer;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * L'analyseur d'impact de suppression lit les VRAIES métadonnées Doctrine
 * (ORM 3) : il détecte les enfants supprimés en cascade (orphanRemoval) et ne
 * produit aucun blocage sur une entité non encore persistée. Aucune connexion
 * BDD nécessaire (métadonnées + valeurs d'objet uniquement).
 */
class CascadeImpactAnalyzerTest extends KernelTestCase
{
    private function analyzer(): CascadeImpactAnalyzer
    {
        self::bootKernel();

        return self::getContainer()->get(CascadeImpactAnalyzer::class);
    }

    public function testDetecteLesEnfantsSupprimesEnCascade(): void
    {
        $client = (new Client())->setNom('Client test');
        $client->addContact(new Contact());
        $client->addContact(new Contact());

        $impact = $this->analyzer()->analyserSuppression($client);

        // Client.contacts est orphanRemoval : 2 contacts seraient supprimés.
        $contacts = array_filter($impact->enfants, static fn ($e) => $e['entite'] === 'Contact');
        $this->assertCount(1, $contacts);
        $this->assertSame(2, array_values($contacts)[0]['count']);
        $this->assertNotEmpty($impact->descriptions());
        $this->assertStringContainsString('2 Contact', $impact->descriptions()[0]);
    }

    public function testAucunBlocageSurEntiteNonPersistee(): void
    {
        // Sans identifiant, aucune requête de références entrantes n'est lancée.
        $impact = $this->analyzer()->analyserSuppression((new Client())->setNom('Nouveau'));

        $this->assertFalse($impact->estBloque());
    }

    /**
     * LA PORTÉE ANNONCÉE DOIT ÊTRE LA PORTÉE RÉELLE. S'arrêter à une profondeur
     * mentait par omission : supprimer une opportunité annonçait « 1 Cotation liée »
     * alors que disparaissaient aussi son échéancier et les paiements déjà déclarés.
     * On ne peut pas demander de valider ce qu'on cache.
     */
    public function testLaCascadeEstSuivieEnProfondeur(): void
    {
        $piste = (new Piste())->setNom('Opportunité');
        $cotation = (new Cotation())->setNom('Proposition');
        $tranche = (new Tranche())->setNom('1re échéance');
        $cotation->addTranche($tranche);
        $piste->addCotation($cotation);

        $impact = $this->analyzer()->analyserSuppression($piste);
        $parEntite = array_column($impact->enfants, 'count', 'entite');

        $this->assertSame(1, $parEntite['Cotation'] ?? 0, 'Profondeur 1.');
        $this->assertSame(1, $parEntite['Tranche'] ?? 0, 'Profondeur 2 — invisible auparavant.');
    }

    /**
     * LE LIEN QUI NE DOIT JAMAIS ÊTRE REMONTÉ. `Piste::avenantDeBase` est en
     * cascade:['remove'], mais le moteur le COUPE avant de supprimer : la police de
     * base survit. L'annoncer comme détruite serait un mensonge — aussi grave que
     * taire une vraie destruction.
     */
    public function testLaPoliceDeBaseNEstPasAnnonceeCommeDetruite(): void
    {
        $police = (new Avenant())->setReferencePolice('POL-1')->setDescription('Police');
        $derivee = (new Piste())->setNom('Renouvellement');
        $derivee->setAvenantDeBase($police);

        $impact = $this->analyzer()->analyserSuppression($derivee);
        $parEntite = array_column($impact->enfants, 'count', 'entite');

        $this->assertArrayNotHasKey('Avenant', $parEntite, 'La police de base est protégée, pas détruite.');
    }
}
