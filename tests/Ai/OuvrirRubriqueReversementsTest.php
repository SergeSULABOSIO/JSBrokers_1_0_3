<?php

namespace App\Tests\Ai;

use App\Ai\Scope\AiScope;
use App\Ai\Tool\AiToolResult;
use App\Ai\Tool\OuvrirRubriqueTool;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Utilisateur;
use App\Services\Search\ReversementScope;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * KET OUVRE LA RUBRIQUE DES REVERSEMENTS, FILTRÉE — comme l'écran.
 *
 * Le volet dédié a disparu : le bouton « Versements enregistrés » du rapport de production
 * ouvre la rubrique filtrée sur son agent. Si l'assistant ne savait pas en faire autant,
 * « ouvre-moi les versements d'Alice » ouvrirait la liste ENTIÈRE pendant que le chat
 * annoncerait ceux d'une seule personne — la contradiction que `OuvrirRubriqueTool` a été
 * écrit pour éliminer.
 *
 * Trois propriétés :
 *
 *  1. LE MÊME CRITÈRE QUE L'ÉCRAN. Le chip, le bouton du rapport et l'assistant posent
 *     rigoureusement le même filtre — sinon deux surfaces montrent deux listes.
 *  2. UN AGENT SE DÉSIGNE PAR SON NOM, et un nom inconnu pose une QUESTION plutôt que
 *     d'ouvrir tout.
 *  3. LE FILTRE EST ANNONCÉ. L'assistant doit dire ce que l'écran montre.
 */
class OuvrirRubriqueReversementsTest extends KernelTestCase
{
    private const OWNER_EMAIL = 'phpunit-ouvrir-reversements@test.local';
    private const ENT = 'PHPUnit Ouvrir Reversements SARL';

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

    private function outil(): OuvrirRubriqueTool
    {
        return static::getContainer()->get(OuvrirRubriqueTool::class);
    }

    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email = :e', ['e' => self::OWNER_EMAIL]);
        $conn->executeStatement(
            'DELETE t FROM invite t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :nom',
            ['nom' => self::ENT],
        );
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :nom', ['nom' => self::ENT]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :email', ['email' => self::OWNER_EMAIL]);
        $this->em()->clear();
    }

    /** @return array{scope: AiScope, agent: Invite, homonyme: Invite} */
    private function semer(): array
    {
        $em = $this->em();

        $owner = (new Utilisateur())->setEmail(self::OWNER_EMAIL)->setNom('Ouvrir')->setVerified(true)->setPassword('x');
        $em->persist($owner);

        $ent = (new Entreprise())->setNom(self::ENT)->setLicence('LIC')->setAdresse('1 rue')
            ->setTelephone('+2430000')->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($owner);
        $em->persist($ent);
        $owner->setConnectedTo($ent);

        $gestionnaire = (new Invite())->setNom('Gestionnaire')->setProprietaire(true);
        $gestionnaire->setUtilisateur($owner)->setEntreprise($ent);
        $em->persist($gestionnaire);

        $agent = (new Invite())->setNom('Alice')->setProprietaire(false);
        $agent->setEntreprise($ent);
        $em->persist($agent);

        // Un nom qui CONTIENT le précédent : « Alice » ne doit pas être écrasé par
        // « Alice Dupont », même règle que la résolution des bénéficiaires ailleurs.
        $homonyme = (new Invite())->setNom('Alice Dupont')->setProprietaire(false);
        $homonyme->setEntreprise($ent);
        $em->persist($homonyme);

        $em->flush();

        return ['scope' => new AiScope($ent, $gestionnaire), 'agent' => $agent, 'homonyme' => $homonyme];
    }

    // ===================== 1. Le même critère que l'écran =====================

    /**
     * LE CRITÈRE POSÉ EST CELUI DU CHIP ET DU BOUTON DU RAPPORT — au caractère près.
     *
     * C'est ce qui rend la parité structurelle plutôt que promise : les trois surfaces
     * appellent la MÊME fabrique de critère.
     */
    public function testLeCritereDeBeneficiaireEstCeluiDeLEcran(): void
    {
        $s = $this->semer();

        $resultat = $this->outil()->execute([
            'entite' => 'ReversementRetroAgent',
            'beneficiaire' => 'Alice',
        ], $s['scope']);

        self::assertSame(AiToolResult::STATUS_OK, $resultat->status);
        $criteres = $resultat->uiAction['criteres'] ?? [];

        self::assertSame(
            ReversementScope::critereBeneficiaire((int) $s['agent']->getId(), 'Alice'),
            $criteres,
            'Le critère de l’assistant doit être exactement celui du chip-sélecteur.',
        );
        self::assertStringContainsString('Alice', implode(' ', $resultat->data['filtres'] ?? []));
    }

    /** Les trois chips de la rubrique, par le même vocabulaire que l'écran. */
    public function testLesTroisChipsSontDisponiblesEtAnnonces(): void
    {
        $s = $this->semer();

        $cas = [
            ['justificatif', ReversementScope::SANS_PIECE, ReversementScope::CLE_JUSTIFICATIF],
            ['periode', ReversementScope::CE_MOIS, ReversementScope::CLE_PERIODE],
            ['virement', ReversementScope::GROUPE, ReversementScope::CLE_VIREMENT],
        ];

        foreach ($cas as [$parametre, $valeur, $cle]) {
            $resultat = $this->outil()->execute([
                'entite' => 'ReversementRetroAgent',
                $parametre => $valeur,
            ], $s['scope']);

            self::assertSame(
                ReversementScope::critereRecherche(ReversementScope::ENTITE, $cle, $valeur),
                $resultat->uiAction['criteres'] ?? [],
                sprintf('Le paramètre « %s » doit poser le critère du chip.', $parametre),
            );
            // ANNONCER CE QUE L'ÉCRAN MONTRE : une liste filtrée sans un mot se lit comme
            // une liste complète, et l'assistant passerait pour avoir tout montré.
            self::assertContains(
                ReversementScope::libelle($cle, $valeur),
                $resultat->data['filtres'] ?? [],
                sprintf('Le filtre « %s » doit être annoncé.', $parametre),
            );
        }
    }

    /** Les filtres se cumulent, comme les chips de la barre. */
    public function testLesFiltresSeCumulent(): void
    {
        $s = $this->semer();

        $resultat = $this->outil()->execute([
            'entite' => 'ReversementRetroAgent',
            'beneficiaire' => 'Alice',
            'justificatif' => ReversementScope::SANS_PIECE,
        ], $s['scope']);

        $criteres = $resultat->uiAction['criteres'] ?? [];
        self::assertArrayHasKey(ReversementScope::CHAMP_BENEFICIAIRE, $criteres);
        self::assertArrayHasKey(ReversementScope::CLE_JUSTIFICATIF, $criteres);
    }

    // ===================== 2. La résolution du nom =====================

    /** Le nom EXACT l'emporte sur le partiel : « Alice » n'est pas « Alice Dupont ». */
    public function testLeNomExactLEmporteSurLePartiel(): void
    {
        $s = $this->semer();

        $resultat = $this->outil()->execute([
            'entite' => 'ReversementRetroAgent',
            'beneficiaire' => 'Alice',
        ], $s['scope']);

        self::assertSame(
            $s['agent']->getId(),
            $resultat->uiAction['criteres'][ReversementScope::CHAMP_BENEFICIAIRE]['value'],
        );
    }

    /**
     * UN AGENT INCONNU N'OUVRE PAS LA LISTE ENTIÈRE.
     *
     * Ouvrir tout en lot de consolation reviendrait à annoncer les versements d'une
     * personne et à montrer ceux de tout le monde — exactement la contradiction que le
     * filtrage à l'ouverture corrige.
     */
    public function testUnAgentInconnuNOuvrePasLaListeEntiere(): void
    {
        $s = $this->semer();

        $resultat = $this->outil()->execute([
            'entite' => 'ReversementRetroAgent',
            'beneficiaire' => 'Personne Inconnue',
        ], $s['scope']);

        self::assertSame(AiToolResult::STATUS_INTROUVABLE, $resultat->status);
        self::assertNull($resultat->uiAction, 'Aucune rubrique ne doit s’ouvrir.');
    }

    /** Sur une AUTRE rubrique, ces paramètres sont sans effet — ils lui sont étrangers. */
    public function testLesFiltresDeReversementNeDebordentPasSurUneAutreRubrique(): void
    {
        $s = $this->semer();

        $resultat = $this->outil()->execute([
            'entite' => 'Avenant',
            'justificatif' => ReversementScope::SANS_PIECE,
            'beneficiaire' => 'Alice',
        ], $s['scope']);

        $criteres = $resultat->uiAction['criteres'] ?? [];
        self::assertArrayNotHasKey(ReversementScope::CLE_JUSTIFICATIF, $criteres);
        self::assertArrayNotHasKey(ReversementScope::CHAMP_BENEFICIAIRE, $criteres);
    }
}
