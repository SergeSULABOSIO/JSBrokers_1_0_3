<?php

namespace App\Tests\Workspace;

use App\Entity\Client;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Note;
use App\Entity\Paiement;
use App\Entity\PaiementPrime;
use App\Entity\Piste;
use App\Entity\RolesEnAdministration;
use App\Entity\RolesEnFinance;
use App\Entity\Tranche;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * LES BOUTONS D'ÉCRITURE D'UN DIALOGUE SUIVENT LES DROITS.
 *
 * Ils s'affichaient à tous : « Ajouter », « Modifier », « Supprimer » d'une collection,
 * comme « Facturer la commission » ou « Signaler un paiement de prime », et seul le
 * serveur refusait — au clic. Un gestionnaire de compte, qui doit tout VOIR sans rien
 * toucher à la comptabilité, se voyait offrir des gestes qui lui étaient interdits.
 *
 * Ce test tient la règle : chaque bouton suit le droit EXACT que vérifie son endpoint ;
 * une ligne qu'on ne peut pas modifier garde un bouton « Consulter », qui ouvre la même
 * fiche en lecture ; le propriétaire voit tout.
 */
class CollectionDroitsEcritureTest extends WebTestCase
{
    private const OWNER_EMAIL = 'phpunit-droits-ecriture-owner@test.local';
    private const GUEST_EMAIL = 'phpunit-droits-ecriture-guest@test.local';
    private const ENT = 'PHPUnit Droits Ecriture SARL';

    private KernelBrowser $client;

    protected function setUp(): void
    {
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
        $conn->executeStatement(
            'UPDATE utilisateur SET connected_to_id = NULL WHERE email IN (:e)',
            ['e' => [self::OWNER_EMAIL, self::GUEST_EMAIL]],
            ['e' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );
        foreach (['paiement_prime', 'paiement', 'note', 'tranche', 'cotation', 'piste', 'client', 'roles_en_finance', 'roles_en_administration', 'invite'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :nom",
                ['nom' => self::ENT],
            );
        }
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :nom', ['nom' => self::ENT]);
        $conn->executeStatement(
            'DELETE FROM utilisateur WHERE email IN (:e)',
            ['e' => [self::OWNER_EMAIL, self::GUEST_EMAIL]],
            ['e' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );
        $this->em()->clear();
    }

    /**
     * Un cabinet, son propriétaire, un invité aux droits Finance fournis, et une tranche
     * portant un paiement de prime ; une note portant un paiement.
     *
     * @param array<string, int[]> $droits accessTranche / accessNote / accessPaiement / accessDocument
     *
     * @return array{tranche: int, paiementPrime: int, note: int, paiement: int}
     */
    private function semer(array $droits): array
    {
        $em = $this->em();

        $owner = (new Utilisateur())->setEmail(self::OWNER_EMAIL)->setNom('Patron')->setVerified(true)->setPassword('x');
        $em->persist($owner);
        $ent = (new Entreprise())->setNom(self::ENT)->setLicence('LIC')->setAdresse('1 rue')
            ->setTelephone('+2430000')->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($owner);
        $em->persist($ent);
        $owner->setConnectedTo($ent);
        $proprietaire = (new Invite())->setNom('Le Patron')->setProprietaire(true);
        $proprietaire->setUtilisateur($owner)->setEntreprise($ent);
        $em->persist($proprietaire);

        $guestUser = (new Utilisateur())->setEmail(self::GUEST_EMAIL)->setNom('Gestionnaire')->setVerified(true)->setPassword('x');
        $guestUser->setConnectedTo($ent);
        $em->persist($guestUser);
        $guest = (new Invite())->setNom('Gestionnaire de compte')->setProprietaire(false);
        $guest->setUtilisateur($guestUser)->setEntreprise($ent);
        $roles = (new RolesEnFinance())->setNom('Finance du gestionnaire')
            ->setAccessTranche($droits['tranche'] ?? [])
            ->setAccessNote($droits['note'] ?? [])
            ->setAccessPaiement($droits['paiement'] ?? []);
        $roles->setEntreprise($ent);
        $guest->addRolesEnFinance($roles);
        $em->persist($roles);
        $admin = (new RolesEnAdministration())->setNom('Administration du gestionnaire')
            ->setAccessDocument($droits['document'] ?? []);
        $admin->setEntreprise($ent);
        $guest->addRolesEnAdministration($admin);
        $em->persist($admin);
        $em->persist($guest);

        $client = (new Client())->setNom('Client Droits')->setExonere(false)->setEntreprise($ent);
        $em->persist($client);
        $piste = (new Piste())->setNom('Piste Droits')->setTypeAvenant(0)->setDescriptionDuRisque('Risque')
            ->setExercice(2026)->setClient($client)->setEntreprise($ent)->setInvite($proprietaire);
        $em->persist($piste);
        $cotation = (new Cotation())->setNom('Cotation Droits')->setDuree(365);
        $cotation->setPiste($piste)->setEntreprise($ent);
        $em->persist($cotation);
        $tranche = (new Tranche())->setNom('Tranche Droits')->setPourcentage(100.0)
            ->setPayableAt(new \DateTimeImmutable('-30 days'))->setEcheanceAt(new \DateTimeImmutable('-5 days'));
        $tranche->setCotation($cotation)->setEntreprise($ent);
        $em->persist($tranche);
        $paiementPrime = (new PaiementPrime())->setPaidAt(new \DateTimeImmutable('-3 days'))->setMontant(100.0)->setReference('PP-1');
        $paiementPrime->setEntreprise($ent);
        $tranche->addPaiementsPrime($paiementPrime);
        $em->persist($paiementPrime);

        $note = (new Note())->setNom('Note Droits')->setReference('ND-1')->setType(Note::TYPE_NOTE_DE_DEBIT)
            ->setAddressedTo(Note::TO_ASSUREUR)->setValidated(true)->setSignature('sig');
        $note->setEntreprise($ent)->setInvite($proprietaire);
        $em->persist($note);
        $paiement = (new Paiement())->setMontant(50.0)->setPaidAt(new \DateTimeImmutable('-1 day'))->setReference('PAY-1');
        $paiement->setEntreprise($ent);
        $note->addPaiement($paiement);
        $em->persist($paiement);

        $em->flush();
        $ids = [
            'tranche' => $tranche->getId(), 'paiementPrime' => $paiementPrime->getId(),
            'note' => $note->getId(), 'paiement' => $paiement->getId(),
        ];
        $em->clear();

        return $ids;
    }

    private function connecter(string $email): void
    {
        $this->client->loginUser($this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => $email]));
    }

    private function ouvrir(string $url): Crawler
    {
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /** @return string[] les libellés des actions rapides de la fiche */
    private function actionsDeLaFiche(Crawler $crawler): array
    {
        $noeud = $crawler->filter('[data-actions-fiche]');
        if ($noeud->count() === 0) {
            return [];
        }

        return array_column(json_decode((string) $noeud->attr('data-actions-fiche'), true), 'label');
    }

    /** Le HTML de la liste d'une collection en dialogue (contrat JSON {html, itemCount}). */
    private function listeDeCollection(string $url): Crawler
    {
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        $json = json_decode((string) $this->client->getResponse()->getContent(), true);

        return new Crawler($json['html']);
    }

    /** Le bouton « Ajouter » du widget est-il offert ? */
    private function ajoutOffert(Crawler $crawler, string $champ): bool
    {
        $bloc = $crawler->filter(sprintf('[data-field-code="%s"] [data-collection-target="addButtonContainer"]', $champ));
        self::assertSame(1, $bloc->count(), "Le widget « $champ » doit être rendu.");

        return !str_contains((string) $bloc->attr('class'), 'd-none');
    }

    // ───────────────────────────── Actions rapides ─────────────────────────────

    /**
     * Le gestionnaire MODIFIE la tranche, mais n'a ni l'Écriture sur Tranche ni aucun
     * droit sur les notes : aucune des trois actions financières ne lui est proposée.
     */
    public function testLesActionsRapidesSuiventLeDroitDeLeurEndpoint(): void
    {
        $ids = $this->semer(['tranche' => [Invite::ACCESS_LECTURE, Invite::ACCESS_MODIFICATION]]);
        $this->connecter(self::GUEST_EMAIL);

        $actions = $this->actionsDeLaFiche($this->ouvrir('/admin/tranche/api/get-form/' . $ids['tranche']));

        self::assertNotContains('Signaler un paiement de prime', $actions, 'Exige l\'Écriture sur Tranche.');
        self::assertNotContains('Gérer le partage', $actions, 'Exige l\'Écriture sur Tranche.');
        self::assertNotContains('Facturer la commission', $actions, 'Exige l\'Écriture sur Note.');
    }

    /** Avec les droits exacts, les mêmes actions reviennent — le filtre ne retire que l'interdit. */
    public function testAvecLesDroitsExactsLesActionsSontProposees(): void
    {
        $ids = $this->semer([
            'tranche' => [Invite::ACCESS_LECTURE, Invite::ACCESS_ECRITURE, Invite::ACCESS_MODIFICATION],
            'note'    => [Invite::ACCESS_LECTURE, Invite::ACCESS_ECRITURE],
        ]);
        $this->connecter(self::GUEST_EMAIL);

        $actions = $this->actionsDeLaFiche($this->ouvrir('/admin/tranche/api/get-form/' . $ids['tranche']));

        self::assertContains('Signaler un paiement de prime', $actions);
        self::assertContains('Gérer le partage', $actions);
        self::assertContains('Facturer la commission', $actions);
    }

    // ───────────────────────────── Collections ─────────────────────────────

    /**
     * Sans Écriture : pas de « Ajouter ». Sans Suppression : pas de corbeille. Avec la
     * Modification : le crayon reste.
     */
    public function testLesBoutonsDeCollectionSuiventLesDroitsSurLEnfant(): void
    {
        $ids = $this->semer([
            'tranche'  => [Invite::ACCESS_LECTURE, Invite::ACCESS_MODIFICATION],
            'document' => [Invite::ACCESS_LECTURE],
        ]);
        $this->connecter(self::GUEST_EMAIL);

        // Le widget : des pièces jointes qu'on lit sans pouvoir en ajouter.
        $fiche = $this->ouvrir('/admin/tranche/api/get-form/' . $ids['tranche']);
        self::assertFalse($this->ajoutOffert($fiche, 'documents'), 'Ajouter une pièce exige l\'Écriture sur Document.');

        // La liste : les signalements de prime, gouvernés par la tranche (Modification
        // sans Suppression).
        $liste = $this->listeDeCollection(sprintf('/admin/tranche/api/%d/paiementsPrime/dialog', $ids['tranche']));
        self::assertSame(1, $liste->filter('[data-action="click->collection#editItem"]')->count(), 'La Modification garde le crayon.');
        self::assertSame(0, $liste->filter('[data-action="click->collection#deleteItem"]')->count(), 'Sans Suppression, pas de corbeille.');
        self::assertSame(1, $liste->filter('tbody tr td.text-end.pe-3')->count(), 'La cellule d\'actions reste : trois colonnes figées.');
    }

    /**
     * Sans Modification sur l'enfant, la ligne ne perd pas son bouton : elle reçoit
     * « Consulter ». La fiche s'ouvre alors en lecture, sans « Enregistrer » —
     * et l'ouverture ordinaire, elle, reste refusée.
     */
    public function testUneLigneEnLectureSeuleSeConsulte(): void
    {
        $ids = $this->semer([
            'note'     => [Invite::ACCESS_LECTURE, Invite::ACCESS_MODIFICATION],
            'paiement' => [Invite::ACCESS_LECTURE],
        ]);
        $this->connecter(self::GUEST_EMAIL);

        $liste = $this->listeDeCollection(sprintf('/admin/note/api/%d/paiements/dialog', $ids['note']));
        self::assertSame(1, $liste->filter('[data-action="click->collection#consulterItem"]')->count(), 'Une ligne lisible doit pouvoir s\'ouvrir.');
        self::assertSame(0, $liste->filter('[data-action="click->collection#editItem"]')->count());
        self::assertSame(0, $liste->filter('[data-action="click->collection#deleteItem"]')->count());
        self::assertStringNotContainsString('collection#addItem', $liste->html(), 'Aucun « Ajouter », pas même dans un état vide.');

        $consultee = $this->ouvrir(sprintf('/admin/paiement/api/get-form/%d?consultation=1', $ids['paiement']));
        self::assertSame(1, $consultee->filter('[data-consultation]')->count(), 'La fiche est marquée consultée (pied sans « Enregistrer »).');
        self::assertGreaterThan(0, $consultee->filter('form input[disabled], form select[disabled], form textarea[disabled]')->count(), 'Les champs sont inertes.');
        self::assertSame([], $this->actionsDeLaFiche($consultee), 'Une fiche consultée n\'offre aucune action.');

        // Sans le paramètre, l'ouverture exige toujours la Modification.
        $refusee = $this->ouvrir('/admin/paiement/api/get-form/' . $ids['paiement']);
        self::assertSame(0, $refusee->filter('form')->count(), 'L\'édition reste refusée sans droit de Modification.');
    }

    /** La consultation exige au moins la Lecture : sans elle, rien ne s'ouvre. */
    public function testLaConsultationExigeLaLecture(): void
    {
        $ids = $this->semer(['note' => [Invite::ACCESS_LECTURE]]);
        $this->connecter(self::GUEST_EMAIL);

        $crawler = $this->ouvrir(sprintf('/admin/paiement/api/get-form/%d?consultation=1', $ids['paiement']));
        self::assertSame(0, $crawler->filter('form')->count());
    }

    /** Le propriétaire, lui, garde tous ses gestes. */
    public function testLeProprietaireGardeTousLesBoutons(): void
    {
        $ids = $this->semer([]);
        $this->connecter(self::OWNER_EMAIL);

        $fiche = $this->ouvrir('/admin/tranche/api/get-form/' . $ids['tranche']);
        self::assertTrue($this->ajoutOffert($fiche, 'documents'));
        self::assertContains('Facturer la commission', $this->actionsDeLaFiche($fiche));

        $liste = $this->listeDeCollection(sprintf('/admin/tranche/api/%d/paiementsPrime/dialog', $ids['tranche']));
        self::assertSame(1, $liste->filter('[data-action="click->collection#editItem"]')->count());
        self::assertSame(1, $liste->filter('[data-action="click->collection#deleteItem"]')->count());
        self::assertSame(0, $liste->filter('[data-action="click->collection#consulterItem"]')->count());
    }
}
