<?php

namespace App\Tests\Ai;

use App\Ai\Action\TypeAction;
use App\Ai\Scope\AiScope;
use App\Ai\Tool\AiToolResult;
use App\Ai\Tool\FermerRubriqueTool;
use App\Ai\Tool\OuvrirRubriqueTool;
use App\Entity\Client;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Piste;
use App\Entity\Portefeuille;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * LES DEUX GESTES DE NAVIGATION DE KET, et les deux incidents du 2026-08-10 qu'ils
 * corrigent.
 *
 * 1. OUVRIR UNE LISTE QUI CONTREDIT LA RÉPONSE. « Donne-moi la liste des pistes pour
 *    Mme Marlette et ouvre cette liste dans le workspace » : le chat énumérait DEUX
 *    pistes, l'écran en affichait CINQ — celles de tout le monde. La rubrique s'ouvrait
 *    sans le moindre filtre, et Ket annonçait pourtant « la rubrique a été ouverte »
 *    comme si l'écran montrait sa réponse. La correction est du CODE, pas une consigne :
 *    l'outil calcule lui-même le critère de liste, à partir du nom dicté et du graphe
 *    des relations.
 *
 * 2. FERMER CE QUE PERSONNE NE SAVAIT FERMER. « Ferme la rubrique Monnaie et Clients
 *    stp » : aucun outil ne fermait quoi que ce soit. Le modèle ouvrait le TABLEAU DE
 *    BORD — qui ne ferme rien — et annonçait la fermeture. L'utilisateur a insisté
 *    (« elles ne sont pas fermées ! ») et a reçu la même affirmation. Une capacité
 *    manquante se comble par du code.
 */
class NavigationRubriqueToolsTest extends KernelTestCase
{
    private const OWNER_EMAIL = 'phpunit-navrubrique-owner@test.local';
    private const ENTREPRISE_NOM = 'PHPUnit NavRubrique SARL';

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

    private function ouvrir(): OuvrirRubriqueTool
    {
        return static::getContainer()->get(OuvrirRubriqueTool::class);
    }

    private function fermer(): FermerRubriqueTool
    {
        return static::getContainer()->get(FermerRubriqueTool::class);
    }

    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email = :e', ['e' => self::OWNER_EMAIL]);
        foreach (['piste', 'client', 'portefeuille'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :n",
                ['n' => self::ENTREPRISE_NOM],
            );
        }
        $conn->executeStatement(
            'DELETE i FROM invite i LEFT JOIN entreprise e ON i.entreprise_id = e.id WHERE e.nom = :n',
            ['n' => self::ENTREPRISE_NOM],
        );
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :n', ['n' => self::ENTREPRISE_NOM]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :e', ['e' => self::OWNER_EMAIL]);
    }

    /**
     * Un cabinet, deux clients, trois pistes : deux pour Mme Marlette, une pour un
     * autre client. C'est exactement la situation de l'incident.
     *
     * @return array{owner: Invite, entreprise: Entreprise, marlette: Client}
     */
    private function seed(): array
    {
        $em = $this->em();

        $utilisateur = (new Utilisateur())
            ->setEmail(self::OWNER_EMAIL)
            ->setNom('PHPUnit NavRubrique')
            ->setPassword('irrelevant');
        $utilisateur->setVerified(true);
        $em->persist($utilisateur);

        $entreprise = (new Entreprise())
            ->setNom(self::ENTREPRISE_NOM)
            ->setLicence('LIC-NAV')
            ->setAdresse('1 rue Nav')
            ->setTelephone('+243000000000')
            ->setRccm('RCCM-NAV')
            ->setIdnat('IDNAT-NAV')
            ->setNumimpot('IMP-NAV')
            ->setUtilisateur($utilisateur);
        $em->persist($entreprise);

        $owner = (new Invite())
            ->setNom('Propriétaire')
            ->setUtilisateur($utilisateur)
            ->setEntreprise($entreprise);
        $owner->setProprietaire(true);
        $em->persist($owner);

        $portefeuille = (new Portefeuille())
            ->setNom('Portefeuille Nav')
            ->setGestionnaire($owner)
            ->setEntreprise($entreprise);
        $em->persist($portefeuille);

        $marlette = (new Client())->setNom('Mme. Marlette SULA EKUMBO')->setExonere(false);
        $marlette->setEntreprise($entreprise);
        $portefeuille->addClient($marlette);
        $em->persist($marlette);

        $autre = (new Client())->setNom('CHEMAF SARL')->setExonere(false);
        $autre->setEntreprise($entreprise);
        $portefeuille->addClient($autre);
        $em->persist($autre);

        foreach ([[$marlette, 'Assurance voyage - Suisse'], [$marlette, 'Assurance Incendie'], [$autre, 'Transport des fonds']] as [$client, $nom]) {
            $em->persist((new Piste())
                ->setNom($nom)
                ->setTypeAvenant(0)
                ->setDescriptionDuRisque('Risque de test navigation')
                ->setExercice(2026)
                ->setClient($client)
                ->setEntreprise($entreprise)
                ->setInvite($owner));
        }

        $em->flush();

        return ['owner' => $owner, 'entreprise' => $entreprise, 'marlette' => $marlette];
    }

    // ------------------------------------------------------- ouvrir_rubrique

    /**
     * L'INCIDENT. Le NOM dicté suffit : le serveur le résout, trouve seul par quelle
     * relation les pistes rejoignent un client, et transmet au navigateur un critère
     * de liste — pas un simple « ouvre la rubrique ».
     */
    public function testLaRubriqueSOuvreFiltreeSurLeNomDicte(): void
    {
        ['owner' => $owner, 'entreprise' => $e, 'marlette' => $marlette] = $this->seed();

        $result = $this->ouvrir()->execute([
            'entite' => 'Piste',
            'lieA'   => ['entite' => 'Client', 'nom' => 'Marlette'],
        ], new AiScope($e, $owner));

        $this->assertSame(AiToolResult::STATUS_OK, $result->status);
        $this->assertSame(TypeAction::OUVRIR_RUBRIQUE->value, $result->uiAction['type']);
        $this->assertSame('Piste', $result->uiAction['entite']);
        // Le chemin de relations est calculé côté serveur : Piste → client (direct).
        $this->assertSame(
            ['client' => ['operator' => '=', 'value' => $marlette->getId(), 'label' => $marlette->getNom()]],
            $result->uiAction['criteres'],
        );
        $this->assertStringContainsString('FILTRÉE', $result->data['note']);
    }

    /**
     * Un filtre RAPIDE de rubrique voyage aussi : la rubrique doit s'ouvrir sur le
     * même chip que celui qui a produit la réponse écrite.
     */
    public function testUnFiltreRapideDeRubriqueVoyageAvecLOuverture(): void
    {
        ['owner' => $owner, 'entreprise' => $e] = $this->seed();

        $result = $this->ouvrir()->execute([
            'entite'         => 'Piste',
            'transformation' => 'en_cours',
        ], new AiScope($e, $owner));

        $this->assertSame(AiToolResult::STATUS_OK, $result->status);
        $this->assertArrayHasKey(\App\Services\Search\PisteTransformationScope::CRITERION_KEY, $result->uiAction['criteres']);
        $this->assertNotEmpty($result->data['filtres']);
    }

    /**
     * SANS FILTRE, ON LE DIT. La note doit interdire d'annoncer que l'écran montre la
     * réponse écrite : c'est précisément le mensonge de l'incident.
     */
    public function testUneRubriqueSansFiltreLAnnonce(): void
    {
        ['owner' => $owner, 'entreprise' => $e] = $this->seed();

        $result = $this->ouvrir()->execute(['entite' => 'Piste'], new AiScope($e, $owner));

        $this->assertSame(AiToolResult::STATUS_OK, $result->status);
        $this->assertArrayNotHasKey('criteres', $result->uiAction);
        $this->assertStringContainsString('ENTIÈRE', $result->data['note']);
    }

    /**
     * ON NE DEVINE JAMAIS, et surtout on n'ouvre pas la liste entière en lot de
     * consolation : ce serait reproduire l'incident sous couvert de serviabilité.
     */
    public function testUnRattachementIntrouvableNOuvreRien(): void
    {
        ['owner' => $owner, 'entreprise' => $e] = $this->seed();

        $result = $this->ouvrir()->execute([
            'entite' => 'Piste',
            'lieA'   => ['entite' => 'Client', 'nom' => 'Zzzz Inexistant'],
        ], new AiScope($e, $owner));

        $this->assertSame(AiToolResult::STATUS_OK, $result->status);
        $this->assertNull($result->uiAction, 'Aucune rubrique ne s’ouvre sur un rattachement non résolu.');
        $this->assertFalse($result->data['pret']);
        $this->assertSame('introuvable', $result->data['aDemander'][0]['probleme']);
    }

    // ------------------------------------------------------- fermer_rubrique

    /** Plusieurs rubriques nommées dans la même phrase se ferment en UN seul appel. */
    public function testPlusieursRubriquesSeFermentEnUnAppel(): void
    {
        ['owner' => $owner, 'entreprise' => $e] = $this->seed();

        $result = $this->fermer()->execute(
            ['entites' => ['Monnaie', 'Client']],
            new AiScope($e, $owner),
        );

        $this->assertSame(AiToolResult::STATUS_OK, $result->status);
        $this->assertSame(TypeAction::FERMER_RUBRIQUE->value, $result->uiAction['type']);
        $this->assertEqualsCanonicalizing(['Monnaie', 'Client'], $result->uiAction['entites']);
    }

    /** « Toutes » absorbe le reste : deux ordres qui se chevauchent n'en font qu'un. */
    public function testToutesAbsorbeLesAutres(): void
    {
        ['owner' => $owner, 'entreprise' => $e] = $this->seed();

        $result = $this->fermer()->execute(
            ['entites' => ['Client', 'Toutes']],
            new AiScope($e, $owner),
        );

        $this->assertSame(['Toutes'], $result->uiAction['entites']);
    }

    /** Une rubrique inconnue ne produit pas d'action fantôme. */
    public function testUneRubriqueInconnueNeProduitAucuneAction(): void
    {
        ['owner' => $owner, 'entreprise' => $e] = $this->seed();

        $result = $this->fermer()->execute(
            ['entites' => ['NExistePas']],
            new AiScope($e, $owner),
        );

        $this->assertSame(AiToolResult::STATUS_INTROUVABLE, $result->status);
        $this->assertNull($result->uiAction);
    }

    /**
     * La note doit interdire la formule qui a fait échouer l'incident : fermer un
     * onglet n'en ouvre aucun autre, et rien n'autorise à dire « vous êtes revenu sur
     * le tableau de bord ».
     */
    public function testLaNoteInterditDAnnoncerUnRetourAuTableauDeBord(): void
    {
        ['owner' => $owner, 'entreprise' => $e] = $this->seed();

        $result = $this->fermer()->execute(['entites' => ['Client']], new AiScope($e, $owner));

        $this->assertStringContainsString('n’en ouvre aucun autre', $result->data['note']);
    }
}
