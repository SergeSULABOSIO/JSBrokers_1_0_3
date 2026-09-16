<?php

namespace App\Tests\Ai;

use App\Ai\Scope\AiScope;
use App\Ai\Tool\AiToolResult;
use App\Ai\Tool\CatalogueDesRisquesTool;
use App\Ai\Tool\RechercherEntitesTool;
use App\Entity\ConditionPartage;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Partenaire;
use App\Entity\Risque;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * catalogue_des_risques : le catalogue COMPLET du cabinet, matière du conseil.
 *
 * Incident du 2026-09-16 : « quels risques proposer à un client de la construction ? »
 * → « aucun élément », « que couvre l'assurance maladie ? » → repli générique, et un
 * taux annoncé 0 % puis 15 %. Ces tests verrouillent la restitution TOTALE (aucun
 * plafond, aucune troncature), le taux configuré distinct de la moyenne constatée, le
 * cloisonnement par entreprise et le fail-closed.
 */
class CatalogueDesRisquesToolTest extends KernelTestCase
{
    private const OWNER_EMAIL = 'phpunit-catalogue-owner@test.local';
    private const GUEST_EMAIL = 'phpunit-catalogue-guest@test.local';
    private const ENTREPRISE_NOM = 'PHPUnit Catalogue Risques SARL';
    private const ENTREPRISE_B_NOM = 'PHPUnit Catalogue Risques Autre SARL';

    /** Au-delà de toute taille de page : la restitution ne doit pas plafonner. */
    private const NB_RISQUES_ANNEXES = 101;

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

    private function outil(): CatalogueDesRisquesTool
    {
        return static::getContainer()->get(CatalogueDesRisquesTool::class);
    }

    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();
        $noms = [self::ENTREPRISE_NOM, self::ENTREPRISE_B_NOM];
        $types = ['noms' => \Doctrine\DBAL\ArrayParameterType::STRING];
        foreach (['condition_partage', 'risque', 'partenaire', 'invite'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom IN (:noms)",
                ['noms' => $noms],
                $types,
            );
        }
        $conn->executeStatement('DELETE FROM entreprise WHERE nom IN (:noms)', ['noms' => $noms], $types);
        $conn->executeStatement(
            'DELETE FROM utilisateur WHERE email IN (:emails)',
            ['emails' => [self::OWNER_EMAIL, self::GUEST_EMAIL]],
            ['emails' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );
        $this->em()->clear();
    }

    private function entreprise(string $nom, Utilisateur $owner): Entreprise
    {
        $e = (new Entreprise())->setNom($nom)->setLicence('LIC')->setAdresse('1 rue')
            ->setTelephone('+2430000')->setRccm('RCCM')->setIdnat('IDNAT')->setNumimpot('IMP');
        $e->setUtilisateur($owner);
        $this->em()->persist($e);

        return $e;
    }

    private function risque(Entreprise $e, string $code, string $nom, ?string $description, ?float $taux): Risque
    {
        $r = (new Risque())->setCode($code)->setNomComplet($nom)->setDescription($description)
            ->setPourcentageCommissionSpecifiqueHT($taux)
            ->setBranche(Risque::BRANCHE_IARD_OU_NON_VIE)->setImposable(true);
        $r->setEntreprise($e);
        $this->em()->persist($r);

        return $r;
    }

    /** @return array{owner: Invite, guest: Invite, entreprise: Entreprise, descriptionLongue: string} */
    private function semer(): array
    {
        $em = $this->em();

        $ownerUser = (new Utilisateur())->setEmail(self::OWNER_EMAIL)->setNom('Catalogue')
            ->setVerified(true)->setPassword('x');
        $em->persist($ownerUser);
        $e = $this->entreprise(self::ENTREPRISE_NOM, $ownerUser);

        $owner = (new Invite())->setNom('Propriétaire')->setProprietaire(true);
        $owner->setUtilisateur($ownerUser)->setEntreprise($e);
        $em->persist($owner);

        // Invité SANS aucun rôle : il ne lit pas les risques.
        $guestUser = (new Utilisateur())->setEmail(self::GUEST_EMAIL)->setNom('Sans droit')
            ->setVerified(true)->setPassword('x');
        $em->persist($guestUser);
        $guest = (new Invite())->setNom('Invité sans droit')->setProprietaire(false);
        $guest->setUtilisateur($guestUser)->setEntreprise($e);
        $em->persist($guest);

        $this->risque($e, 'TRC', 'Tous risques chantier',
            'Couvre les dommages matériels aux ouvrages en cours de construction et la RC du maître d\'ouvrage.', 12.0);
        $descriptionLongue = 'Frais médicaux, hospitalisation et pharmacie du personnel. '
            . str_repeat('Détail des garanties et exclusions du contrat santé collectif. ', 60);
        $this->risque($e, 'SANTE', 'Maladie groupe', $descriptionLongue, 10.0);
        $caution = $this->risque($e, 'CAUT', 'Caution', null, 15.0);
        for ($i = 1; $i <= self::NB_RISQUES_ANNEXES; ++$i) {
            $this->risque($e, 'ANX' . $i, sprintf('Annexe %03d', $i), 'Risque annexe numéro ' . $i, 5.0);
        }

        $partenaire = (new Partenaire())->setNom('SUNU Courtage')->setPart(20.0);
        $partenaire->setEntreprise($e);
        $em->persist($partenaire);
        $condition = (new ConditionPartage())->setNom('Apport cautions')
            ->setFormule(ConditionPartage::FORMULE_NE_SAPPLIQUE_PAS_SEUIL)->setSeuil(0.0)->setTaux(30.0)
            ->setCritereRisque(ConditionPartage::CRITERE_INCLURE_TOUS_CES_RISQUES)
            ->setPartenaire($partenaire);
        $condition->addProduit($caution);
        $condition->setEntreprise($e);
        $em->persist($condition);

        // Entreprise B : son risque ne doit jamais apparaître dans le catalogue de A.
        $b = $this->entreprise(self::ENTREPRISE_B_NOM, $ownerUser);
        $this->risque($b, 'ETR', 'Risque étranger', 'Ne doit pas fuiter.', 99.0);

        $em->flush();

        return ['owner' => $owner, 'guest' => $guest, 'entreprise' => $e, 'descriptionLongue' => $descriptionLongue];
    }

    /** @return array<string, array<string, mixed>> risques restitués indexés par nom */
    private function parNom(AiToolResult $result): array
    {
        return array_column($result->data['risques'], null, 'nom');
    }

    public function testRestitueLeCatalogueEntierSansPlafondNiTroncature(): void
    {
        ['owner' => $owner, 'entreprise' => $e, 'descriptionLongue' => $longue] = $this->semer();

        $result = $this->outil()->execute(['besoin' => 'entreprise de construction'], new AiScope($e, $owner));

        self::assertSame(AiToolResult::STATUS_OK, $result->status);
        $total = 3 + self::NB_RISQUES_ANNEXES;
        self::assertSame($total, $result->data['nbRisques']);
        self::assertCount($total, $result->data['risques'], 'Aucun risque ne doit manquer au catalogue.');
        self::assertTrue($result->data['catalogueComplet']);
        self::assertSame('entreprise de construction', $result->data['besoin']);

        $risques = $this->parNom($result);
        self::assertArrayNotHasKey('Risque étranger', $risques, 'Le catalogue d\'une autre entreprise ne fuit pas.');
        self::assertSame(trim($longue), $risques['Maladie groupe']['description'], 'Description restituée en entier.');
        self::assertStringContainsString('construction', $risques['Tous risques chantier']['description']);
        self::assertTrue($risques['Caution']['sansDescription']);
    }

    public function testLeTauxConfigureNeSeConfondPlusAvecLaMoyenneConstatee(): void
    {
        ['owner' => $owner, 'entreprise' => $e] = $this->semer();

        $result = $this->outil()->execute([], new AiScope($e, $owner));
        $caution = $this->parNom($result)['Caution'];

        self::assertEquals(15.0, $caution['tauxCommissionConfigure']);
        self::assertArrayHasKey('lectureDesTaux', $result->data, 'La lecture des taux est dite une fois, en tête.');
        // Sans production, UNE phrase dit tous les indicateurs nuls au lieu de les énumérer.
        self::assertIsString($caution['production']);
        self::assertArrayNotHasKey('fiche', $caution, 'Aucune redite de la fiche.');

        // La fiche de lire_fiche (et des objets attachés) porte la même désambiguïsation.
        $risque = $this->em()->getRepository(Risque::class)->findOneBy(['code' => 'CAUT', 'entreprise' => $e]);
        $fiche = static::getContainer()->get(\App\Ai\FicheNormaliseur::class)->ficheEnrichie($risque);
        self::assertEquals(15.0, $fiche['tauxCommissionConfigure']);
        self::assertArrayNotHasKey('tauxCommission', $fiche, 'Le calculé ambigu est renommé.');
        self::assertEquals(0.0, $fiche['tauxCommissionMoyenConstate'], 'Sans police, la moyenne constatée est nulle.');
        self::assertArrayHasKey('nombrePolices', $fiche, 'Les indicateurs calculés accompagnent la fiche.');
        self::assertArrayHasKey('lectureDesTaux', $fiche);
    }

    public function testUnRisqueSansTauxLeDitAuLieuDeLeTaire(): void
    {
        ['owner' => $owner, 'entreprise' => $e] = $this->semer();
        $this->risque($e, 'SANSTAUX', 'Risque sans taux', 'Couverture de test.', null);
        $this->em()->flush();

        $risque = $this->parNom($this->outil()->execute([], new AiScope($e, $owner)))['Risque sans taux'];

        self::assertSame('non configuré sur ce risque', $risque['tauxCommissionConfigure']);
    }

    public function testLesConditionsDePartageDuRisqueSontRestituees(): void
    {
        ['owner' => $owner, 'entreprise' => $e] = $this->semer();

        $risques = $this->parNom($this->outil()->execute([], new AiScope($e, $owner)));

        self::assertCount(1, $risques['Caution']['conditionsDePartage']);
        $condition = $risques['Caution']['conditionsDePartage'][0];
        self::assertSame('Apport cautions', $condition['nom']);
        self::assertEquals(30.0, $condition['taux']);
        self::assertStringContainsString('SUNU Courtage', $condition['beneficiaire']);
        self::assertStringContainsString('INCLUS', $condition['critere']);
        self::assertArrayNotHasKey('conditionsDePartage', $risques['Tous risques chantier']);
    }

    /** « Que couvre l'assurence maladie ? » — faute de frappe comprise : le risque passe en tête. */
    public function testUnRisqueNommeApproximativementPasseEnTete(): void
    {
        ['owner' => $owner, 'entreprise' => $e] = $this->semer();
        $scope = new AiScope($e, $owner);

        $args = $this->outil()->match('Que couvre l\'assurence maladie?', $scope);
        self::assertNotNull($args, 'La question doit être aiguillée vers le catalogue.');

        $result = $this->outil()->execute($args, $scope);

        self::assertSame('Maladie groupe', $result->data['risques'][0]['nom']);
        self::assertContains('Maladie groupe', $result->data['correspondances']);
        self::assertCount(3 + self::NB_RISQUES_ANNEXES, $result->data['risques'], 'Le tri ne retire rien.');
    }

    public function testLaQuestionDeConseilEstAiguilleeVersLeCatalogue(): void
    {
        ['owner' => $owner, 'entreprise' => $e] = $this->semer();

        self::assertNotNull($this->outil()->match(
            'j\'ai un client qui est spécialisé dans la construction quels sont les risques que je peux lui proposer',
            new AiScope($e, $owner),
        ));
    }

    public function testFailClosedSansDroitDeLectureDesRisques(): void
    {
        ['guest' => $guest, 'entreprise' => $e] = $this->semer();

        $result = $this->outil()->execute([], new AiScope($e, $guest));

        self::assertSame(AiToolResult::STATUS_HORS_PERIMETRE, $result->status);
    }

    /** Un mot par interlocuteur, un seul objet : les synonymes désignent la rubrique Risques. */
    public function testLesSynonymesDuRisqueDesignentLaRubriqueRisques(): void
    {
        $lexique = static::getContainer()->get(\App\Ai\Tool\EntiteLexique::class);
        $norm = static fn (string $t): string => \App\Ai\AiText::normalize($t);

        foreach (['quels types d\'assurance avez-vous ?', 'liste les couvertures d\'assurance', 'le produit d\'assurance caution'] as $question) {
            self::assertSame('Risque', $lexique->matchEntite($norm($question)), $question);
        }
        // « couverture » seul reste la période d'une police, jamais un risque.
        self::assertNotSame('Risque', $lexique->matchEntite($norm('la période de couverture de la police')));
    }

    /** La recherche filtrée lit aussi la description quand aucun libellé ne correspond. */
    public function testLaRechercheFiltreeRetombeSurLaDescription(): void
    {
        ['owner' => $owner, 'entreprise' => $e] = $this->semer();

        $result = static::getContainer()->get(RechercherEntitesTool::class)->execute(
            ['entite' => 'Risque', 'filtre' => 'construction'],
            new AiScope($e, $owner),
        );

        self::assertSame(AiToolResult::STATUS_OK, $result->status);
        self::assertSame(1, $result->data['totalItems']);
        self::assertSame('Tous risques chantier', $result->data['items'][0]['libelle']);
        self::assertArrayHasKey('filtreTrouveDansLaDescription', $result->data);
    }
}
