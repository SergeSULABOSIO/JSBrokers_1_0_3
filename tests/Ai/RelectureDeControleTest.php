<?php

namespace App\Tests\Ai;

use App\Ai\Mutation\MutationOperation;
use App\Ai\Mutation\MutationPlan;
use App\Ai\Scope\AiScope;
use App\Ai\Verification\RelectureDeControle;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Risque;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * RELECTURE DE CONTRÔLE — la base a le dernier mot, pas le journal.
 *
 * Régression fondatrice, 2026-08-13 : Ket annonce « Mission exécutée avec succès »
 * et le fil affiche « 1 opération exécutée avec succès » pour une correction de
 * taux de commission… qui n'est jamais arrivée en base. Rien ne mentait
 * volontairement : le journal d'exécution est produit par le code qui écrit, il
 * dit donc toujours ce que ce code CROIT avoir fait. Aucun maillon du circuit
 * n'allait relire.
 *
 * Ce test porte sur le maillon manquant. Il vérifie les deux erreurs symétriques,
 * car la seconde est la plus dangereuse : un contrôle qui crie au loup serait
 * désactivé au bout d'une semaine, et ne protégerait plus personne.
 */
class RelectureDeControleTest extends KernelTestCase
{
    private const ENT = 'PHPUnit Relecture SARL';
    private const OWNER = 'phpunit-relecture-owner@test.local';

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
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
        foreach (['risque', 'invite'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :n",
                ['n' => self::ENT],
            );
        }
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email = :e', ['e' => self::OWNER]);
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :e', ['e' => self::OWNER]);
        $this->em->clear();
    }

    /**
     * Un risque « Assurance Voyage » dont le taux de commission vaut $taux —
     * `null` reproduisant exactement l'état constaté le 2026-08-13.
     *
     * @return array{scope: AiScope, id: int}
     */
    private function seed(?float $taux): array
    {
        $owner = (new Utilisateur())
            ->setEmail(self::OWNER)->setNom('PHPUnit Relecture')->setVerified(true)->setPassword('x');
        $owner->setPaidTokens(1_000_000);
        $this->em->persist($owner);

        $ent = (new Entreprise())
            ->setNom(self::ENT)->setLicence('LIC-REL')->setAdresse('1 rue du Contrôle')
            ->setTelephone('+243000000011')->setRccm('RCCM-REL')->setIdnat('IDNAT-REL')
            ->setNumimpot('IMP-REL')->setUtilisateur($owner);
        $this->em->persist($ent);
        $owner->setConnectedTo($ent);

        $invite = (new Invite())->setNom('Propriétaire REL');
        $invite->setUtilisateur($owner)->setEntreprise($ent)->setProprietaire(true);
        $this->em->persist($invite);

        $risque = (new Risque())
            ->setCode('AV')
            ->setNomComplet('Assurance Voyage')
            ->setImposable(true)
            ->setBranche(Risque::BRANCHE_IARD_OU_NON_VIE)
            ->setPourcentageCommissionSpecifiqueHT($taux);
        $risque->setEntreprise($ent);
        $this->em->persist($risque);

        $this->em->flush();
        $idRisque = (int) $risque->getId();
        $idEnt = (int) $ent->getId();
        $idInvite = (int) $invite->getId();
        $this->em->clear();

        return [
            'scope' => new AiScope(
                $this->em->getRepository(Entreprise::class)->find($idEnt),
                $this->em->getRepository(Invite::class)->find($idInvite),
                null,
            ),
            'id' => $idRisque,
        ];
    }

    /** Le plan que Ket a fait valider : « corrige le taux de commission à 20 ». */
    private function plan(int $idRisque, float $taux = 20.0): MutationPlan
    {
        return new MutationPlan([
            new MutationOperation(
                op: MutationOperation::OP_EDIT,
                entityShortName: 'Risque',
                targetId: $idRisque,
                fields: ['pourcentageCommissionSpecifiqueHT' => $taux],
            ),
        ]);
    }

    /** Le journal tel que l'exécution le produit : une ligne de tête, statut ok. */
    private function journal(int $idRisque): array
    {
        return [[
            'op'      => 'edit',
            'entite'  => 'Risque',
            'libelle' => 'Risque',
            'cible'   => 'Assurance Voyage',
            'id'      => $idRisque,
            'statut'  => 'ok',
            'niveau'  => 0,
        ]];
    }

    private function service(): RelectureDeControle
    {
        return static::getContainer()->get(RelectureDeControle::class);
    }

    /**
     * L'INCIDENT LUI-MÊME. Le journal dit « fait », la base dit « vide » : c'est
     * la relecture, et elle seule, qui peut trancher — et elle doit NOMMER le
     * champ et les deux valeurs, sans quoi l'utilisateur ne saurait pas quoi
     * reprendre.
     */
    public function testUnChampResteVideEnBaseEstUnEcartNomme(): void
    {
        ['scope' => $scope, 'id' => $id] = $this->seed(null);

        $verdict = $this->service()->verifier($this->plan($id), $this->journal($id), $scope);

        $this->assertFalse($verdict['conforme'], 'Un taux resté vide en base ne peut pas être déclaré conforme.');
        $this->assertCount(1, $verdict['ecarts']);
        $this->assertStringContainsString('pourcentageCommissionSpecifiqueHT', $verdict['ecarts'][0]);
        $this->assertStringContainsString('(vide)', $verdict['ecarts'][0]);
        $this->assertStringContainsString('20', $verdict['ecarts'][0]);
    }

    /**
     * L'AUTRE MOITIÉ DU CONTRAT. Un contrôle qui se déclencherait aussi quand tout
     * va bien serait pire qu'inutile : on cesserait de le lire.
     */
    public function testUneEcritureRealiseeEstDeclareeConforme(): void
    {
        ['scope' => $scope, 'id' => $id] = $this->seed(20.0);

        $verdict = $this->service()->verifier($this->plan($id), $this->journal($id), $scope);

        $this->assertTrue($verdict['conforme'], implode(' | ', $verdict['ecarts']));
        $this->assertSame([], $verdict['ecarts']);
        $this->assertCount(1, $verdict['ecrits']);
        $this->assertTrue($verdict['ecrits'][0]['present']);
    }

    /**
     * Les arrondis monétaires ne sont pas des écarts : le formulaire normalise, et
     * signaler une divergence de FORME noierait les vraies.
     */
    public function testUnEcartDArrondiNEstPasUnEcart(): void
    {
        ['scope' => $scope, 'id' => $id] = $this->seed(20.001);

        $verdict = $this->service()->verifier($this->plan($id), $this->journal($id), $scope);

        $this->assertTrue($verdict['conforme'], implode(' | ', $verdict['ecarts']));
    }

    /**
     * Une SUPPRESSION se vérifie par l'absence : son id journalisé est nul par
     * construction. La compter comme « introuvable en base » ferait échouer toutes
     * les suppressions réussies — le faux positif le plus facile à produire ici.
     */
    public function testUneSuppressionEstUnConstatJamaisUnEcart(): void
    {
        ['scope' => $scope, 'id' => $id] = $this->seed(20.0);

        $plan = new MutationPlan([
            new MutationOperation(op: MutationOperation::OP_DELETE, entityShortName: 'Risque', targetId: $id),
        ]);
        $journal = [[
            'op' => 'delete', 'entite' => 'Risque', 'libelle' => 'Risque',
            'cible' => 'Assurance Voyage', 'id' => null, 'statut' => 'ok', 'niveau' => 0,
        ]];

        $verdict = $this->service()->verifier($plan, $journal, $scope);

        $this->assertTrue($verdict['conforme'], implode(' | ', $verdict['ecarts']));
        $this->assertNotEmpty($verdict['constats']);
    }

    /**
     * LE PIÈGE CENTRAL — l'identity map de Doctrine.
     *
     * Sur le chemin du plan isolé, la relecture suit l'écriture DANS LA MÊME
     * REQUÊTE HTTP. Sans précaution, Doctrine rend l'objet déjà en mémoire : le
     * contrôle relit alors ce que le code croit avoir écrit, et confirme
     * précisément ce qu'il est censé démentir. Ce serait le pire des résultats —
     * un contrôle qui rassure à tort, donc pire que pas de contrôle du tout.
     *
     * On reproduit exactement cet état : l'entité est chargée et modifiée EN
     * MÉMOIRE, sans flush, si bien que la base porte encore l'ancienne valeur. Le
     * verdict doit suivre la BASE, pas la mémoire.
     */
    public function testLaRelectureSuitLaBaseEtNonLObjetDejaEnMemoire(): void
    {
        ['scope' => $scope, 'id' => $id] = $this->seed(null);

        // L'objet entre dans l'identity map, et on lui donne en mémoire la valeur
        // que le plan annonce — sans jamais l'écrire. C'est l'état d'un code qui
        // croit avoir réussi.
        $risque = $this->em->getRepository(Risque::class)->find($id);
        $risque->setPourcentageCommissionSpecifiqueHT(20.0);

        $verdict = $this->service()->verifier($this->plan($id), $this->journal($id), $scope);

        $this->assertFalse(
            $verdict['conforme'],
            'La relecture a cru l’objet en mémoire au lieu de la base : le contrôle ne protège plus de rien.',
        );
        $this->assertStringContainsString('(vide)', $verdict['ecarts'][0]);
    }

    /**
     * Une exécution qui n'a rien journalisé n'est pas une exécution conforme :
     * sans journal il n'y a rien à relire, et le silence ne vaut pas quitus.
     */
    public function testUnJournalVideEstUnEcart(): void
    {
        ['scope' => $scope, 'id' => $id] = $this->seed(20.0);

        $verdict = $this->service()->verifier($this->plan($id), [], $scope);

        $this->assertFalse($verdict['conforme']);
        $this->assertNotEmpty($verdict['ecarts']);
    }
}
