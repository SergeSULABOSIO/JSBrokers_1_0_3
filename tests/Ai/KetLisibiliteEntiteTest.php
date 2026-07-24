<?php

namespace App\Tests\Ai;

use App\Ai\Parcours\ParcoursBuilder;
use App\Ai\Scope\AiScope;
use App\Ai\Tool\LireFicheTool;
use App\Entity\Chargement;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\RevenuPourCourtier;
use App\Entity\TypeRevenu;
use App\Entity\Utilisateur;
use App\Service\Workspace\WorkspaceMutationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Ket doit COMPRENDRE une entité pour ne pas ignorer l'étape qui la concerne.
 * Cas vécu : le revenu du courtier « selon le taux relatif au risque » a été
 * omis du plan. Deux causes, deux garanties vérifiées ici :
 *
 *  1) LISIBILITÉ — la fiche et les valeurs de référentiel exposent les attributs
 *     CALCULÉS (libellés des champs codés, taux en clair), pas seulement des
 *     entiers opaques : un TypeRevenu redevient décidable.
 *  2) UNITÉS — un champ saisi en pourcentage mais stocké en fraction est annoncé
 *     comme tel (inventaire + lecture), pour que Ket écrive « 15 » et non « 0.15 »
 *     (sinon le taux est divisé par 100 en silence).
 */
class KetLisibiliteEntiteTest extends WebTestCase
{
    private const ENT = 'PHPUnit-KetLisib';
    private const OWNER = 'phpunit-ketlisib-owner@test.local';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private WorkspaceMutationService $service;
    private LireFicheTool $lireFiche;
    private ParcoursBuilder $builder;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->service = static::getContainer()->get(WorkspaceMutationService::class);
        $this->lireFiche = static::getContainer()->get(LireFicheTool::class);
        $this->builder = static::getContainer()->get(ParcoursBuilder::class);
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
        foreach (['revenu_pour_courtier', 'cotation', 'type_revenu', 'chargement', 'invite'] as $t) {
            $conn->executeStatement("DELETE x FROM {$t} x JOIN entreprise e ON x.entreprise_id = e.id WHERE e.nom = :n", ['n' => self::ENT]);
        }
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :e', ['e' => self::OWNER]);
        $this->em->clear();
    }

    /** @return array{0:Entreprise,1:Invite,2:TypeRevenu} */
    private function seed(): array
    {
        $u = (new Utilisateur())->setEmail(self::OWNER)->setNom('P')->setVerified(true);
        $u->setPassword('x');
        $this->em->persist($u);
        $ent = (new Entreprise())->setNom(self::ENT)->setLicence('L')->setAdresse('a')->setTelephone('t')
            ->setRccm('r')->setIdnat('i')->setNumimpot('n')->setUtilisateur($u);
        $this->em->persist($ent);
        $inv = (new Invite())->setNom('O')->setUtilisateur($u)->setEntreprise($ent)->setProprietaire(true);
        $this->em->persist($inv);
        $u->setConnectedTo($ent);

        $ch = (new Chargement())->setNom('Prime nette');
        $ch->setEntreprise($ent)->setInvite($inv);
        $this->em->persist($ch);
        $tr = (new TypeRevenu())->setNom('Commission courtier')->setShared(true)->setMultipayments(true)
            ->setRedevable(TypeRevenu::REDEVABLE_ASSUREUR)->setPourcentage(0.15)
            ->setModeCalcul(TypeRevenu::MODE_CALCUL_POURCENTAGE_CHARGEMENT)->setTypeChargement($ch);
        $tr->setEntreprise($ent)->setInvite($inv);
        $this->em->persist($tr);
        $this->em->flush();
        $this->client->loginUser($u);

        return [$ent, $inv, $tr];
    }

    // ─────────────────────────── Lisibilité ───────────────────────────

    /** La fiche d'un TypeRevenu porte les LIBELLÉS des champs codés, pas des entiers nus. */
    public function testLireFicheExposeLesAttributsCalcules(): void
    {
        [$ent, $inv] = $this->seed();

        $res = $this->lireFiche->execute(['entite' => 'TypeRevenu', 'nom' => 'Commission courtier'], new AiScope($ent, $inv));
        $fiche = $res->data['fiche'];

        $this->assertSame('Assureur', $fiche['redevableString'] ?? null, 'Le débiteur est lisible (pas « 1 »).');
        $this->assertSame('Pourcentage sur chargement', $fiche['descriptionModeCalcul'] ?? null);
        $this->assertSame(15.0, $fiche['pourcentageDisplay'] ?? null, 'Le taux est donné en clair (15 %).');
        // La relation typeChargement est présente ET élaguée de ses champs vides.
        $this->assertSame('Prime nette', $fiche['typeChargement']['nom'] ?? null);
        $this->assertArrayNotHasKey('description', $fiche['typeChargement'] ?? [], 'Les champs vides de la relation sont élagués (tokens).');
    }

    /** Les valeurs de référentiel d'une étape portent leurs attributs — donc décidables. */
    public function testParcoursDonneLesTypesRevenuAvecLeursAttributs(): void
    {
        [$ent, $inv] = $this->seed();

        $parcours = $this->builder->construire('proposition', new AiScope($ent, $inv));
        $etape = null;
        foreach ($parcours['etapes'] as $e) {
            if ($e['cle'] === 'revenu-courtier') {
                $etape = $e;
            }
        }

        $this->assertNotNull($etape, 'L’étape revenu du courtier est présente.');
        $ref = $etape['valeursReferentiel'] ?? null;
        $this->assertNotNull($ref, 'Les types de revenu disponibles sont fournis…');
        $valeur = $ref['valeurs'][0];
        $this->assertSame('Commission courtier', $valeur['nom']);
        // …AVEC de quoi choisir « selon le taux relatif au risque » sans deviner.
        $this->assertSame('Assureur', $valeur['attributs']['redevableString'] ?? null);
        $this->assertSame(15.0, $valeur['attributs']['pourcentageDisplay'] ?? null);
        $this->assertSame('Prime nette', $valeur['attributs']['typeChargement']['nom'] ?? null);
    }

    // ─────────────────────────── Unités ───────────────────────────

    /** L'inventaire signale qu'un taux se saisit en %, avec la mise en garde anti-recopie. */
    public function testInventaireSignaleLesChampsPourcentage(): void
    {
        [$ent, $inv] = $this->seed();

        $inventaire = $this->service->inventaireChamps('RevenuPourCourtier', new AiScope($ent, $inv));
        $taux = null;
        foreach (array_merge($inventaire['obligatoires'], $inventaire['facultatifs']) as $champ) {
            if ($champ['champ'] === 'tauxExceptionel') {
                $taux = $champ;
            }
        }

        $this->assertNotNull($taux);
        $this->assertSame('pourcentage', $taux['unite'] ?? null);
        $this->assertStringContainsString('15', $taux['aide'] ?? '', 'La mise en garde donne l’exemple 15 pour 15 %.');
    }

    /** lire_fiche donne, pour un champ %, la valeur à ÉCRIRE à côté de la valeur stockée. */
    public function testLireFicheAnnonceLesUnitesPourcentage(): void
    {
        [$ent, $inv] = $this->seed();

        $res = $this->lireFiche->execute(['entite' => 'TypeRevenu', 'nom' => 'Commission courtier'], new AiScope($ent, $inv));

        $this->assertArrayHasKey('unites', $res->data);
        $this->assertArrayHasKey('pourcentage', $res->data['unites']);
        $message = $res->data['unites']['pourcentage'];
        $this->assertStringContainsString('0.15', $message, 'La valeur stockée est rappelée…');
        $this->assertStringContainsString('15', $message, '…et la valeur à écrire donnée.');
    }

    /**
     * Incident tranche : une fraction stockée « pleine » (1 = 100 %) ne doit JAMAIS
     * être relue comme « 1 % » ni « corrigée ». Le bloc d'unité l'affirme désormais
     * explicitement (100 %, PAS 1 %, ne la corrige pas) — c'est ce qui empêche Ket
     * de réécrire 1 → 0,01 (le facteur 100 qui a corrompu la tranche 132).
     */
    public function testUnitePourcentagePleinDitCentPourCentEtInterditLaCorrection(): void
    {
        [$ent, $inv, $tr] = $this->seed();
        $tr->setPourcentage(1.0); // 100 % en fraction, comme une tranche unique
        $this->em->flush();

        $res = $this->lireFiche->execute(['entite' => 'TypeRevenu', 'nom' => 'Commission courtier'], new AiScope($ent, $inv));
        $message = $res->data['unites']['pourcentage'] ?? '';

        $this->assertStringContainsString('100', $message, 'La fraction 1 est annoncée comme 100 %.');
        $this->assertStringContainsString('PAS', $message, 'Le message écarte explicitement la lecture « 1 % ».');
        $this->assertStringContainsString('corrige', $message, 'Le message interdit de « corriger » une valeur déjà juste.');
    }

    /**
     * Preuve du bug d'origine ET de sa prévention : écrire « 15 » (le pourcentage
     * dicté) stocke bien 0,15 ; recopier « 0.15 » (la valeur lue) stockerait
     * 0,0015 — c'est ce que la mise en garde d'unité empêche.
     */
    public function testEcrireLePourcentageStockeLaBonneFraction(): void
    {
        [$ent, $inv, $tr] = $this->seed();
        $cot = (new Cotation())->setNom('Offre unités')->setDuree(12);
        $cot->setEntreprise($ent)->setInvite($inv);
        $this->em->persist($cot);
        $this->em->flush();
        $cotId = $cot->getId();
        $trId = $tr->getId();

        $op = \App\Ai\Mutation\MutationOperation::fromArray([
            'op' => 'edit', 'entite' => 'Cotation', 'id' => $cotId,
            'collections' => [['collection' => 'revenus', 'elements' => [
                ['op' => 'create', 'champs' => ['nom' => 'Com 15', 'tauxExceptionel' => 15, 'typeRevenu' => $trId]],
            ]]],
        ]);
        $this->service->executer($op, new AiScope($ent, $inv), $inv->getUtilisateur());

        $this->em->clear();
        $rev = $this->em->getRepository(RevenuPourCourtier::class)->findOneBy(['nom' => 'Com 15']);
        $this->assertNotNull($rev);
        $this->assertEqualsWithDelta(0.15, $rev->getTauxExceptionel(), 0.0001, 'Écrire 15 stocke 0,15 (15 %), pas 0,0015.');
    }
}
