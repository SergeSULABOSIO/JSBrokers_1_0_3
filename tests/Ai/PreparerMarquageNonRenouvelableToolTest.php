<?php

namespace App\Tests\Ai;

use App\Ai\Mutation\PlanEnAttente;
use App\Ai\Scope\AiScope;
use App\Ai\Tool\AiToolResult;
use App\Ai\Tool\PreparerMarquageNonRenouvelableTool;
use App\Ai\Tool\PreparerMouvementAvenantTool;
use App\Entity\Assureur;
use App\Entity\Avenant;
use App\Entity\Client;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Piste;
use App\Entity\Risque;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * KET SAIT CONSIGNER « CETTE POLICE N'EST PAS À RENOUVELER ».
 *
 * L'information arrive dans une phrase (« le client a vendu son véhicule ») : l'assistante
 * doit pouvoir la transformer en décision tracée, sans que l'utilisateur ait à ouvrir une
 * fiche. Ces tests protègent ce qui rend la chose sûre plutôt que pratique :
 *
 *  1. le MOTIF ne s'invente pas — sans lui, l'outil REFUSE et fait poser la question ;
 *  2. AUCUNE CONDITION DE DATE — une police qui couvre encore est le cas nominal ;
 *  3. le geste s'accorde avec l'ÉTAT réel (ne pas marquer deux fois, ne pas lever ce qui
 *     n'est pas marqué), sinon le modèle annoncerait une action qui n'écrit rien ;
 *  4. le plan porte L'AVERTISSEMENT DE RECOUVREMENT, pour que Ket ne laisse jamais croire
 *     que le dossier est clos ;
 *  5. un MOUVEMENT sur une police marquée est refusé — on n'efface pas en silence la
 *     décision d'un collègue.
 */
class PreparerMarquageNonRenouvelableToolTest extends WebTestCase
{
    private const ENT   = 'PHPUnit-KetMarquageNR';
    private const OWNER = 'phpunit-ketmarquagenr-owner@test.local';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private PreparerMarquageNonRenouvelableTool $outil;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em    = static::getContainer()->get(EntityManagerInterface::class);
        $this->outil = static::getContainer()->get(PreparerMarquageNonRenouvelableTool::class);
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
        $n = self::ENT;

        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email = :e', ['e' => self::OWNER]);
        // Cycle de FK Avenant ↔ Piste : dissocier les deux liens croisés d'abord.
        $conn->executeStatement('UPDATE avenant a JOIN entreprise e ON a.entreprise_id = e.id SET a.piste_de_renouvellement_id = NULL WHERE e.nom = :n', ['n' => $n]);
        $conn->executeStatement('UPDATE piste p JOIN entreprise e ON p.entreprise_id = e.id SET p.avenant_de_base_id = NULL WHERE e.nom = :n', ['n' => $n]);
        $conn->executeStatement('UPDATE avenant a JOIN entreprise e ON a.entreprise_id = e.id SET a.non_renouvelable_par_id = NULL WHERE e.nom = :n', ['n' => $n]);

        foreach (['avenant', 'cotation', 'piste', 'assureur', 'client', 'risque', 'invite'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :n",
                ['n' => $n]
            );
        }
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :n', ['n' => $n]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :e', ['e' => self::OWNER]);
        $this->em->clear();
    }

    // ------------------------------------------------------------------ fixture

    /**
     * Une police ÉCHUE et une police QUI COUVRE ENCORE (90 jours devant elle) : le second
     * cas est celui qu'aucune garde de date ne doit bloquer.
     *
     * @return array{ent: Entreprise, inv: Invite, echue: int, enCours: int}
     */
    private function seed(): array
    {
        $em = $this->em;

        $user = (new Utilisateur())->setEmail(self::OWNER)->setNom('PHPUnit KetMarquageNR')->setVerified(true);
        $user->setPassword('irrelevant');
        $em->persist($user);

        $ent = (new Entreprise())
            ->setNom(self::ENT)->setLicence('LIC-KNR')->setAdresse('1 rue de Ket')
            ->setTelephone('+243000000013')->setRccm('RCCM-KNR')->setIdnat('IDNAT-KNR')->setNumimpot('IMP-KNR')
            ->setUtilisateur($user);
        $em->persist($ent);
        // Espace de travail COURANT : le moteur de mutation construit les formulaires, dont
        // les listes déroulantes sont scopées par Utilisateur::getConnectedTo().
        $user->setConnectedTo($ent);

        $inv = (new Invite())->setNom('Jean Kabila')->setUtilisateur($user)
            ->setEntreprise($ent)->setProprietaire(true);
        $em->persist($inv);

        $assureur = (new Assureur())->setNom('Assureur KNR')->setEmail('knr@assureur.test')
            ->setNumimpot('IMP-K')->setIdnat('IDNAT-K')->setRccm('RCCM-K');
        $assureur->setEntreprise($ent);
        $em->persist($assureur);

        $risque = (new Risque())->setNomComplet('Risque KNR')->setCode('KNR-RQ')
            ->setDescription('Risque')->setBranche(Risque::BRANCHE_IARD_OU_NON_VIE)->setImposable(true);
        $risque->setEntreprise($ent)->setInvite($inv);
        $em->persist($risque);

        $ids = [];
        foreach ([['ECHUE', '-10 days'], ['EN-COURS', '+90 days']] as [$ref, $delta]) {
            $fin = new \DateTimeImmutable($delta);

            $client = (new Client())->setNom('Client ' . $ref)->setExonere(false);
            $client->setEntreprise($ent);
            $em->persist($client);

            $piste = (new Piste())->setNom('Piste ' . $ref)->setTypeAvenant(Piste::AVENANT_SOUSCRIPTION)
                ->setDescriptionDuRisque('Risque')->setExercice(2026)->setClient($client)->setRisque($risque);
            $piste->setEntreprise($ent)->setInvite($inv);
            $em->persist($piste);

            $cotation = (new Cotation())->setNom('Cotation ' . $ref)->setDuree(365)->setAssureur($assureur);
            $cotation->setPiste($piste);
            $cotation->setEntreprise($ent);
            $em->persist($cotation);

            $avenant = (new Avenant())->setReferencePolice('POL-KNR-' . $ref)->setNumero('0')
                ->setDescription('Avenant ' . $ref)
                ->setStartingAt($fin->modify('-365 days'))->setEndingAt($fin);
            $avenant->setEntreprise($ent)->setInvite($inv);
            $cotation->addAvenant($avenant);
            $em->persist($avenant);

            $ids[$ref] = $avenant;
        }

        $em->flush();
        $this->client->loginUser($user);
        $entId = $ent->getId();
        $invId = $inv->getId();
        $echue = $ids['ECHUE']->getId();
        $enCours = $ids['EN-COURS']->getId();
        $em->clear();

        return [
            'ent'     => $this->em->getRepository(Entreprise::class)->find($entId),
            'inv'     => $this->em->getRepository(Invite::class)->find($invId),
            'echue'   => $echue,
            'enCours' => $enCours,
        ];
    }

    private function scope(array $s): AiScope
    {
        return new AiScope($s['ent'], $s['inv']);
    }

    private function avenant(int $id): Avenant
    {
        return $this->em->getRepository(Avenant::class)->find($id);
    }

    // ------------------------------------------------------------------ tests

    /**
     * LE MOTIF NE S'INVENTE PAS. C'est la seule chose que l'outil ait le droit de faire
     * demander — et il vaut mieux une question de plus qu'une note vide dans un dossier
     * rouvert dans huit mois. Sans plan, aucun bouton : rien ne doit laisser croire qu'une
     * décision a été enregistrée.
     */
    public function testSansMotifLOutilRefuseEtFaitPoserLaQuestion(): void
    {
        $s = $this->seed();

        $resultat = $this->outil->execute(['avenantId' => $s['echue']], $this->scope($s));

        $this->assertSame(AiToolResult::STATUS_OK, $resultat->status);
        $this->assertFalse($resultat->data['pret'] ?? true);
        $this->assertSame('motif', $resultat->data['aDemander'][0]['champ'] ?? null);
        $this->assertNull($resultat->uiAction, 'Sans plan, aucun bouton de validation.');
        $this->assertFalse($this->avenant($s['echue'])->isNonRenouvelable(), 'Rien n’a été écrit.');
    }

    /**
     * AUCUNE CONDITION DE DATE. Le client annonce en mars ce qui se produira en décembre :
     * marquer une police qui couvre encore est le cas NOMINAL, et le plan doit rappeler que
     * la couverture n'est pas interrompue — sans quoi Ket la présenterait comme résiliée.
     */
    public function testUnePoliceQuiCouvreEncorePeutEtreMarquee(): void
    {
        $s = $this->seed();

        $resultat = $this->outil->execute([
            'avenantId' => $s['enCours'],
            'motif'     => 'Le client revend sa flotte en fin d’année.',
        ], $this->scope($s));

        $this->assertTrue($resultat->data['pret'] ?? false, 'Une police en cours doit pouvoir être marquée.');
        $this->assertTrue($resultat->data['couvertureEnCours'] ?? false);
        $this->assertStringContainsString('N’EST PAS interrompue', (string) $resultat->data['consigne']);
        $this->assertSame(PlanEnAttente::ACTION_REVUE, $resultat->uiAction['type'] ?? null);

        // Le plan écrit bien le marquage, son motif ET son auteur — une décision engage
        // celui qui la prend, et l'identifiant vient du scope, jamais du modèle.
        $champs = $resultat->uiAction['plan'][0]['fields'] ?? [];
        $this->assertSame('1', $champs['nonRenouvelable'] ?? null);
        $this->assertSame('Le client revend sa flotte en fin d’année.', $champs['nonRenouvelableMotif'] ?? null);
        $this->assertSame((string) $s['inv']->getId(), $champs['nonRenouvelablePar'] ?? null);

        // DRY-RUN : preparer_operations ne fait que préparer.
        $this->assertFalse($this->avenant($s['enCours'])->isNonRenouvelable());
    }

    /** Le plan ne peut pas s'appliquer à une police d'un autre espace de travail. */
    public function testUnePoliceInconnueEstRefuseeSansPlan(): void
    {
        $s = $this->seed();

        $resultat = $this->outil->execute([
            'avenantId' => 999999999,
            'motif'     => 'Peu importe.',
        ], $this->scope($s));

        $this->assertFalse($resultat->data['pret'] ?? true);
        $this->assertArrayHasKey('bloquant', $resultat->data);
        $this->assertNull($resultat->uiAction);
    }

    /**
     * LE GESTE S'ACCORDE AVEC L'ÉTAT. Sans ce contrôle, « lève le marquage » sur une police
     * jamais marquée produirait un plan qui n'écrit rien — et le modèle annoncerait une
     * action accomplie.
     */
    public function testUnGesteIncoherentAvecLEtatDeLaPoliceEstRefuse(): void
    {
        $s = $this->seed();
        $scope = $this->scope($s);

        $lever = $this->outil->execute(['avenantId' => $s['echue'], 'mode' => 'lever'], $scope);
        $this->assertTrue($lever->data['nonMarquee'] ?? false);
        $this->assertNull($lever->uiAction);

        // On marque pour de bon, puis on retente un marquage.
        $avenant = $this->avenant($s['echue']);
        $avenant->setNonRenouvelable(true);
        $avenant->setNonRenouvelableMotif('Déjà décidé.');
        $this->em->flush();

        $reMarquer = $this->outil->execute([
            'avenantId' => $s['echue'],
            'motif'     => 'Une autre raison.',
        ], $scope);
        $this->assertTrue($reMarquer->data['dejaMarquee'] ?? false);
        $this->assertSame('Déjà décidé.', $reMarquer->data['motifActuel'] ?? null);
        $this->assertNull($reMarquer->uiAction, 'Rien à écrire : aucun bouton.');
    }

    /**
     * LE RETRAIT EST UN GESTE DE PREMIER RANG, et le seul qui n'exige pas de motif : une
     * décision commerciale se révise, et exiger une justification pour revenir en arrière
     * découragerait de le faire.
     */
    public function testLeRetraitNExigeAucunMotifEtPrepareUnPlan(): void
    {
        $s = $this->seed();
        $avenant = $this->avenant($s['echue']);
        $avenant->setNonRenouvelable(true);
        $avenant->setNonRenouvelableMotif('Le client annonçait son départ.');
        $this->em->flush();

        $resultat = $this->outil->execute(['avenantId' => $s['echue'], 'mode' => 'lever'], $this->scope($s));

        $this->assertTrue($resultat->data['pret'] ?? false);
        $this->assertSame('0', $resultat->uiAction['plan'][0]['fields']['nonRenouvelable'] ?? null);
        $this->assertStringContainsString('REVENIR', (string) $resultat->data['consigne']);
        $this->assertStringContainsString('conservé', (string) $resultat->data['consigne']);
    }

    /**
     * UN MOUVEMENT SUR UNE POLICE MARQUÉE EST REFUSÉ. La renouveler en silence effacerait
     * la décision d'un collègue ; l'annuler ou la résilier reposerait sur une lecture
     * périmée du dossier. Le refus NOMME la décision et indique la sortie.
     */
    public function testUnMouvementSurUnePoliceMarqueeEstRefuseEnNommantLaDecision(): void
    {
        $s = $this->seed();
        $avenant = $this->avenant($s['echue']);
        $avenant->setNonRenouvelable(true);
        $avenant->setNonRenouvelableMotif('Le client a vendu le véhicule.');
        $this->em->flush();

        /** @var PreparerMouvementAvenantTool $mouvement */
        $mouvement = static::getContainer()->get(PreparerMouvementAvenantTool::class);
        $resultat = $mouvement->execute([
            'mouvement' => 'renouvellement',
            'avenantId' => $s['echue'],
        ], $this->scope($s));

        $this->assertTrue($resultat->data['nonRenouvelable'] ?? false);
        $this->assertSame('Le client a vendu le véhicule.', $resultat->data['motif'] ?? null);
        $this->assertNull($resultat->uiAction, 'Aucun plan, donc aucun bouton.');
        $this->assertStringContainsString('mode="lever"', (string) $resultat->data['note']);
    }
}
