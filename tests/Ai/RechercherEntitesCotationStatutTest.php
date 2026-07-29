<?php

namespace App\Tests\Ai;

use App\Ai\Scope\AiScope;
use App\Ai\Tool\AiToolResult;
use App\Ai\Tool\RechercherEntitesTool;
use App\Entity\Avenant;
use App\Entity\Client;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Piste;
use App\Entity\Utilisateur;
use App\Services\Search\CotationSouscriptionScope;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * rechercher_entites, statut de souscription par item (Cotation) : la lacune qui faisait
 * qualifier à tort de « proposition en attente » une cotation DÉJÀ transformée en police
 * (au moins un avenant). Quand un client a plusieurs cotations dont l'une est bound, chaque
 * item doit porter son statut (« Souscrite » / « En attente ») pour que l'assistant distingue
 * la police concrétisée des simples propositions. Même source de vérité que le chip de la
 * rubrique (CotationSouscriptionScope) et l'indicateur calculé (isCotationBound).
 */
class RechercherEntitesCotationStatutTest extends KernelTestCase
{
    private const OWNER_EMAIL = 'phpunit-cotstatut-owner@test.local';
    private const ENTREPRISE_NOM = 'PHPUnit CotStatut SARL';

    protected function setUp(): void
    {
        static::bootKernel();
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

    private function tool(): RechercherEntitesTool
    {
        return static::getContainer()->get(RechercherEntitesTool::class);
    }

    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();
        foreach (['avenant', 'cotation', 'piste', 'client', 'portefeuille', 'invite'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :nom",
                ['nom' => self::ENTREPRISE_NOM],
            );
        }
        $conn->executeStatement("DELETE FROM entreprise WHERE nom = :nom", ['nom' => self::ENTREPRISE_NOM]);
        $conn->executeStatement("DELETE FROM utilisateur WHERE email = :email", ['email' => self::OWNER_EMAIL]);
    }

    /**
     * Un client avec trois cotations sur un même risque, dont UNE seule est bound (avenant
     * déjà en place) — reproduit le cas « Orange RC Auto » : Mayfair souscrite, deux autres en
     * attente. Renvoie l'entreprise, l'invité propriétaire, le client et le nom de la cotation
     * souscrite.
     *
     * @return array{entreprise: Entreprise, invite: Invite, client: Client, nomBound: string}
     */
    private function seed(): array
    {
        $em = $this->em();

        $owner = new Utilisateur();
        $owner->setEmail(self::OWNER_EMAIL)->setNom('CotStatut Owner')->setVerified(true)->setPassword('x');
        $em->persist($owner);

        $entreprise = (new Entreprise())->setNom(self::ENTREPRISE_NOM)->setLicence('LIC')->setAdresse('1 rue')
            ->setTelephone('+2430000')->setRccm('RCCM')->setIdnat('IDNAT')->setNumimpot('IMP');
        $entreprise->setUtilisateur($owner);
        $em->persist($entreprise);

        $invite = (new Invite())->setNom('Proprio')->setProprietaire(true);
        $invite->setUtilisateur($owner)->setEntreprise($entreprise);
        $em->persist($invite);

        $client = (new Client())->setNom('Orange RDC SA')->setExonere(false);
        $client->setEntreprise($entreprise);
        $em->persist($client);

        $piste = (new Piste())->setNom('Piste RC Auto')->setTypeAvenant(0)
            ->setDescriptionDuRisque('RC Automobile')->setExercice(2026)
            ->setClient($client)->setEntreprise($entreprise)->setInvite($invite);
        $em->persist($piste);

        // Mayfair : bound (avenant déjà en place). SUNU et SFA CONGO : propositions en attente.
        foreach (['Mayfair' => true, 'SUNU' => false, 'SFA CONGO' => false] as $nom => $bound) {
            $cotation = (new Cotation())->setNom('Proposition RC Auto - ' . $nom)->setDuree(365);
            $cotation->setEntreprise($entreprise);
            // addCotation synchronise les DEUX côtés : sans em->clear(), la piste gardée en
            // mémoire doit connaître ses cotations sœurs (détection « concurrente caduque »).
            $piste->addCotation($cotation);
            $em->persist($cotation);

            if ($bound) {
                $avenant = (new Avenant())->setReferencePolice('POL-' . $nom)->setNumero('0')
                    ->setDescription('Police ' . $nom)
                    ->setStartingAt(new \DateTimeImmutable('-30 days'))
                    ->setEndingAt(new \DateTimeImmutable('+335 days'));
                $avenant->setEntreprise($entreprise)->setInvite($invite);
                // addAvenant synchronise les DEUX côtés (collection + owning) : sans em->clear(),
                // la cotation gardée en mémoire doit connaître son avenant pour être vue bound.
                $cotation->addAvenant($avenant);
                $em->persist($avenant);
            }
        }

        $em->flush();

        return [
            'entreprise' => $entreprise,
            'invite'     => $invite,
            'client'     => $client,
            'nomBound'   => 'Proposition RC Auto - Mayfair',
        ];
    }

    public function testChaqueCotationPorteSonStatutDeSouscription(): void
    {
        ['entreprise' => $e, 'invite' => $invite, 'client' => $client, 'nomBound' => $nomBound] = $this->seed();
        $scope = new AiScope($e, $invite);

        $result = $this->tool()->execute([
            'entite'    => 'Cotation',
            'lieA'      => ['entite' => 'Client', 'id' => $client->getId()],
            'perimetre' => \App\Services\Search\PortefeuilleScope::PERIMETRE_ENTREPRISE,
        ], $scope);

        $this->assertSame(AiToolResult::STATUS_OK, $result->status);
        $this->assertSame(3, $result->data['totalItems'], 'Les 3 cotations du client remontent.');

        $statutParLibelle = [];
        $itemParLibelle = [];
        foreach ($result->data['items'] as $item) {
            $this->assertArrayHasKey('statut', $item, 'Chaque cotation doit porter son statut de souscription.');
            $statutParLibelle[$item['libelle']] = $item['statut'];
            $itemParLibelle[$item['libelle']] = $item;
        }

        // La cotation souscrite porte la PREUVE concrète de la police : référence + période.
        $this->assertSame('POL-Mayfair', $itemParLibelle[$nomBound]['referencePolice'] ?? null);
        $this->assertArrayHasKey('periodeCouverture', $itemParLibelle[$nomBound]);
        // Une cotation en attente n'a ni police ni période (rien à inventer). Comme une AUTRE
        // proposition de la MÊME piste (Mayfair) est souscrite, les concurrentes sont « sans
        // suite » : marché attribué, plus d'opportunité à relancer.
        foreach (['Proposition RC Auto - SUNU', 'Proposition RC Auto - SFA CONGO'] as $nomEnAttente) {
            $this->assertArrayNotHasKey('referencePolice', $itemParLibelle[$nomEnAttente]);
            $this->assertArrayHasKey('suivi', $itemParLibelle[$nomEnAttente], 'Une proposition concurrente perdante doit être marquée sans suite.');
            $this->assertStringContainsString('Sans suite', $itemParLibelle[$nomEnAttente]['suivi']);
        }
        // La cotation souscrite (le gagnant) n'est évidemment PAS « sans suite ».
        $this->assertArrayNotHasKey('suivi', $itemParLibelle[$nomBound]);

        // La cotation Mayfair est BOUND (avenant) → « Souscrite », jamais « En attente ».
        $this->assertSame(
            CotationSouscriptionScope::STATUT_LABEL_SOUSCRITE,
            $statutParLibelle[$nomBound] ?? null,
            'La proposition liée à un avenant est marquée Souscrite (police concrétisée).',
        );

        // Exactement une souscrite, deux en attente : Ket ne peut plus toutes les croire en attente.
        $souscrites = array_keys($statutParLibelle, CotationSouscriptionScope::STATUT_LABEL_SOUSCRITE, true);
        $enAttente = array_keys($statutParLibelle, CotationSouscriptionScope::STATUT_LABEL_EN_ATTENTE, true);
        $this->assertCount(1, $souscrites);
        $this->assertCount(2, $enAttente);
    }

    public function testLAvenantDeLaCotationBoundEstRetrouvableParClient(): void
    {
        ['entreprise' => $e, 'invite' => $invite, 'client' => $client] = $this->seed();
        $scope = new AiScope($e, $invite);

        // La police (avenant) de la cotation souscrite doit être retrouvable en partant du CLIENT
        // (chemin Avenant → cotation → piste → client). Si cette liste est vide, Ket conclut à tort
        // « aucun avenant actif » alors que la couverture existe.
        $result = $this->tool()->execute([
            'entite'    => 'Avenant',
            'lieA'      => ['entite' => 'Client', 'id' => $client->getId()],
            'perimetre' => \App\Services\Search\PortefeuilleScope::PERIMETRE_ENTREPRISE,
        ], $scope);

        $this->assertSame(AiToolResult::STATUS_OK, $result->status);
        $this->assertSame(1, $result->data['totalItems'], 'La police de la cotation Mayfair doit remonter côté client.');
    }
}
