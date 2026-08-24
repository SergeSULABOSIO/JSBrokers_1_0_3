<?php

namespace App\Tests\Ai;

use App\Ai\Scope\AiScope;
use App\Ai\Tool\AiToolResult;
use App\Ai\Tool\RetrocommissionsTool;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\RolesEnFinance;
use App\Entity\Utilisateur;
use App\Service\Workspace\WorkspaceAccessResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * KET ET L'ÉCRAN DISENT LA MÊME CHOSE DES RÉTROS AGENTS.
 *
 * Avant le droit dédié, deux règles coexistaient et se contredisaient :
 *
 *  — l'ÉCRAN montrait la rubrique à qui pouvait lire les AVENANTS, et lui montrait tout ;
 *  — l'ASSISTANT s'en tenait au « gestionnaire d'invités », pour l'énumération comme pour
 *    un agent nommé (`peutConsulter`).
 *
 * L'assistant était donc plus STRICT que l'écran, et c'est ce décalage qui posait problème :
 * un collaborateur voyait dans la liste ce que le chat lui refusait, sur la même donnée et
 * au même instant. La règle est désormais unique — le droit de lecture de
 * `ReversementRetroAgent`, celui-là même qui gouverne la rubrique.
 *
 * Trois propriétés :
 *
 *  1. AVEC le droit, l'assistant atteint tous les bénéficiaires du cabinet, comme la liste.
 *  2. SANS le droit, un collègue est refusé — et refusé comme HORS PÉRIMÈTRE, jamais comme
 *     introuvable : prétendre qu'il n'existe pas enverrait chacun corriger une orthographe
 *     correcte, et chercher un collègue qu'il côtoie tous les jours.
 *  3. SANS le droit, l'invité s'atteint tout de même LUI-MÊME : sa propre rémunération n'a
 *     jamais demandé de permission, et la lui retirer serait une régression.
 */
class RetrocommissionsPariteDroitTest extends KernelTestCase
{
    private const OWNER_EMAIL = 'phpunit-parite-retro@test.local';
    private const ENT = 'PHPUnit Parité Retro SARL';

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

    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email = :e', ['e' => self::OWNER_EMAIL]);
        foreach (['roles_en_finance', 'roles_en_production', 'invite'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :nom",
                ['nom' => self::ENT],
            );
        }
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :nom', ['nom' => self::ENT]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :email', ['email' => self::OWNER_EMAIL]);
        $this->em()->clear();
    }

    /**
     * Un cabinet, son propriétaire, et DEUX collaborateurs ordinaires : « Alice » (celle
     * qui interroge) et « Bruno » (le collègue dont la rémunération doit rester couverte).
     *
     * @return array{entreprise: Entreprise, alice: Invite, bruno: Invite}
     */
    private function semer(): array
    {
        $em = $this->em();

        $owner = (new Utilisateur())->setEmail(self::OWNER_EMAIL)->setNom('Parité')->setVerified(true)->setPassword('x');
        $em->persist($owner);

        $ent = (new Entreprise())->setNom(self::ENT)->setLicence('LIC')->setAdresse('1 rue')
            ->setTelephone('+2430000')->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($owner);
        $em->persist($ent);
        $owner->setConnectedTo($ent);

        $proprietaire = (new Invite())->setNom('Le Patron')->setProprietaire(true);
        $proprietaire->setUtilisateur($owner)->setEntreprise($ent);
        $em->persist($proprietaire);

        $alice = (new Invite())->setNom('Alice Mukendi')->setProprietaire(false);
        $alice->setEntreprise($ent);
        $em->persist($alice);

        $bruno = (new Invite())->setNom('Bruno Kalala')->setProprietaire(false);
        $bruno->setEntreprise($ent);
        $em->persist($bruno);

        $em->flush();

        return ['entreprise' => $ent, 'alice' => $alice, 'bruno' => $bruno, 'proprietaire' => $proprietaire];
    }

    /** Accorde à un invité le droit de LECTURE sur les rétros agents. */
    private function accorderLeDroit(Invite $invite, Entreprise $ent): void
    {
        $roles = (new RolesEnFinance())->setNom('Rôles de ' . $invite->getNom());
        $roles->setAccessReversementRetroAgent([Invite::ACCESS_LECTURE]);
        $roles->setInvite($invite);
        $roles->setEntreprise($ent);
        $this->em()->persist($roles);
        $this->em()->flush();
        $this->em()->refresh($invite);
    }

    private function outil(): RetrocommissionsTool
    {
        return static::getContainer()->get(RetrocommissionsTool::class);
    }

    /**
     * CE BÉNÉFICIAIRE EST-IL CONSULTABLE PAR CE DEMANDEUR ?
     *
     * C'est la propriété que la règle gouverne, et la seule qui se lise sans planter le
     * décor complet d'une rétrocommission. Interroger les MONTANTS aurait demandé
     * conditions de partage, avenants et revenus — et surtout, un bénéficiaire sans rétro
     * est écarté du tableau (`nbLignes === 0`), si bien qu'une absence n'y prouverait rien.
     *
     * On lit le STATUT, pas une tournure de phrase : c'est lui que le moteur consulte pour
     * ne rien restituer au modèle.
     */
    private function estConsultable(Invite $demandeur, Entreprise $ent, string $terme): bool
    {
        return $this->statutPour($demandeur, $ent, $terme) === AiToolResult::STATUS_OK;
    }

    private function statutPour(Invite $demandeur, Entreprise $ent, string $terme): string
    {
        return $this->outil()->execute(
            ['beneficiaire' => $terme],
            new AiScope($ent, $demandeur),
        )->status;
    }

    // ===================== 1. Le droit ouvre tout, comme l'écran =====================

    /** Le droit est un INTERRUPTEUR : Alice, une fois autorisée, atteint aussi Bruno. */
    public function testAvecLeDroitUnCollegueEstAtteignable(): void
    {
        $s = $this->semer();
        $this->accorderLeDroit($s['alice'], $s['entreprise']);

        self::assertTrue(
            static::getContainer()->get(WorkspaceAccessResolver::class)->canRead($s['alice'], 'ReversementRetroAgent'),
            'Le droit accordé doit être effectif — sinon ce test ne prouve rien.',
        );
        self::assertTrue($this->estConsultable($s['alice'], $s['entreprise'], 'Bruno Kalala'));
    }

    /** Le propriétaire n'a besoin d'aucun rôle : le bypass du resolver suffit. */
    public function testLeProprietaireAtteintToutSansAucunRole(): void
    {
        $s = $this->semer();

        self::assertTrue($this->estConsultable($s['proprietaire'], $s['entreprise'], 'Bruno Kalala'));
        self::assertTrue($this->estConsultable($s['proprietaire'], $s['entreprise'], 'Alice Mukendi'));
    }

    // ===================== 2. Sans le droit : un collègue reste couvert =====================

    /**
     * SANS LE DROIT, LA RÉMUNÉRATION D'UN COLLÈGUE EST REFUSÉE — et refusée pour la BONNE
     * raison.
     *
     * Le statut compte autant que le refus : `HORS_PERIMETRE` dit « cette personne existe,
     * mais elle ne vous regarde pas », quand `INTROUVABLE` prétendrait qu'elle n'existe
     * pas — envoyant le modèle corriger une orthographe correcte et l'utilisateur douter
     * d'un nom qu'il connaît.
     */
    public function testSansLeDroitUnCollegueEstRefusePourHorsPerimetre(): void
    {
        $s = $this->semer();

        self::assertSame(
            AiToolResult::STATUS_HORS_PERIMETRE,
            $this->statutPour($s['alice'], $s['entreprise'], 'Bruno Kalala'),
            'Sans le droit, la rémunération d’un collègue doit être refusée — pas rendue, et pas '
            . 'déguisée en « introuvable ».',
        );
    }

    /** Ni par son identifiant : la même règle, quel que soit le chemin. */
    public function testSansLeDroitLIdentifiantDUnCollegueNeDonneRienNonPlus(): void
    {
        $s = $this->semer();

        self::assertFalse($this->estConsultable($s['alice'], $s['entreprise'], (string) $s['bruno']->getId()));
    }

    /** Ni par un fragment de nom, qui est la façon la plus commode de contourner. */
    public function testSansLeDroitUnFragmentDeNomNeDonneRien(): void
    {
        $s = $this->semer();

        self::assertFalse($this->estConsultable($s['alice'], $s['entreprise'], 'Kalala'));
    }

    // ===================== 3. Sa propre rémunération reste accessible =====================

    /**
     * SANS LE DROIT, ALICE S'ATTEINT ELLE-MÊME.
     *
     * C'est l'écart assumé avec l'écran, et il est délibéré : sa propre rémunération n'a
     * jamais demandé de permission. Fermer aussi cette porte serait une régression
     * fonctionnelle, pas un durcissement.
     */
    public function testSansLeDroitElleSAtteintElleMemeParSonNom(): void
    {
        $s = $this->semer();

        self::assertTrue(
            $this->estConsultable($s['alice'], $s['entreprise'], 'Alice Mukendi'),
            'Un agent doit toujours pouvoir consulter SA rétrocommission.',
        );
    }

    /**
     * Et « mes rétrocommissions » — c'est-à-dire l'appel SANS bénéficiaire — reste borné à
     * elle : c'est ce chemin, et non un mot-clé à la première personne, qui sert la demande
     * la plus courante.
     */
    public function testSansLeDroitLAppelSansBeneficiaireResteBorneASoi(): void
    {
        $s = $this->semer();

        $resultat = $this->outil()->execute([], new AiScope($s['entreprise'], $s['alice']));

        self::assertSame(AiToolResult::STATUS_OK, $resultat->status);
        self::assertStringContainsString(
            'propres',
            mb_strtolower((string) ($resultat->data['perimetre'] ?? '')),
            'Le périmètre annoncé doit dire à l’utilisateur qu’il ne voit que les siennes.',
        );
    }

    /** Son propre identifiant fonctionne aussi, sans le droit. */
    public function testSansLeDroitSonProprePeutEtreDonneParIdentifiant(): void
    {
        $s = $this->semer();

        self::assertTrue($this->estConsultable($s['alice'], $s['entreprise'], (string) $s['alice']->getId()));
    }
}
