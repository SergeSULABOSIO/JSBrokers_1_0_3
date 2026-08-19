<?php

namespace App\Tests\Workspace;

use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Taxe;
use App\Entity\Utilisateur;
use App\Token\TokenAccountService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * LA VALIDATION SANS ÉCRITURE (`dry_run`).
 *
 * Une collection dont le parent n'existe pas encore garde ses éléments en mémoire du
 * navigateur jusqu'à l'enregistrement de l'ancêtre. Elle doit pourtant dire TOUT DE SUITE
 * si la saisie est recevable — sinon l'utilisateur découvrirait ses erreurs bien plus tard,
 * sur un formulaire qu'il a déjà fermé.
 *
 * D'où un arrêt une ligne avant `commitWrite()`, sur le chemin de soumission EXISTANT :
 * mêmes contrôles Symfony, même `ChampsObligatoiresInspector`, même périmètre workspace.
 * Ce test existe pour prouver les trois choses qu'on ne voit pas à l'œil nu :
 *   1. le verdict est le MÊME qu'une vraie soumission (200 quand ça passe, 422 quand non) ;
 *   2. RIEN n'est écrit en base ;
 *   3. RIEN n'est facturé — car ouvrir un dialogue ne doit jamais coûter des tokens, et un
 *      solde épuisé ne doit plus empêcher de SAISIR, seulement d'enregistrer.
 */
class ValidationSansEcritureTest extends WebTestCase
{
    private const ENT = 'PHPUnit-DryRun';
    private const OWNER = 'phpunit-dryrun-owner@test.local';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        parent::tearDown();
    }

    private function cleanUp(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email = :e', ['e' => self::OWNER]);
        foreach (['cotation', 'taxe', 'invite'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :n",
                ['n' => self::ENT],
            );
        }
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :e', ['e' => self::OWNER]);
        $this->em->clear();
    }

    /** @return array{0:Utilisateur,1:Entreprise,2:Invite} */
    private function seedContexte(): array
    {
        $owner = (new Utilisateur())->setEmail(self::OWNER)->setNom('PHPUnit')->setVerified(true);
        $owner->setPassword('x');
        $this->em->persist($owner);

        $ent = (new Entreprise())
            ->setNom(self::ENT)->setLicence('LIC')->setAdresse('1 rue')->setTelephone('+243000')
            ->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($owner);
        $this->em->persist($ent);

        $inv = (new Invite())->setNom('Owner')->setUtilisateur($owner)->setEntreprise($ent)->setProprietaire(true);
        $this->em->persist($inv);

        $owner->setConnectedTo($ent);
        $this->em->flush();

        return [$owner, $ent, $inv];
    }

    /** Charge utile complète et valide pour une taxe — le cas « ça passerait ». */
    private function taxeValide(Entreprise $ent, Invite $inv, string $code): array
    {
        return [
            'idEntreprise' => $ent->getId(),
            'idInvite' => $inv->getId(),
            'code' => $code,
            'description' => 'Taxe du test de validation seule',
            'tauxIARD' => '16',
            'tauxVIE' => '16',
            'redevable' => (string) Taxe::REDEVABLE_COURTIER,
        ];
    }

    public function testUneSaisieValideEstAccepteeMaisPasEcrite(): void
    {
        [$owner, $ent, $inv] = $this->seedContexte();
        $this->client->loginUser($owner);

        $this->client->request('POST', '/admin/taxe/api/submit', $this->taxeValide($ent, $inv, 'DRY-OK') + ['dry_run' => '1']);

        $this->assertResponseIsSuccessful('Une saisie recevable doit être acceptée telle quelle.');
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertTrue($payload['valide'] ?? false, 'La réponse annonce explicitement que la saisie tiendrait.');

        // LE POINT CENTRAL : le verdict est rendu, mais la base n'a pas bougé.
        $this->em->clear();
        $this->assertNull(
            $this->em->getRepository(Taxe::class)->findOneBy(['code' => 'DRY-OK']),
            'dry_run ne doit RIEN écrire : c\'est toute sa raison d\'être.',
        );
    }

    public function testLeMemeChampManquantEstSignaleQuAvecUneVraieSoumission(): void
    {
        [$owner, $ent, $inv] = $this->seedContexte();
        $this->client->loginUser($owner);

        // « redevable » est obligatoire métier et ne porte AUCUNE contrainte #[Assert] :
        // seul ChampsObligatoiresInspector le voit. S'il n'était pas traversé, on
        // obtiendrait un 200 trompeur — l'utilisateur ne découvrirait le problème qu'au
        // rejeu, une fois son formulaire fermé.
        $charge = $this->taxeValide($ent, $inv, 'DRY-KO');
        unset($charge['redevable']);

        $this->client->request('POST', '/admin/taxe/api/submit', $charge + ['dry_run' => '1']);

        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('redevable', $payload['errors'] ?? [], 'Le champ fautif est nommé, comme en soumission réelle.');
    }

    public function testAucunTokenNEstDebite(): void
    {
        [$owner, $ent, $inv] = $this->seedContexte();
        $this->client->loginUser($owner);

        $comptes = static::getContainer()->get(TokenAccountService::class);
        $avant = $comptes->getBalance($owner);

        $this->client->request('POST', '/admin/taxe/api/submit', $this->taxeValide($ent, $inv, 'DRY-TOK') + ['dry_run' => '1']);
        $this->assertResponseIsSuccessful();

        $this->em->clear();
        $apres = $comptes->getBalance($this->em->getRepository(Utilisateur::class)->findOneBy(['email' => self::OWNER]));

        // Sans cette garantie, ouvrir un dialogue de création deviendrait payant, et un
        // solde épuisé empêcherait purement et simplement de saisir.
        $this->assertSame(
            $avant['used'] ?? null,
            $apres['used'] ?? null,
            'Une validation qui n\'écrit rien ne doit rien facturer.',
        );
    }

    public function testLeChampParentAbsentNEstPasReclame(): void
    {
        [$owner, $ent, $inv] = $this->seedContexte();
        $this->client->loginUser($owner);

        // En différé, l'enfant est saisi AVANT que son parent existe : le formulaire est
        // demandé sans `parent_id`, donc le champ parent n'est pas injecté dans le layout,
        // donc il ne figure pas parmi les champs pilotables et ne peut pas être réclamé.
        // C'est le seul endroit où le différé pourrait produire un faux « champ
        // obligatoire manquant » — d'où cette preuve plutôt qu'une supposition.
        $this->client->request('POST', '/admin/cotation/api/submit', [
            'idEntreprise' => $ent->getId(),
            'idInvite' => $inv->getId(),
            'nom' => 'Cotation sans piste',
            'duree' => '12',
            'dry_run' => '1',
        ]);

        $this->assertResponseIsSuccessful('Une cotation saisie avant sa piste doit être jugée recevable.');
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertTrue($payload['valide'] ?? false);

        $this->em->clear();
        $this->assertNull(
            $this->em->getRepository(Cotation::class)->findOneBy(['nom' => 'Cotation sans piste']),
            'Ici non plus, rien ne doit atteindre la base.',
        );
    }
}
