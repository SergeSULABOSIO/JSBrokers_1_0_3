<?php

namespace App\Tests\Workspace;

use App\Entity\ConditionPartage;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Partenaire;
use App\Entity\Utilisateur;
use App\Service\Partage\ConditionDOffice;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * TOUT INTERMÉDIAIRE REPART DE SON FORMULAIRE AVEC UNE CONDITION DE PARTAGE.
 *
 * ── CE QUE CE TEST FERME ────────────────────────────────────────────────────────────
 * Un partenaire ou un agent fraîchement créé n'avait aucune condition : il n'était donc
 * pas RATTACHABLE. Le partage d'un partenaire fonctionnait quand même — sa « Part % » sert
 * de taux tout en bas de la cascade — mais le geste qui dit « ces affaires-ci relèvent de
 * son accord » n'avait rien à désigner, et il fallait aller écrire une condition dans une
 * autre rubrique avant de pouvoir s'en servir.
 *
 * ── POURQUOI CE TEST PASSE PAR LES ENDPOINTS ────────────────────────────────────────
 * La règle vit sur le FORMULAIRE ({@see \App\Services\FormListenerFactory::conditionDOffice()}),
 * et non au ras de Doctrine : un abonné `onFlush` aurait doté TOUT invité écrit, y compris
 * ceux que le code crée pour lui-même, faisant de la condition une clé étrangère de plus à
 * dénouer avant de pouvoir supprimer un membre de l'espace de travail. Éprouver la règle
 * ailleurs qu'en soumettant vraiment le formulaire ne prouverait donc rien — c'est aussi le
 * chemin que l'assistant emprunte, ce qui rend la parité constatable plutôt que supposée.
 *
 * Les règles verrouillées : la condition naît avec la fiche, elle porte la part du
 * partenaire (et aucun taux pour un agent), elle SUIT la part tant qu'on ne l'a pas
 * retouchée, et elle cesse de la suivre dès qu'on l'a fait.
 */
class ConditionDOfficeTest extends WebTestCase
{
    private const ENT = 'PHPUnit-Office';
    private const OWNER = 'phpunit-office@test.local';

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
        foreach (['condition_partage', 'partenaire', 'invite'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :n",
                ['n' => self::ENT],
            );
        }
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :e', ['e' => self::OWNER]);
        $this->em->clear();
    }

    /** @return array{entreprise: Entreprise, invite: Invite} */
    private function semer(): array
    {
        $owner = (new Utilisateur())->setEmail(self::OWNER)->setNom('PHPUnit')->setVerified(true);
        $owner->setPassword('x');
        $this->em->persist($owner);

        $entreprise = (new Entreprise())->setNom(self::ENT)->setLicence('LIC')->setAdresse('1 rue')
            ->setTelephone('+243000')->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($owner);
        $this->em->persist($entreprise);

        $invite = (new Invite())->setNom('Owner')->setUtilisateur($owner)
            ->setEntreprise($entreprise)->setProprietaire(true);
        $this->em->persist($invite);
        $owner->setConnectedTo($entreprise);

        $this->em->flush();
        $this->client->loginUser($owner);

        return ['entreprise' => $entreprise, 'invite' => $invite];
    }

    /** Soumet le formulaire d'un partenaire — création si `$id` est nul, modification sinon. */
    private function soumettrePartenaire(array $s, array $champs, ?int $id = null): void
    {
        $this->client->request('POST', '/admin/partenaire/api/submit', array_merge([
            'idEntreprise' => $s['entreprise']->getId(),
            'idInvite' => $s['invite']->getId(),
            'adressePhysique' => '1 avenue',
            'telephone' => '+243111',
            'email' => 'contact@sunu.test',
            'numimpot' => 'N',
            'rccm' => 'R',
            'idnat' => 'I',
        ], $champs, $id === null ? [] : ['id' => $id]));

        self::assertResponseIsSuccessful();
        $this->em->clear();
    }

    /** Les conditions de ce partenaire, relues DEPUIS LA BASE. */
    private function conditionsDuPartenaire(int $id): array
    {
        return $this->em->getRepository(ConditionPartage::class)->findBy(['partenaire' => $id]);
    }

    private function partenaireNomme(string $nom): Partenaire
    {
        $partenaire = $this->em->getRepository(Partenaire::class)->findOneBy(['nom' => $nom]);
        self::assertNotNull($partenaire, "Le partenaire « {$nom} » a bien été écrit.");

        return $partenaire;
    }

    /**
     * LE CAS NOMINAL : créer un partenaire à 20 % lui donne sa condition, au même taux.
     *
     * Neutraliser l'écouteur fait tomber ce test sur le compte : zéro condition, donc rien
     * à rattacher — exactement l'état d'avant.
     */
    public function testUnPartenaireNaitAvecSaCondition(): void
    {
        $s = $this->semer();
        $this->soumettrePartenaire($s, ['nom' => 'SUNU Office', 'part' => '20']);

        $partenaire = $this->partenaireNomme('SUNU Office');
        $conditions = $this->conditionsDuPartenaire($partenaire->getId());
        self::assertCount(1, $conditions, 'Une condition, et une seule.');

        $condition = $conditions[0];
        self::assertSame(ConditionDOffice::nomPour('SUNU Office'), $condition->getNom());
        self::assertSame(20.0, $condition->getTaux(), 'Elle porte la part du partenaire.');
        self::assertSame(
            ConditionPartage::CRITERE_PAS_RISQUES_CIBLES,
            $condition->getCritereRisque(),
            'Elle ne cible aucun risque : c est un accord-cadre, le ciblage se pose ensuite.',
        );
        self::assertSame(
            ConditionPartage::FORMULE_NE_SAPPLIQUE_PAS_SEUIL,
            $condition->getFormule(),
            'Aucun seuil : le taux s applique quel que soit le montant.',
        );
    }

    /**
     * ET ELLE EST VISIBLE LÀ OÙ ELLE SERT : dans la collection de la fiche.
     *
     * C'est la promesse entière du lot — « rattachable » ne veut rien dire si la condition
     * existe en base sans paraître à l'écran. La liste de la collection est ce que le
     * dialogue interroge (cf. `listUrl` du widget), et le picker de rattachement puise au
     * même endroit.
     */
    public function testLaConditionParaitDansLaCollectionDeLaFiche(): void
    {
        $s = $this->semer();
        $this->soumettrePartenaire($s, ['nom' => 'SUNU Visible', 'part' => '20']);

        $id = $this->partenaireNomme('SUNU Visible')->getId();
        $this->client->request('GET', "/admin/partenaire/api/{$id}/conditionPartages");

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            'SUNU Visible',
            (string) $this->client->getResponse()->getContent(),
            'La condition d office est offerte au rattachement dès la création de la fiche.',
        );
    }

    /**
     * UN AGENT N'A PAS DE PART. Sa condition naît sans taux, à saisir : inventer un
     * pourcentage reviendrait à lui promettre une rémunération que personne n'a décidée.
     */
    public function testUnAgentNaitAvecSaConditionSansTaux(): void
    {
        $s = $this->semer();

        $this->client->request('POST', '/admin/invite/api/submit', [
            'idEntreprise' => $s['entreprise']->getId(),
            'idInvite' => $s['invite']->getId(),
            'nom' => 'Alice Agent',
            'email' => 'alice.agent@test.local',
        ]);
        self::assertResponseIsSuccessful();
        $this->em->clear();

        $agent = $this->em->getRepository(Invite::class)->findOneBy(['nom' => 'Alice Agent']);
        self::assertNotNull($agent, 'L agent a bien été écrit.');

        $conditions = $this->em->getRepository(ConditionPartage::class)->findBy(['agent' => $agent->getId()]);
        self::assertCount(1, $conditions, 'L agent aussi est rattachable dès sa création.');
        self::assertNull($conditions[0]->getTaux(), 'Le taux reste à saisir.');
        self::assertSame(ConditionDOffice::nomPour('Alice Agent'), $conditions[0]->getNom());
    }

    /**
     * QUI A DÉJÀ UNE CONDITION N'EN REÇOIT PAS UNE SECONDE — sinon chaque enregistrement
     * de la fiche en empilerait une de plus.
     */
    public function testAucunDoublonAuSecondEnregistrement(): void
    {
        $s = $this->semer();
        $this->soumettrePartenaire($s, ['nom' => 'SUNU Deja', 'part' => '20']);

        $id = $this->partenaireNomme('SUNU Deja')->getId();
        self::assertCount(1, $this->conditionsDuPartenaire($id));

        $this->soumettrePartenaire($s, ['nom' => 'SUNU Deja', 'part' => '20'], $id);
        self::assertCount(1, $this->conditionsDuPartenaire($id), 'Toujours une seule.');
    }

    /**
     * LA PART RESTE LA SOURCE DU TAUX. Deux écritures du même nombre finissent toujours par
     * diverger — et c'est le taux de la CONDITION qui paierait, pendant que la fiche
     * annoncerait la part.
     */
    public function testChangerLaPartEntraineLaCondition(): void
    {
        $s = $this->semer();
        $this->soumettrePartenaire($s, ['nom' => 'SUNU Suivi', 'part' => '20']);

        $id = $this->partenaireNomme('SUNU Suivi')->getId();
        $this->soumettrePartenaire($s, ['nom' => 'SUNU Suivi', 'part' => '30'], $id);

        $conditions = $this->conditionsDuPartenaire($id);
        self::assertCount(1, $conditions);
        self::assertSame(30.0, $conditions[0]->getTaux(), 'La condition d office a suivi la part.');
    }

    /**
     * ⚠ MAIS ON NE RÉÉCRIT JAMAIS PAR-DESSUS UNE DÉCISION.
     *
     * Dès que le taux de la condition s'écarte de la part, la condition appartient à
     * l'utilisateur : changer la part ne la touche plus.
     */
    public function testUneConditionRetoucheeNeSuitPlusLaPart(): void
    {
        $s = $this->semer();
        $this->soumettrePartenaire($s, ['nom' => 'SUNU Retouche', 'part' => '20']);

        $id = $this->partenaireNomme('SUNU Retouche')->getId();

        // La décision de l'utilisateur : un taux négocié, différent de la part.
        $condition = $this->conditionsDuPartenaire($id)[0];
        $condition->setTaux(15.0);
        $this->em->flush();
        $this->em->clear();

        $this->soumettrePartenaire($s, ['nom' => 'SUNU Retouche', 'part' => '30'], $id);

        $conditions = $this->conditionsDuPartenaire($id);
        self::assertSame(15.0, $conditions[0]->getTaux(), 'Le taux négocié tient bon.');
    }
}
